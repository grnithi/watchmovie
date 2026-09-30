/**
 * What To Watch - front-end
 * State (mode / type / region / range) is remembered in localStorage.
 */
(function () {
    'use strict';

    const LANG_ORDER = ['Tamil', 'Telugu', 'Hindi', 'Malayalam', 'Kannada', 'English'];

    const store = {
        get(k, d) { try { return localStorage.getItem(k) || d; } catch (e) { return d; } },
        set(k, v) { try { localStorage.setItem(k, v); } catch (e) { /* ignore */ } },
    };

    const SLUGS = { US: 'usa', IN: 'india' };
    const fromSlug = { usa: 'US', india: 'IN' };
    const pick = (v, allowed, fallback) => (allowed.includes(v) ? v : fallback);

    // URL wins over remembered choices: /india?mode=ott&type=tv&range=week
    const urlParams = new URLSearchParams(location.search);
    const pathSlug = location.pathname.replace(/\/+$/, '').split('/').pop().toLowerCase();

    const state = {
        mode: pick(urlParams.get('mode') || store.get('w_mode', 'ott'), ['ott', 'theatrical'], 'ott'),
        type: pick(urlParams.get('type') || store.get('w_type', 'movie'), ['movie', 'tv'], 'movie'),
        region: fromSlug[pathSlug] || pick(store.get('w_region', 'US'), ['US', 'IN'], 'US'),
        range: pick(urlParams.get('range') || store.get('w_range', 'weekend'), ['weekend', 'week', 'upcoming', 'recent'], 'weekend'),
        language: 'all',
        platform: 'all',
        sort: 'popular',
        query: '',
        items: [],
        windowLabel: '',
        cached: false,
        seq: 0,
    };

    const $ = (id) => document.getElementById(id);
    const el = {
        grid: $('grid'), status: $('statusBox'), filters: $('filters'),
        langChips: $('langChips'), platformChips: $('platformChips'), platformLine: $('platformLine'),
        search: $('searchInput'), sort: $('sortSelect'), count: $('countText'),
        heroTitle: $('heroTitle'), heroSub: $('heroSub'), refresh: $('refreshBtn'),
        footer: $('footerText'), modal: $('modal'),
    };

    const esc = (s) => String(s == null ? '' : s).replace(/[&<>"']/g, (c) => (
        { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));

    /* ---------- dates ---------- */
    function parseDate(s) {
        if (!s) return null;
        const [y, m, d] = s.split('-').map(Number);
        return new Date(y, m - 1, d);
    }
    function fmtDate(s) {
        const d = parseDate(s);
        if (!d) return 'TBA';
        const today = new Date(); today.setHours(0, 0, 0, 0);
        const diff = Math.round((d - today) / 86400000);
        if (diff === 0) return 'Today';
        if (diff === 1) return 'Tomorrow';
        if (diff === -1) return 'Yesterday';
        const opts = { weekday: 'short', month: 'short', day: 'numeric' };
        if (d.getFullYear() !== today.getFullYear()) opts.year = 'numeric';
        return d.toLocaleDateString('en-US', opts);
    }

    /* ---------- labels ---------- */
    const todayStr = (() => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`; })();
    const isReleased = (it) => !!it.release_date && it.release_date <= todayStr;
    function dateBadge(it) {
        if (it.type === 'tv') {
            if (it.is_new) return { cls: 'new', text: 'New series' };
            return { cls: 'live', text: it.seasons ? `Season ${it.seasons}` : 'Streaming' };
        }
        const when = fmtDate(it.release_date);
        if (state.mode === 'theatrical') {
            return isReleased(it) ? { cls: 'live', text: 'In theatres' } : { cls: 'soon', text: `Opens ${when}` };
        }
        return isReleased(it) ? { cls: 'live', text: `Out ${when}` } : { cls: 'soon', text: `Streams ${when}` };
    }

    /* ---------- data ---------- */
    function syncUrl(push) {
        const base = document.baseURI.split(/[?#]/)[0].replace(/[^/]*$/, '');
        const url = `${base}${SLUGS[state.region]}?mode=${state.mode}&type=${state.type}&range=${state.range}`;
        if (url === location.href) return;
        history[push ? 'pushState' : 'replaceState'](null, '', url);
    }

    async function load(force, push) {
        syncUrl(!!push);
        const seq = ++state.seq;
        showSkeleton();
        el.refresh.classList.add('spin');
        const qs = new URLSearchParams({ mode: state.mode, type: state.type, region: state.region, range: state.range });
        if (force) qs.set('refresh', '1');
        try {
            const res = await fetch('api/feed.php?' + qs);
            const data = await res.json();
            if (seq !== state.seq) return; // a newer request superseded this one
            if (!data.success) throw new Error(data.error || 'Request failed');
            state.items = data.releases || [];
            state.windowLabel = data.window ? data.window.label : '';
            state.cached = !!data.cached;
            state.language = 'all';
            state.platform = 'all';
            render();
        } catch (err) {
            if (seq !== state.seq) return;
            state.items = [];
            el.filters.hidden = true;
            el.grid.innerHTML = '';
            showMessage('😕', 'Couldn’t load releases', esc(err.message), true);
        } finally {
            if (seq === state.seq) el.refresh.classList.remove('spin');
        }
    }

    /* ---------- UI chrome ---------- */
    function syncControls() {
        const set = (id, attr, val) => $(id).querySelectorAll('.seg-btn').forEach((b) => {
            const on = b.dataset[attr] === val;
            b.classList.toggle('active', on);
            b.setAttribute('aria-selected', on);
            b.setAttribute('aria-checked', on);
        });
        set('modeSeg', 'mode', state.mode);
        set('typeSeg', 'type', state.type);
        set('rangeSeg', 'range', state.range);
        set('regionSeg', 'region', state.region);

        // TV shows have no theatrical run.
        const tvBtn = $('typeSeg').querySelector('[data-type="tv"]');
        tvBtn.disabled = state.mode === 'theatrical';
        tvBtn.title = tvBtn.disabled ? 'TV shows are only available on OTT' : '';

        const ott = state.mode === 'ott';
        const what = state.type === 'tv' ? 'TV shows' : 'movies';
        el.heroTitle.textContent = ott ? (state.type === 'tv' ? 'TV shows on OTT' : 'New on OTT') : 'In theatres';
        const where = state.region === 'IN' ? 'India' : 'the USA';
        el.heroSub.textContent = `${ott ? 'Streaming' : 'Theatrical'} ${what} in ${where}` + (state.windowLabel ? ` · ${state.windowLabel}` : '');
        document.title = `${el.heroTitle.textContent} · What To Watch`;
        el.platformLine.hidden = !ott;
    }

    function chip(label, value, active, count, extra) {
        return `<button class="chip${active ? ' active' : ''}" data-value="${esc(value)}">${extra || ''}${esc(label)}${count != null ? `<em>${count}</em>` : ''}</button>`;
    }

    function renderFilters() {
        // Language chips (counts from the whole result set)
        const langCount = {};
        state.items.forEach((i) => { langCount[i.language] = (langCount[i.language] || 0) + 1; });
        let html = chip('All', 'all', state.language === 'all', state.items.length);
        LANG_ORDER.forEach((l) => { if (langCount[l]) html += chip(l, l, state.language === l, langCount[l]); });
        el.langChips.innerHTML = html;

        // Platform chips (OTT only), respecting the language filter so counts stay meaningful
        if (state.mode === 'ott') {
            const inLang = state.items.filter((i) => state.language === 'all' || i.language === state.language);
            const plat = {};
            inLang.forEach((i) => {
                i.providers.filter((p) => p.kind !== 'rent').forEach((p) => {
                    plat[p.name] = plat[p.name] || { n: 0, logo: p.logo };
                    plat[p.name].n++;
                });
            });
            const names = Object.keys(plat).sort((a, b) => plat[b].n - plat[a].n);
            if (state.platform !== 'all' && !plat[state.platform]) state.platform = 'all';
            let p = chip('All platforms', 'all', state.platform === 'all', inLang.length);
            names.forEach((n) => {
                const logo = plat[n].logo ? `<img src="${esc(plat[n].logo)}" alt="" loading="lazy">` : '';
                p += chip(n, n, state.platform === n, plat[n].n, logo);
            });
            el.platformChips.innerHTML = p;
        }
    }

    function visibleItems() {
        const q = state.query.trim().toLowerCase();
        const list = state.items.filter((i) => {
            if (state.language !== 'all' && i.language !== state.language) return false;
            if (state.mode === 'ott' && state.platform !== 'all' &&
                !i.providers.some((p) => p.kind !== 'rent' && p.name === state.platform)) return false;
            if (q && !(i.title + ' ' + i.original_title).toLowerCase().includes(q)) return false;
            return true;
        });
        const by = {
            popular: (a, b) => (b.is_new - a.is_new) || (b.popularity - a.popularity),
            date: (a, b) => (b.release_date || '').localeCompare(a.release_date || ''),
            rating: (a, b) => (b.rating || 0) - (a.rating || 0),
        }[state.sort];
        return list.sort(by);
    }

    /* ---------- rendering ---------- */
    function card(it, idx) {
        const b = dateBadge(it);
        const plats = state.mode === 'ott'
            ? it.providers.filter((p) => p.kind !== 'rent').slice(0, 4)
            : [];
        const platHtml = plats.length
            ? `<div class="plats">${plats.map((p) => p.logo
                ? `<img src="${esc(p.logo)}" alt="${esc(p.name)}" title="${esc(p.name)}" loading="lazy">`
                : `<span class="plat-txt">${esc(p.name)}</span>`).join('')}</div>`
            : (state.mode === 'ott' ? '<div class="plats"><span class="plat-txt">Platform TBA</span></div>' : '');
        const rating = it.rating ? `<span class="rating">★ ${it.rating.toFixed(1)}</span>` : '';
        return `
        <button class="card" data-idx="${idx}" aria-label="${esc(it.title)}">
            <div class="poster">
                <img src="${esc(it.poster)}" alt="" loading="lazy">
                <span class="badge ${b.cls}">${esc(b.text)}</span>
                ${rating}
                ${platHtml}
            </div>
            <div class="card-body">
                <h3>${esc(it.title)}</h3>
                <p>${esc(it.language)}${it.genres[0] ? ' · ' + esc(it.genres[0]) : ''}</p>
            </div>
        </button>`;
    }

    let shown = [];
    function render() {
        syncControls();
        el.status.hidden = true;
        el.filters.hidden = state.items.length === 0;
        if (state.items.length === 0) {
            el.grid.innerHTML = '';
            const where = state.mode === 'ott' ? 'on OTT' : 'in theatres';
            showMessage('🍿', 'Nothing here yet', `No ${state.type === 'tv' ? 'TV shows' : 'movies'} found ${where} for this period. Try a wider range.`);
            return;
        }
        renderFilters();
        shown = visibleItems();
        el.count.textContent = `${shown.length} ${shown.length === 1 ? 'title' : 'titles'}`;
        if (!shown.length) {
            el.grid.innerHTML = '';
            showMessage('🔎', 'No matches', 'Nothing matches those filters.');
            return;
        }
        el.status.hidden = true;
        el.grid.innerHTML = shown.map(card).join('');
    }

    function showSkeleton() {
        el.status.hidden = true;
        syncControls();
        el.grid.innerHTML = Array.from({ length: 12 }, () =>
            '<div class="card skeleton"><div class="poster"></div><div class="card-body"><h3>&nbsp;</h3><p>&nbsp;</p></div></div>').join('');
    }

    function showMessage(icon, title, text, retry) {
        el.status.hidden = false;
        el.status.innerHTML = `<div class="state-icon">${icon}</div><h3>${title}</h3><p>${text}</p>` +
            (retry ? '<button class="btn" id="retryBtn">Try again</button>' : '');
        const r = $('retryBtn');
        if (r) r.addEventListener('click', () => load(true));
    }

    /* ---------- platform links (straight to the streaming service) ---------- */
    const PLATFORM_SEARCH = {
        'netflix': (q) => `https://www.netflix.com/search?q=${q}`,
        'amazon prime video': (q) => `https://www.primevideo.com/search/?phrase=${q}`,
        'jiohotstar': (q) => `https://www.hotstar.com/in/search?q=${q}`,
        'disney+': (q) => `https://www.disneyplus.com/search?q=${q}`,
        'zee5': (q) => `https://www.zee5.com/search?q=${q}`,
        'sony liv': (q) => `https://www.sonyliv.com/search?searchTerm=${q}`,
        'sun nxt': (q) => `https://www.sunnxt.com/search?q=${q}`,
        'hulu': (q) => `https://www.hulu.com/search?q=${q}`,
        'hbo max': (q) => `https://play.max.com/search?q=${q}`,
        'apple tv': (q) => `https://tv.apple.com/search?term=${q}`,
        'peacock': (q) => `https://www.peacocktv.com/search?q=${q}`,
        'paramount+': (q) => `https://www.paramountplus.com/search/?query=${q}`,
        'mubi': (q) => `https://mubi.com/en/search/films?query=${q}`,
        'youtube': (q) => `https://www.youtube.com/results?search_query=${q}`,
    };
    function platformUrl(name, title) {
        const q = encodeURIComponent(title);
        const fn = PLATFORM_SEARCH[String(name).toLowerCase()];
        return fn ? fn(q) : `https://www.google.com/search?q=${encodeURIComponent(`watch ${title} on ${name}`)}`;
    }

    /* ---------- modal ---------- */
    function openModal(it) {
        const b = dateBadge(it);
        $('mHero').innerHTML = it.backdrop
            ? `<img src="${esc(it.backdrop)}" alt="">`
            : `<img src="${esc(it.poster)}" alt="" class="contain">`;
        $('mTitle').textContent = it.title;
        const meta = [
            `<span class="badge inline ${b.cls}">${esc(b.text)}</span>`,
            it.release_date ? `<span>${esc(fmtDate(it.release_date))}</span>` : '',
            `<span>${esc(it.language)}</span>`,
            it.runtime ? `<span>${Math.floor(it.runtime / 60) ? Math.floor(it.runtime / 60) + 'h ' : ''}${it.runtime % 60}m</span>` : '',
            it.seasons ? `<span>${it.seasons} season${it.seasons > 1 ? 's' : ''}</span>` : '',
            it.certification ? `<span class="cert">${esc(it.certification)}</span>` : '',
            it.rating ? `<span class="rating">★ ${it.rating.toFixed(1)}</span>` : '',
        ].filter(Boolean);
        $('mMeta').innerHTML = meta.join('');
        $('mTagline').textContent = it.tagline || '';
        $('mTagline').hidden = !it.tagline;
        $('mOverview').textContent = it.overview || 'No synopsis available yet.';

        let prov = '';
        if (state.mode === 'ott') {
            const groups = [['stream', 'Stream'], ['free', 'Free'], ['rent', 'Rent / Buy']];
            prov = groups.map(([k, label]) => {
                const list = it.providers.filter((p) => p.kind === k);
                if (!list.length) return '';
                return `<div class="prov-group"><h4>${label}</h4><div class="prov-list">${list.map((p) =>
                    `<a class="prov" href="${esc(platformUrl(p.name, it.title))}" target="_blank" rel="noopener">${p.logo ? `<img src="${esc(p.logo)}" alt="">` : ''}${esc(p.name)}</a>`).join('')}</div></div>`;
            }).join('');
            if (!prov) prov = '<div class="prov-group"><h4>Platform</h4><p class="muted">Not announced yet.</p></div>';
        }
        $('mProviders').innerHTML = prov;

        const actions = [];
        const main = it.providers.find((p) => p.kind !== 'rent') || it.providers[0];
        if (state.mode === 'ott' && main) {
            actions.push(`<a class="btn primary" href="${esc(platformUrl(main.name, it.title))}" target="_blank" rel="noopener">▶ Watch on ${esc(main.name)}</a>`);
        }
        actions.push(`<a class="btn" href="${esc(it.trailer_link)}" target="_blank" rel="noopener">▶ Trailer</a>`);
        $('mActions').innerHTML = actions.join('');

        el.modal.hidden = false;
        document.body.classList.add('no-scroll');
        el.modal.querySelector('.modal-close').focus();
    }
    function closeModal() {
        el.modal.hidden = true;
        document.body.classList.remove('no-scroll');
    }

    /* ---------- events ---------- */
    function segHandler(id, attr, key, persist) {
        $(id).addEventListener('click', (e) => {
            const btn = e.target.closest('.seg-btn');
            if (!btn || btn.disabled || state[key] === btn.dataset[attr]) return;
            state[key] = btn.dataset[attr];
            if (persist) store.set('w_' + key, state[key]);
            if (key === 'mode' && state.mode === 'theatrical' && state.type === 'tv') {
                state.type = 'movie';
                store.set('w_type', 'movie');
            }
            load(false, true);
        });
    }

    function init() {
        segHandler('modeSeg', 'mode', 'mode', true);
        segHandler('typeSeg', 'type', 'type', true);
        segHandler('rangeSeg', 'range', 'range', true);
        segHandler('regionSeg', 'region', 'region', true);

        if (state.mode === 'theatrical' && state.type === 'tv') state.type = 'movie';

        el.langChips.addEventListener('click', (e) => {
            const c = e.target.closest('.chip'); if (!c) return;
            state.language = c.dataset.value; render();
        });
        el.platformChips.addEventListener('click', (e) => {
            const c = e.target.closest('.chip'); if (!c) return;
            state.platform = c.dataset.value; render();
        });
        el.sort.addEventListener('change', () => { state.sort = el.sort.value; render(); });
        let t;
        el.search.addEventListener('input', () => {
            clearTimeout(t);
            t = setTimeout(() => { state.query = el.search.value; render(); }, 150);
        });
        el.refresh.addEventListener('click', () => load(true));
        el.grid.addEventListener('click', (e) => {
            const c = e.target.closest('.card[data-idx]');
            if (c) openModal(shown[Number(c.dataset.idx)]);
        });
        el.modal.addEventListener('click', (e) => { if (e.target.closest('[data-close]')) closeModal(); });
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && !el.modal.hidden) closeModal(); });

        // Back/forward buttons: re-read the URL and reload that view.
        window.addEventListener('popstate', () => {
            const p = new URLSearchParams(location.search);
            const slug = location.pathname.replace(/\/+$/, '').split('/').pop().toLowerCase();
            state.region = fromSlug[slug] || state.region;
            state.mode = pick(p.get('mode'), ['ott', 'theatrical'], state.mode);
            state.type = pick(p.get('type'), ['movie', 'tv'], state.type);
            state.range = pick(p.get('range'), ['weekend', 'week', 'upcoming', 'recent'], state.range);
            load(false);
        });

        syncControls();
        load(false);
    }

    document.addEventListener('DOMContentLoaded', init);
})();

