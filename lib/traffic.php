<?php
/**
 * Lightweight visitor tracking (no database). Appends one JSON line per page view to
 * cache/traffic_visits.json (cache/ is blocked from public access and skipped by deploys).
 * Ported from the exchange-car TrafficTracker.
 */

const TRAFFIC_LOG = __DIR__ . '/../cache/traffic_visits.json';

function traffic_is_bot(string $ua): bool
{
    if ($ua === '' || $ua === 'Unknown') return true;
    $sigs = ['bot', 'crawl', 'spider', 'slurp', 'mediapartners', 'headless', 'curl', 'wget', 'python',
        'postman', 'preview', 'facebookexternalhit', 'whatsapp', 'discordbot', 'slackbot', 'telegrambot',
        'twitterbot', 'applebot', 'semrush', 'ahref', 'mj12bot', 'dotbot', 'yandex', 'baidu', 'bingbot',
        'googlebot', 'gptbot', 'chatgpt', 'claudebot', 'perplexity'];
    $lower = strtolower($ua);
    foreach ($sigs as $s) {
        if (strpos($lower, $s) !== false) return true;
    }
    return false;
}

function traffic_client_ip(): string
{
    if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return $_SERVER['HTTP_CF_CONNECTING_IP'];
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
    return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
}

function traffic_country(string $ip): array
{
    if (in_array($ip, ['127.0.0.1', '::1'], true)) return ['name' => 'Localhost', 'code' => 'XX', 'flag' => '🏠'];
    $code = strtoupper($_SERVER['HTTP_CF_IPCOUNTRY'] ?? $_SERVER['HTTP_X_COUNTRY_CODE'] ?? '');
    if (!preg_match('/^[A-Z]{2}$/', $code) || $code === 'XX') return ['name' => 'Unknown', 'code' => '', 'flag' => '🌐'];
    $names = ['US' => 'United States', 'IN' => 'India', 'CA' => 'Canada', 'GB' => 'United Kingdom', 'AU' => 'Australia',
        'AE' => 'UAE', 'SG' => 'Singapore', 'DE' => 'Germany', 'FR' => 'France', 'LK' => 'Sri Lanka', 'MY' => 'Malaysia'];
    $flag = mb_chr(ord($code[0]) + 127397) . mb_chr(ord($code[1]) + 127397);
    return ['name' => $names[$code] ?? $code, 'code' => $code, 'flag' => $flag];
}

function traffic_categorize(): array
{
    $path = trim(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '', '/');
    $slug = strtolower(basename($path));
    $region = $slug === 'india' ? 'India' : ($slug === 'usa' ? 'USA' : 'Home');
    $isApp = ($_GET['source'] ?? '') === 'pwa';
    return [
        'label' => $region . ($isApp ? ' (installed app)' : ''),
        'category' => $isApp ? 'Installed App' : $region,
    ];
}

function traffic_log_request(): void
{
    if (PHP_SAPI === 'cli' || ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') return;

    // Skip the service worker's own background fetch of the page (not a real visit).
    if (stripos($_SERVER['HTTP_REFERER'] ?? '', '/sw.js') !== false) return;

    $ip = traffic_client_ip();
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
    $page = traffic_categorize();
    $country = traffic_country($ip);

    $record = [
        'time' => date('Y-m-d H:i:s'),
        'date' => date('Y-m-d'),
        'timestamp' => time(),
        'ip' => $ip,
        'page' => $page['label'],
        'category' => $page['category'],
        'uri' => $_SERVER['REQUEST_URI'] ?? '/',
        'country' => $country['name'],
        'country_code' => $country['code'],
        'flag' => $country['flag'],
        'ref' => $_SERVER['HTTP_REFERER'] ?? 'direct',
        'ua' => $ua,
        'is_bot' => traffic_is_bot($ua),
    ];

    if (!is_dir(dirname(TRAFFIC_LOG))) @mkdir(dirname(TRAFFIC_LOG), 0755, true);
    @file_put_contents(TRAFFIC_LOG, json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);

    // Occasionally trim to the latest 5000 lines once the log passes 5MB.
    if (mt_rand(1, 200) === 1 && @filesize(TRAFFIC_LOG) > 5 * 1024 * 1024) {
        $lines = @file(TRAFFIC_LOG, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines && count($lines) > 5000) {
            @file_put_contents(TRAFFIC_LOG, implode("\n", array_slice($lines, -5000)) . "\n", LOCK_EX);
        }
    }
}

/**
 * Country lookup by IP for hosts without a CDN country header. Results are cached forever in
 * cache/geo_cache.json; at most $budget new lookups per call (free ipwho.is service, HTTPS).
 * Returns [ip => ['name'=>..,'code'=>..,'flag'=>..]] for the requested IPs (missing = unresolved).
 */
function traffic_geo_resolve(array $ips, int $budget = 15): array
{
    $file = __DIR__ . '/../cache/geo_cache.json';
    $cache = json_decode((string)@file_get_contents($file), true) ?: [];
    $dirty = false;
    foreach (array_unique($ips) as $ip) {
        if (isset($cache[$ip]) || $budget <= 0 || !filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) continue;
        $budget--;
        $ctx = stream_context_create(['http' => ['timeout' => 2]]);
        $res = json_decode((string)@file_get_contents('https://ipwho.is/' . rawurlencode($ip) . '?fields=success,country,country_code', false, $ctx), true);
        if (!$res) continue; // network error: retry next time
        $code = strtoupper($res['country_code'] ?? '');
        $cache[$ip] = !empty($res['success']) && strlen($code) === 2
            ? ['name' => $res['country'], 'code' => $code, 'flag' => mb_chr(ord($code[0]) + 127397) . mb_chr(ord($code[1]) + 127397)]
            : ['name' => 'Unknown', 'code' => '', 'flag' => '🌐'];
        $dirty = true;
    }
    if ($dirty) @file_put_contents($file, json_encode($cache, JSON_UNESCAPED_UNICODE), LOCK_EX);
    return $cache;
}
