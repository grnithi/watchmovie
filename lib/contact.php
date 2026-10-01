<?php
/**
 * Contact-form helpers (no database). Spam/abuse defences:
 *  - stateless HMAC-signed, expiring, single-use form tokens (CSRF + replay protection)
 *  - minimum fill time, honeypot field, same-origin check
 *  - per-IP and global rate limits (files in cache/contact/, which is not web-accessible)
 *  - strict validation, header-injection-proof mail headers, link/URL limits
 *  - optional Cloudflare Turnstile
 */

const CONTACT_DIR = __DIR__ . '/../cache/contact';
const CONTACT_MIN_SECONDS = 4;      // humans need a few seconds to fill the form
const CONTACT_MAX_SECONDS = 7200;   // token expires after 2 hours
const CONTACT_IP_PER_HOUR = 3;
const CONTACT_IP_PER_DAY = 6;
const CONTACT_GLOBAL_PER_DAY = 40;

function contact_dir(): string
{
    if (!is_dir(CONTACT_DIR)) @mkdir(CONTACT_DIR, 0700, true);
    return CONTACT_DIR;
}

function contact_secret(): string
{
    if (defined('CONTACT_SECRET') && CONTACT_SECRET !== '') return CONTACT_SECRET;
    $file = contact_dir() . '/secret.key'; // auto-generated once, kept outside public access
    $s = is_file($file) ? trim((string)@file_get_contents($file)) : '';
    if (strlen($s) < 32) {
        $s = bin2hex(random_bytes(32));
        @file_put_contents($file, $s, LOCK_EX);
        @chmod($file, 0600);
    }
    return $s;
}

function contact_b64(string $s): string { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

function contact_ip(): string
{
    $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '0.0.0.0';
}

function contact_token(): string
{
    $payload = time() . '.' . bin2hex(random_bytes(8));
    return contact_b64($payload) . '.' . contact_b64(hash_hmac('sha256', $payload, contact_secret(), true));
}

/** Returns null if the token is valid, else a short reason code. */
function contact_check_token(string $token): ?string
{
    $parts = explode('.', $token);
    if (count($parts) !== 2) return 'bad';
    $payload = base64_decode(strtr($parts[0], '-_', '+/'), true);
    $sig = base64_decode(strtr($parts[1], '-_', '+/'), true);
    if ($payload === false || $sig === false || !preg_match('/^(\d{10})\.([0-9a-f]{16})$/', $payload, $m)) return 'bad';
    if (!hash_equals(hash_hmac('sha256', $payload, contact_secret(), true), $sig)) return 'bad';
    $age = time() - (int)$m[1];
    if ($age < CONTACT_MIN_SECONDS) return 'fast';
    if ($age > CONTACT_MAX_SECONDS) return 'expired';
    // single use: the nonce can only ever be redeemed once
    $used = contact_dir() . '/used_' . $m[2];
    $fh = @fopen($used, 'x');
    if ($fh === false) return 'replay';
    fclose($fh);
    return null;
}

/** Same-origin check: the browser's Origin/Referer host must match this site. */
function contact_same_origin(): bool
{
    $host = strtolower($_SERVER['HTTP_HOST'] ?? '');
    $src = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($src === '' || $host === '') return false;
    return strtolower((string)parse_url($src, PHP_URL_HOST) . (($p = parse_url($src, PHP_URL_PORT)) ? ':' . $p : '')) === $host;
}

/** Sliding-window rate limit. Records a hit and returns true if allowed. */
function contact_rate_ok(string $key, int $max, int $window): bool
{
    $file = contact_dir() . '/rl_' . hash('sha256', $key . contact_secret());
    $fh = @fopen($file, 'c+');
    if (!$fh) return true; // never lock out real users because of a disk problem
    flock($fh, LOCK_EX);
    $hits = json_decode((string)stream_get_contents($fh), true) ?: [];
    $now = time();
    $hits = array_values(array_filter($hits, fn($t) => $t > $now - $window));
    $ok = count($hits) < $max;
    if ($ok) $hits[] = $now;
    ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($hits));
    flock($fh, LOCK_UN); fclose($fh);
    return $ok;
}