/* PWA: service worker + "Add to Home screen" prompt */
(function () {
    'use strict';
    const base = document.querySelector('base') ? document.querySelector('base').href : './';
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(() => {}));
    }

    const standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
    const banner = document.getElementById('installBanner');
    if (!banner || standalone) return;

    const KEY = 'w_install_dismissed';
    const dismissedAt = () => { try { return +localStorage.getItem(KEY) || 0; } catch (e) { return 0; } };
    const recentlyDismissed = () => Date.now() - dismissedAt() < 14 * 864e5;
    const hide = () => { banner.hidden = true; };
    document.getElementById('installClose').addEventListener('click', () => {
        try { localStorage.setItem(KEY, String(Date.now())); } catch (e) { /* ignore */ }
        hide();
    });

    let deferred = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred = e;
        if (!recentlyDismissed()) banner.hidden = false;
    });
    document.getElementById('installBtn').addEventListener('click', async () => {
        if (deferred) {
            deferred.prompt();
            await deferred.userChoice.catch(() => {});
            deferred = null;
            hide();
        } else {
            alertIos();
        }
    });
    window.addEventListener('appinstalled', hide);

    // iOS Safari has no install event: show manual instructions instead.
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
    const isSafari = /safari/i.test(navigator.userAgent) && !/crios|fxios|edgios/i.test(navigator.userAgent);
    function alertIos() {
        document.getElementById('installText').textContent = 'Tap Share, then "Add to Home Screen"';
        document.getElementById('installBtn').hidden = true;
    }
    if (isIos && isSafari && !recentlyDismissed()) {
        document.getElementById('installText').textContent = 'Install: tap Share ⎙ then "Add to Home Screen"';
        document.getElementById('installBtn').hidden = true;
        banner.hidden = false;
    }
})();
