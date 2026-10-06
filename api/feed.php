<?php
/**
 * What To Watch - unified feed (TMDB only)
 *
 *   mode   = ott | theatrical
 *   type   = movie | tv            (tv is OTT-only)
 *   region = US | IN
 *   range  = weekend | upcoming | recent
 *
 * OTT movies       -> discover/movie with_release_type=4 (digital), then per-title
 *                     watch/providers to attach the actual platforms.
 * OTT TV shows     -> discover/tv filtered to the region's major streaming providers
 *                     with episodes airing in the window.
 * Theatrical       -> discover/movie with_release_type=2|3 (theatrical) in the window.
 */

declare(strict_types=1);
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once __DIR__ . '/../config.php';

// Local dev: never cache. Live: browsers may reuse a response for 5 minutes (server cache lasts CACHE_DURATION, 6h).
header('Cache-Control: ' . ((defined('DISABLE_CACHE') && DISABLE_CACHE) ? 'no-store' : 'public, max-age=300'));

const LANGUAGES = [
    'ta' => 'Tamil',
    'te' => 'Telugu',
    'hi' => 'Hindi',
    'ml' => 'Malayalam',
    'kn' => 'Kannada',
    'en' => 'English',
];

// Curated major streaming providers per region (TMDB provider ids).
const REGION_PROVIDERS = [
    'IN' => [8, 119, 2336, 232, 237, 309],       // Netflix, Prime, JioHotstar, Zee5, SonyLIV, Sun NXT
    'US' => [8, 119, 337, 350, 15, 1899, 386, 531], // Netflix, Prime, Disney+, Apple TV+, Hulu, Max, Peacock, Paramount+
];

/** True when a cache file is fresh enough to use (always false in local dev). */
function cacheFresh(string $file, int $ttl): bool {
    return !(defined('DISABLE_CACHE') && DISABLE_CACHE) && is_file($file) && (time() - filemtime($file)) < $ttl;
}

/**
 * Data refreshes once a week: the "cache week" starts every Wednesday 00:00 (APP_TIMEZONE).
 * Returns that Wednesday (today, if today is Wednesday).
 */
function cacheWeekStart(): DateTime {
    $tz = new DateTimeZone(defined('APP_TIMEZONE') ? APP_TIMEZONE : 'America/New_York');
    $d = new DateTime('today', $tz);
    $back = ((int)$d->format('w') - 3 + 7) % 7; // 3 = Wednesday
    return $d->modify("-{$back} days");
}

/** Server cache lifetime in seconds (CACHE_DURATION, 6h by default). */
function cacheTtl(): int {
    return defined('CACHE_DURATION') ? (int)CACHE_DURATION : 21600;
}

/** True when the file was written during the current Wednesday-to-Tuesday cache week (never in local dev). */
function cacheFreshThisWeek(string $file): bool {
    return !(defined('DISABLE_CACHE') && DISABLE_CACHE) && is_file($file) && filemtime($file) >= cacheWeekStart()->getTimestamp();
}

function cacheDir(string $sub = ''): string {
    $dir = (defined('CACHE_DIR') ? CACHE_DIR : __DIR__ . '/../cache') . ($sub ? '/' . $sub : '');
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}

function tmdbToken(): string {
    return defined('TMDB_ACCESS_TOKEN') ? trim(TMDB_ACCESS_TOKEN) : '';
}