/** Remove stale token/rate-limit files (cheap, runs on ~2% of requests). */
function contact_gc(): void
{
    if (random_int(1, 50) !== 1) return;
    foreach (glob(contact_dir() . '/{used_,rl_}*', GLOB_BRACE) ?: [] as $f) {
        if (filemtime($f) < time() - 2 * 86400) @unlink($f);
    }
}

function contact_clean_line(string $s): string
{
    $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $s) ?? '';
    return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
}

function contact_clean_text(string $s): string
{
    $s = str_replace("\r\n", "\n", $s);
    $s = preg_replace('/[^\P{C}\n\t]+/u', '', $s) ?? ''; // strip control chars except newline/tab
    return trim($s);
}

/** @return array{0: array<string,string>, 1: array<string,string>} [clean values, errors] */
function contact_validate(array $in): array
{
    $v = [
        'name' => contact_clean_line((string)($in['name'] ?? '')),
        'email' => contact_clean_line((string)($in['email'] ?? '')),
        'message' => contact_clean_text((string)($in['message'] ?? '')),
    ];
    $e = [];
    $len = fn($s) => mb_strlen($s, 'UTF-8');
    if ($len($v['name']) < 2 || $len($v['name']) > 80) $e['name'] = 'Please enter your name (2–80 characters).';
    elseif (preg_match('~https?://|www\.|@~i', $v['name'])) $e['name'] = 'Please use a plain name.';
    if ($len($v['email']) > 254 || !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) $e['email'] = 'Please enter a valid email address.';
    if ($len($v['message']) < 10 || $len($v['message']) > 2000) $e['message'] = 'Message must be 10–2000 characters.';
    elseif (preg_match_all('~https?://|www\.~i', $v['message']) > 2) $e['message'] = 'Please include at most two links.';
    elseif (!preg_match('//u', $v['message'])) $e['message'] = 'Message contains invalid characters.';
    return [$v, $e];
}

function contact_turnstile_enabled(): bool
{
    return defined('TURNSTILE_SITE_KEY') && defined('TURNSTILE_SECRET') && TURNSTILE_SITE_KEY !== '' && TURNSTILE_SECRET !== '';
}

function contact_turnstile_ok(string $response): bool
{
    if ($response === '' || strlen($response) > 2048) return false;
    $ctx = stream_context_create(['http' => [
        'method' => 'POST', 'timeout' => 6,
        'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
        'content' => http_build_query(['secret' => TURNSTILE_SECRET, 'response' => $response, 'remoteip' => contact_ip()]),
    ]]);
    $raw = @file_get_contents('https://challenges.cloudflare.com/turnstile/v0/siteverify', false, $ctx);
    $j = $raw ? json_decode($raw, true) : null;
    return is_array($j) && ($j['success'] ?? false) === true;
}

function contact_send(array $v): bool
{
    $host = strtolower(preg_replace('/:\d+$/', '', $_SERVER['SERVER_NAME'] ?? 'localhost'));
    if (!preg_match('/^[a-z0-9.-]+$/', $host) || !str_contains($host, '.')) $host = 'localhost.localdomain';
    $subject = '[What To Watch] Message from ' . $v['name'];
    $body = "Name:  {$v['name']}\nEmail: {$v['email']}\nIP:    " . contact_ip() . "\nUA:    "
        . contact_clean_line(substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200)) . "\n\n" . $v['message'] . "\n";

    if (DISABLE_CACHE) { // local development: don't send, write to an outbox log instead
        file_put_contents(contact_dir() . '/outbox.log', "--- " . date('c') . "\nTo: " . CONTACT_TO . "\nSubject: $subject\n$body\n", FILE_APPEND | LOCK_EX);
        return true;
    }
    // All header values are single-line sanitised strings, so header injection is not possible.
    $headers = [
        'From' => 'What To Watch <noreply@' . $host . '>',
        'Reply-To' => $v['email'],
        'MIME-Version' => '1.0',
        'Content-Type' => 'text/plain; charset=UTF-8',
        'X-Mailer' => 'WhatToWatch-Contact',
    ];
    $hdr = '';
    foreach ($headers as $k => $val) $hdr .= $k . ': ' . $val . "\r\n";
    return mail(CONTACT_TO, mb_encode_mimeheader($subject, 'UTF-8'), $body, rtrim($hdr), '-fnoreply@' . $host);
}
