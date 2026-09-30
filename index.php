<?php
/**
 * What To Watch - OTT & Theatrical release guide (Indian languages + English)
 * Data comes from api/feed.php (TMDB).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/traffic.php';
require_once __DIR__ . '/lib/og.php';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https' ? 'https' : 'http';
$baseUrl = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/';
traffic_log_request();
header('Cache-Control: no-cache, must-revalidate'); // always fetch the latest page (assets are versioned via ?v=)
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <base href="<?= htmlspecialchars(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/') ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0b0d14">
    <?= og_meta($baseUrl) ?>
    <link rel="manifest" href="manifest.webmanifest">
    <link rel="apple-touch-icon" href="assets/icons/apple-touch-icon.png">
    <link rel="icon" type="image/png" href="assets/icons/icon-192.png">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-title" content="What To Watch">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title>What To Watch | OTT &amp; Theatre Releases</title>
    <meta name="description" content="See what's new on Netflix, Prime Video, JioHotstar and more, and what's playing in theatres — movies and TV shows in Tamil, Telugu, Hindi, Malayalam, Kannada and English.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= DISABLE_CACHE ? time() : filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <?php if (!DISABLE_CACHE): // Microsoft Clarity (skipped on localhost) ?>
    <script type="text/javascript">
        (function(c,l,a,r,i,t,y){
            c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
            t=l.createElement(r);t.async=1;t.src="https://www.clarity.ms/tag/"+i;
            y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
        })(window, document, "clarity", "script", "yqdjyzqv21");
    </script>
    <?php endif; ?>
</head>
<body>
    <header class="topbar">
        <div class="container topbar-inner">
            <a class="brand" href="./" aria-label="What To Watch">
                <span class="brand-mark">▶</span>
                <span class="brand-name">What&nbsp;To&nbsp;Watch</span>
            </a>
            <div class="topbar-actions">
                <div class="seg seg-sm" id="regionSeg" role="radiogroup" aria-label="Region">
                    <button class="seg-btn" data-region="US" role="radio">🇺🇸 <span>USA</span></button>
                    <button class="seg-btn" data-region="IN" role="radio">🇮🇳 <span>India</span></button>
                </div>
                <button id="installTopBtn" class="install-top" title="Install app" aria-label="Install app">⬇ <span>Install</span></button>
                <button id="refreshBtn" class="icon-btn"<?= DISABLE_CACHE ? '' : ' hidden' ?> title="Refresh" aria-label="Refresh">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>
                </button>
            </div>
        </div>
    </header>

    <section class="hero">
        <div class="container">
            <h1 id="heroTitle">New on OTT</h1>
            <p class="hero-sub" id="heroSub"></p>

            <div class="controls">
                <div class="seg seg-lg" id="modeSeg" role="tablist" aria-label="Where to watch">
                    <button class="seg-btn" data-mode="ott" role="tab">📺 <span>OTT</span></button>
                    <button class="seg-btn" data-mode="theatrical" role="tab">🎟️ <span>In Theatres</span></button>
                </div>
                <div class="seg seg-lg" id="typeSeg" role="tablist" aria-label="Content type">
                    <button class="seg-btn" data-type="movie" role="tab">🎬 <span>Movies</span></button>
                    <button class="seg-btn" data-type="tv" role="tab">📡 <span>TV Shows</span></button>
                </div>
                <div class="seg" id="rangeSeg" role="tablist" aria-label="Time range">
                    <button class="seg-btn" data-range="weekend" role="tab">This weekend</button>
                    <button class="seg-btn" data-range="week" role="tab">Next 7 days</button>
                    <button class="seg-btn" data-range="upcoming" role="tab">Next 30 days</button>
                    <button class="seg-btn" data-range="recent" role="tab">Last 30 days</button>
                </div>
            </div>
        </div>
    </section>

    <main class="container main">
        <div class="filters" id="filters" hidden>
            <div class="filter-line">
                <div class="chips" id="langChips" aria-label="Language"></div>
            </div>
            <div class="filter-line" id="platformLine">
                <div class="chips" id="platformChips" aria-label="Platform"></div>
            </div>
            <div class="filter-line tools">
                <label class="search">
                    <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="7"/><path d="M21 21l-4.3-4.3"/></svg>
                    <input id="searchInput" type="search" placeholder="Search titles" autocomplete="off">
                </label>
                <label class="sort">
                    <span>Sort</span>
                    <select id="sortSelect">
                        <option value="popular">Most popular</option>
                        <option value="date">Release date</option>
                        <option value="rating">Rating</option>
                    </select>
                </label>
                <span class="count" id="countText"></span>
            </div>
        </div>

        <div id="statusBox" class="state" hidden></div>
        <div id="grid" class="grid" aria-live="polite"></div>
    </main>

    <footer class="footer container">
        <span id="footerText">Release dates and availability can change — check the platform for the latest.</span>
    <a class="footer-link" href="https://radiovibe.app/">← Back to RadioVibe</a>
    </footer>

    <div class="install-banner" id="installBanner" hidden>
        <span class="install-text" id="installText">Install What To Watch for quick access</span>
        <button class="install-btn" id="installBtn">Install</button>
        <button class="install-close" id="installClose" aria-label="Dismiss">✕</button>
    </div>

    <div class="modal" id="modal" hidden>
        <div class="modal-backdrop" data-close></div>
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="mTitle">
            <button class="modal-close" data-close aria-label="Close">✕</button>
            <div class="modal-hero" id="mHero"></div>
            <div class="modal-body">
                <h2 id="mTitle"></h2>
                <div class="modal-meta" id="mMeta"></div>
                <p class="modal-tagline" id="mTagline"></p>
                <p class="modal-overview" id="mOverview"></p>
                <div id="mProviders"></div>
                <div class="modal-actions" id="mActions"></div>
            </div>
        </div>
    </div>

    <script src="assets/js/app.js?v=<?= DISABLE_CACHE ? time() : filemtime(__DIR__ . '/assets/js/app.js') ?>"></script>
</body>
</html>