/** Run many TMDB GETs in parallel. $requests: key => [endpoint, params]. Returns key => decoded array (or []). */
function tmdbBatch(array $requests): array {
    if (empty($requests)) {
        return [];
    }
    $mh = curl_multi_init();
    $handles = [];
    foreach ($requests as $key => [$endpoint, $params]) {
        $url = 'https://api.themoviedb.org/3/' . ltrim($endpoint, '/');
        if (!empty($params)) {
            $url .= '?' . http_build_query($params);
        }
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . tmdbToken(), 'Accept: application/json'],
        ]);
        curl_multi_add_handle($mh, $ch);
        $handles[$key] = $ch;
    }
    $running = null;
    do {
        $status = curl_multi_exec($mh, $running);
        if ($running > 0) {
            curl_multi_select($mh, 1.0);
        }
    } while ($running > 0 && $status === CURLM_OK);

    $out = [];
    foreach ($handles as $key => $ch) {
        $data = json_decode((string)curl_multi_getcontent($ch), true);
        $out[$key] = is_array($data) ? $data : [];
        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
    }
    curl_multi_close($mh);
    return $out;
}

function tmdbGet(string $endpoint, array $params = []): array {
    return tmdbBatch(['x' => [$endpoint, $params]])['x'];
}

function getGenreMap(): array {
    $file = cacheDir() . '/genres_all.json';
    if (cacheFresh($file, 30 * 86400)) {
        $c = json_decode((string)file_get_contents($file), true);
        if (is_array($c) && $c) {
            return $c;
        }
    }
    $res = tmdbBatch([
        'm' => ['genre/movie/list', ['language' => 'en-US']],
        't' => ['genre/tv/list', ['language' => 'en-US']],
    ]);
    $map = [];
    foreach (['m', 't'] as $k) {
        foreach (($res[$k]['genres'] ?? []) as $g) {
            $map[$g['id']] = $g['name'];
        }
    }
    if ($map) {
        @file_put_contents($file, json_encode($map));
    }
    return $map;
}

/**
 * Date window for the requested range, anchored to the cache week's Wednesday so a
 * week's cached result is internally consistent. Returns [from, to, label].
 *   week     = Wed .. next Wed      weekend  = that week's Fri-Sun
 *   upcoming = Wed .. +30 days      recent   = 30 days before Wed
 */
function dateWindow(string $range): array {
    $wed = cacheWeekStart();
    $fmt = fn(DateTime $d) => $d->format('Y-m-d');
    if ($range === 'recent') {
        return [$fmt((clone $wed)->modify('-30 days')), $fmt($wed), 'Last 30 days'];
    }
    if ($range === 'week') {
        return [$fmt($wed), $fmt((clone $wed)->modify('+7 days')), 'Next 7 days'];
    }
    if ($range === 'upcoming') {
        return [$fmt($wed), $fmt((clone $wed)->modify('+30 days')), 'Next 30 days'];
    }
    $fri = (clone $wed)->modify('+2 days');
    $sun = (clone $wed)->modify('+4 days');
    return [$fmt($fri), $fmt($sun), $fri->format('D M j') . ' – ' . $sun->format('D M j')];
}

/**
 * Per-title details via one call each (parallel): providers + regional release dates + runtime.
 * Cached per title for CACHE_DURATION. Returns id => slim array.
 */
function getDetails(array $ids, string $type): array {
    $dir = cacheDir('details');
    $out = [];
    $need = [];
    foreach ($ids as $id) {
        $file = "$dir/{$type}_{$id}.json";
        if (cacheFresh($file, cacheTtl())) {
            $c = json_decode((string)file_get_contents($file), true);
            if (is_array($c)) {
                $out[$id] = $c;
                continue;
            }
        }
        $need[$id] = ["$type/$id", ['append_to_response' => $type === 'movie' ? 'watch/providers,release_dates' : 'watch/providers']];
    }
    foreach (tmdbBatch($need) as $id => $r) {
        if (!isset($r['id'])) {
            $out[$id] = [];
            continue;
        }
        $slim = [
            'runtime'   => $r['runtime'] ?? null,
            'seasons'   => $r['number_of_seasons'] ?? null,
            'tagline'   => $r['tagline'] ?? '',
            'providers' => array_intersect_key($r['watch/providers']['results'] ?? [], ['US' => 1, 'IN' => 1]),
            'dates'     => [],
            'cert'      => [],
        ];
        foreach (($r['release_dates']['results'] ?? []) as $rd) {
            $cc = $rd['iso_3166_1'] ?? '';
            if ($cc !== 'US' && $cc !== 'IN') {
                continue;
            }
            foreach (($rd['release_dates'] ?? []) as $d) {
                $slim['dates'][$cc][] = ['type' => $d['type'] ?? 0, 'date' => substr((string)($d['release_date'] ?? ''), 0, 10), 'note' => trim((string)($d['note'] ?? ''))];
                if (!empty($d['certification']) && empty($slim['cert'][$cc])) {
                    $slim['cert'][$cc] = $d['certification'];
                }
            }
        }
        @file_put_contents("$dir/{$type}_{$id}.json", json_encode($slim));
        $out[$id] = $slim;
    }
    return $out;
}

/** Best regional release date for the given release types, preferring one inside the window. */
function pickDate(array $dates, array $types, string $from, string $to): string {
    $inWindow = '';
    $any = '';
    foreach ($dates as $d) {
        if (!in_array($d['type'], $types, true) || $d['date'] === '') {
            continue;
        }
        if ($d['date'] >= $from && $d['date'] <= $to && ($inWindow === '' || $d['date'] < $inWindow)) {
            $inWindow = $d['date'];
        }
        if ($any === '' || $d['date'] < $any) {
            $any = $d['date'];
        }
    }
    return $inWindow !== '' ? $inWindow : $any;
}

/** Normalise platform names from different sources to the names TMDB uses. */
function normalizePlatform(string $name): string {
    $map = [
        'prime video' => 'Amazon Prime Video', 'amazon prime' => 'Amazon Prime Video', 'amazon prime video' => 'Amazon Prime Video',
        'disney+' => 'Disney+', 'disney plus' => 'Disney+', 'hbo max' => 'HBO Max', 'max' => 'HBO Max',
        'paramount plus' => 'Paramount+', 'paramount+' => 'Paramount+', 'apple tv+' => 'Apple TV', 'apple tv' => 'Apple TV',
        'jiohotstar' => 'JioHotstar', 'jio hotstar' => 'JioHotstar', 'youtube' => 'YouTube', 'hotstar' => 'JioHotstar', 'disney+ hotstar' => 'JioHotstar',
        'sonyliv' => 'Sony Liv', 'sony liv' => 'Sony Liv', 'zee5' => 'Zee5', 'sun nxt' => 'Sun Nxt', 'sunnxt' => 'Sun Nxt',
        'netflix' => 'Netflix', 'hulu' => 'Hulu', 'peacock' => 'Peacock',
    ];
    $k = strtolower(trim($name));
    return $map[$k] ?? trim($name);
}

/**
 * Watchmode release calendar (streaming additions). It is US-centric, so for India only
 * globally-released services are trusted. Returns tmdb_id => [name, date, season] per type.
 */
function getWatchmodeAdditions(string $type, string $region, string $from, string $to): array {
    $key = defined('WATCHMODE_API_KEY') ? trim(WATCHMODE_API_KEY) : '';
    if ($key === '' || $key === 'YOUR_WATCHMODE_API_KEY_HERE') {
        return [];
    }
    $file = cacheDir('feed') . '/wm_' . $from . '_' . $to . '.json';
    $ttl = defined('CACHE_DURATION') ? CACHE_DURATION : 21600;
    $rows = null;
    if (cacheFresh($file, cacheTtl())) {
        $rows = json_decode((string)file_get_contents($file), true);
    }
    if (!is_array($rows)) {
        $rows = [];
        for ($page = 1; $page <= 3; $page++) {
            $ch = curl_init('https://api.watchmode.com/v1/releases/?' . http_build_query([
                'apiKey' => $key, 'start_date' => str_replace('-', '', $from), 'end_date' => str_replace('-', '', $to),
                'limit' => 250, 'page' => $page,
            ]));
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15]);
            $data = json_decode((string)curl_exec($ch), true);
            curl_close($ch);
            $batch = is_array($data['releases'] ?? null) ? $data['releases'] : [];
            $rows = array_merge($rows, $batch);
            if (count($batch) < 250) {
                break;
            }
        }
        @file_put_contents($file, json_encode($rows));
    }
    $allowed = $region === 'IN'
        ? ['Netflix', 'Amazon Prime Video']
        : ['Netflix', 'Amazon Prime Video', 'Disney+', 'Hulu', 'HBO Max', 'Apple TV', 'Peacock', 'Paramount+'];
    $out = [];
    foreach ($rows as $r) {
        $tid = $r['tmdb_id'] ?? null;
        $ttype = ($r['tmdb_type'] ?? '') === 'tv' ? 'tv' : 'movie';
        $name = normalizePlatform((string)($r['source_name'] ?? ''));
        if (!$tid || $ttype !== $type || !in_array($name, $allowed, true)) {
            continue;
        }
        $out[$tid] = ['name' => $name, 'date' => (string)($r['source_release_date'] ?? ''), 'season' => (int)($r['season_number'] ?? 0)];
    }
    return $out;
}

/** Base TMDB info for titles Watchmode found but discover didn't (cached 7 days). Returns discover-shaped items. */
function getBaseItems(array $ids, string $type): array {
    $dir = cacheDir('base');
    $out = [];
    $need = [];
    foreach ($ids as $id) {
        $file = "$dir/{$type}_{$id}.json";
        if (cacheFresh($file, 7 * 86400)) {
            $c = json_decode((string)file_get_contents($file), true);
            if (is_array($c)) {
                $out[$id] = $c;
                continue;
            }
        }
        $need[$id] = ["$type/$id", []];
    }
    foreach (tmdbBatch($need) as $id => $r) {
        if (!isset($r['id'])) {
            continue;
        }
        $r['genre_ids'] = array_column($r['genres'] ?? [], 'id');
        unset($r['genres'], $r['production_companies'], $r['production_countries'], $r['spoken_languages']);
        @file_put_contents("$dir/{$type}_{$id}.json", json_encode($r));
        $out[$id] = $r;
    }
    return $out;
}

function buildProviders(array $regionData): array {
    $list = [];
    $seen = [];
    foreach (['flatrate' => 'stream', 'free' => 'free', 'ads' => 'free', 'rent' => 'rent', 'buy' => 'rent'] as $bucket => $kind) {
        foreach (($regionData[$bucket] ?? []) as $p) {
            $name = preg_replace('/ (Standard )?with Ads$/i', '', (string)$p['provider_name']);
            $name = preg_replace('/ Amazon Channel$/i', '', $name);
            if (isset($seen[$name])) {
                continue;
            }
            $seen[$name] = true;
            $list[] = [
                'id'   => $p['provider_id'],
                'name' => $name,
                'logo' => !empty($p['logo_path']) ? 'https://image.tmdb.org/t/p/w92' . $p['logo_path'] : '',
                'kind' => $kind,
            ];
        }
    }
    return $list;
}

try {
    if (tmdbToken() === '' || tmdbToken() === 'YOUR_TMDB_ACCESS_TOKEN_HERE') {
        echo json_encode(['success' => false, 'error' => 'TMDB token is not configured in config.php.']);
        exit;
    }

    $mode   = ($_GET['mode'] ?? 'ott') === 'theatrical' ? 'theatrical' : 'ott';
    $type   = ($_GET['type'] ?? 'movie') === 'tv' ? 'tv' : 'movie';
    $region = strtoupper((string)($_GET['region'] ?? 'IN')) === 'US' ? 'US' : 'IN';
    $range  = in_array($_GET['range'] ?? '', ['weekend', 'week', 'upcoming', 'recent'], true) ? $_GET['range'] : 'upcoming';
    $force  = ($_GET['refresh'] ?? '') === '1' && defined('DISABLE_CACHE') && DISABLE_CACHE; // public visitors can't force upstream calls

    if ($mode === 'theatrical' && $type === 'tv') {
        $type = 'movie'; // TV shows don't have a theatrical run
    }

    [$from, $to, $label] = dateWindow($range);

    $cacheFile = cacheDir('feed') . "/{$mode}_{$type}_{$region}_{$range}_" . cacheWeekStart()->format('Ymd') . '_v2.json'; // bump _vN to invalidate all cached feeds on deploy
    $ttl = defined('CACHE_DURATION') ? CACHE_DURATION : 21600;
    if (!$force && cacheFresh($cacheFile, cacheTtl())) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (is_array($cached)) {
            $cached['cached'] = true;
            echo json_encode($cached, JSON_UNESCAPED_SLASHES);
            exit;
        }
    }

    // 1. Discover candidates, one request per language (parallel).
    $requests = [];
    foreach (LANGUAGES as $code => $name) {
        $p = [
            'with_original_language' => $code,
            'include_adult'          => 'false',
            'sort_by'                => 'popularity.desc',
            'page'                   => 1,
        ];
        if ($type === 'tv') {
            $p['air_date.gte'] = $from;
            $p['air_date.lte'] = $to;
            $p['watch_region'] = $region;
            $p['with_watch_providers'] = implode('|', REGION_PROVIDERS[$region]);
            $requests[$code] = ['discover/tv', $p];
        } else {
            $p['region'] = $region;
            $p['release_date.gte'] = $from;
            $p['release_date.lte'] = $to;
            $p['with_release_type'] = $mode === 'theatrical' ? '2|3' : '4';
            $requests[$code] = ['discover/movie', $p];
        }
    }
    $discovered = tmdbBatch($requests);

    $candidates = [];
    foreach ($discovered as $code => $resp) {
        foreach (($resp['results'] ?? []) as $item) {
            $id = $item['id'] ?? null;
            if ($id && !isset($candidates[$id]) && !empty($item['poster_path'])) {
                $item['_lang'] = $code;
                $candidates[$id] = $item;
            }
        }
    }


    // 1b. Supplement OTT with Watchmode's release calendar (knows some additions TMDB has no platform for yet).
    $wm = [];
    if ($mode === 'ott') {
        $wm = getWatchmodeAdditions($type, $region, $from, $to);
        $missing = array_values(array_diff(array_keys($wm), array_keys($candidates)));
        foreach (getBaseItems($missing, $type) as $id => $item) {
            $lang = $item['original_language'] ?? '';
            if (isset(LANGUAGES[$lang]) && !empty($item['poster_path'])) {
                $item['_lang'] = $lang;
                $candidates[$id] = $item;
            }
        }
    }

    // 2. Per-title details: platforms, regional release date, runtime.
    $details = getDetails(array_keys($candidates), $type);

    $genreMap = getGenreMap();
    $today = date('Y-m-d');
    $releases = [];

    foreach ($candidates as $id => $item) {
        $d = $details[$id] ?? [];
        $regionDates = $d['dates'][$region] ?? [];
        $rd = $d['providers'][$region] ?? [];
        $providers = $mode === 'ott' ? buildProviders($rd) : [];
        $wmHit = $wm[$id] ?? null;
        if ($mode === 'ott' && !$providers) {
            // Fallback 1: Watchmode's platform; fallback 2: platform named in TMDB's digital release note.
            $fallback = $wmHit['name'] ?? '';
            if ($fallback === '') {
                foreach ($regionDates as $rdate) {
                    if ($rdate['type'] === 4 && !empty($rdate['note'])) {
                        $fallback = normalizePlatform($rdate['note']);
                        break;
                    }
                }
            }
            if ($fallback !== '') {
                $providers[] = ['id' => 0, 'name' => $fallback, 'logo' => '', 'kind' => 'stream'];
            }
        }

        $hasStreaming = false;
        foreach ($providers as $pr) {
            if ($pr['kind'] !== 'rent') {
                $hasStreaming = true;
            }
        }

        if ($type === 'tv') {
            $date = $item['first_air_date'] ?? '';
            $isNew = $date >= $from && $date <= $to;
            if ($wmHit) {
                $isNew = $wmHit['season'] <= 1;
                if ($wmHit['date'] !== '') {
                    $date = $wmHit['date'];
                }
            }
            // Drop long-finished catalogue shows that only match via old reruns.
            if ($date === '' || $date < date('Y-m-d', strtotime('-5 years'))) {
                continue;
            }
            if (!$hasStreaming) {
                continue;
            }
        } else {
            $isNew = false;
            $date = $mode === 'ott'
                ? pickDate($regionDates, [4], $from, $to)
                : pickDate($regionDates, [2, 3], $from, $to);
            if ($wmHit && $wmHit['date'] !== '') {
                $date = $wmHit['date'];
            }
            if ($date === '') {
                $date = $item['release_date'] ?? '';
            }
            if ($date < $from || $date > $to) {
                continue;
            }
            if ($mode === 'ott') {
                // Rent/buy-only titles aren't "OTT"; keep unknown-platform ones only if not out yet.
                if (!$hasStreaming && !($providers === [] && $date > $today)) {
                    continue;
                }
            }
        }

        $genres = [];
        foreach (($item['genre_ids'] ?? []) as $gid) {
            if (isset($genreMap[$gid])) {
                $genres[] = $genreMap[$gid];
            }
        }

        $releases[] = [
            'id'            => $type . '_' . $id,
            'tmdb_id'       => $id,
            'type'          => $type,
            'title'         => $item['title'] ?? $item['name'] ?? 'Untitled',
            'original_title'=> $item['original_title'] ?? $item['original_name'] ?? '',
            'poster'        => 'https://image.tmdb.org/t/p/w500' . $item['poster_path'],
            'backdrop'      => !empty($item['backdrop_path']) ? 'https://image.tmdb.org/t/p/w780' . $item['backdrop_path'] : '',
            'language'      => LANGUAGES[$item['_lang']] ?? 'Other',
            'language_code' => $item['_lang'],
            'release_date'  => $date,
            'is_new'        => $isNew,
            'overview'      => $item['overview'] ?? '',
            'tagline'       => $d['tagline'] ?? '',
            'genres'        => array_slice($genres, 0, 3),
            'rating'        => (($item['vote_count'] ?? 0) >= 5 && !empty($item['vote_average'])) ? round((float)$item['vote_average'], 1) : null,
            'runtime'       => $d['runtime'] ?? null,
            'seasons'       => $d['seasons'] ?? null,
            'certification' => $d['cert'][$region] ?? '',
            'popularity'    => round((float)($item['popularity'] ?? 0), 1),
            'providers'     => $providers,
            'has_streaming' => $hasStreaming,
            'trailer_link'  => 'https://www.youtube.com/results?search_query=' . rawurlencode(($item['title'] ?? $item['name'] ?? '') . ' trailer'),
        ];
    }

    usort($releases, fn($a, $b) => $b['popularity'] <=> $a['popularity']);

    $result = [
        'success'      => true,
        'cached'       => false,
        'mode'         => $mode,
        'type'         => $type,
        'region'       => $region,
        'range'        => $range,
        'window'       => ['from' => $from, 'to' => $to, 'label' => $label],
        'generated_at' => date('c'),
        'refreshes_on' => (clone cacheWeekStart())->modify('+7 days')->format('Y-m-d'),
        'releases'     => $releases,
    ];
    @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_SLASHES));
    echo json_encode($result, JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Server error: ' . $e->getMessage()]);
}
