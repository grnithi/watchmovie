<?php
/** What To Watch - contact form (emails CONTACT_TO). Defences are documented in lib/contact.php. */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/contact.php';

$ts = contact_turnstile_enabled();
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; style-src 'self' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; img-src 'self' data:; "
    . ($ts ? "script-src 'self' https://challenges.cloudflare.com; frame-src https://challenges.cloudflare.com; connect-src 'self' https://challenges.cloudflare.com; " : "script-src 'none'; ")
    . "form-action 'self'; frame-ancestors 'none'; base-uri 'self'");

$vals = ['name' => '', 'email' => '', 'message' => ''];
$errors = [];
$notice = '';
$self = strtok($_SERVER['REQUEST_URI'] ?? 'contact', '?') ?: 'contact';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    contact_gc();
    $generic = 'Sorry, we could not send your message. Please reload the page and try again.';
    $honeypot = trim((string)($_POST['website'] ?? ''));
    if (!contact_same_origin() || (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 20000) {
        http_response_code(403);
        $notice = $generic;
    } elseif ($honeypot !== '') {
        // Bot filled the hidden field: pretend success so it learns nothing, send nothing.
        header('Location: ' . $self . '?sent=1', true, 303);
        exit;
    } elseif (($why = contact_check_token((string)($_POST['t'] ?? ''))) !== null) {
        $notice = $why === 'fast' ? 'That was quick! Please wait a moment and press Send again.'
            : ($why === 'replay' ? 'This form was already submitted.' : $generic);
    } elseif ($ts && !contact_turnstile_ok((string)($_POST['cf-turnstile-response'] ?? ''))) {
        $notice = 'Please complete the verification and try again.';
    } else {
        [$vals, $errors] = contact_validate($_POST);
        if (!$errors) {
            $ip = contact_ip();
            if (!contact_rate_ok("ip-h:$ip", CONTACT_IP_PER_HOUR, 3600)
                || !contact_rate_ok("ip-d:$ip", CONTACT_IP_PER_DAY, 86400)
                || !contact_rate_ok('global-d', CONTACT_GLOBAL_PER_DAY, 86400)) {
                http_response_code(429);
                $notice = 'Too many messages right now. Please try again later.';
            } elseif (contact_send($vals)) {
                header('Location: ' . $self . '?sent=1', true, 303);
                exit;
            } else {
                http_response_code(500);
                $notice = 'Sorry, the message could not be delivered. Please try again later.';
            }
        }
    }
}
$sent = $_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['sent']);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <base href="<?= $h(rtrim(dirname($_SERVER['SCRIPT_NAME']), '/') . '/') ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="theme-color" content="#0b0d14">
    <meta name="robots" content="noindex">
    <link rel="icon" type="image/png" href="assets/icons/icon-192.png">
    <title>Contact | What To Watch</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Plus+Jakarta+Sans:wght@700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= DISABLE_CACHE ? time() : filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <?php if ($ts): ?><script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script><?php endif; ?>
</head>
<body>
    <header class="topbar">
        <div class="container topbar-inner">
            <a class="brand" href="./" aria-label="What To Watch">
                <span class="brand-mark">▶</span>
                <span class="brand-name">What&nbsp;To&nbsp;Watch</span>
            </a>
        </div>
    </header>

    <main class="container main contact">
        <h1>Contact us</h1>
        <?php if ($sent): ?>
            <p class="contact-ok" role="status">Thanks! Your message has been sent. We'll get back to you soon.</p>
            <p><a class="footer-link" href="./">← Back to releases</a></p>
        <?php else: ?>
            <p class="hero-sub">Questions, feedback or a missing title? Send us a note.</p>
            <?php if ($notice): ?><p class="contact-err" role="alert"><?= $h($notice) ?></p><?php endif; ?>
            <form method="post" action="<?= $h($self) ?>" class="contact-form" novalidate>
                <label>Name
                    <input name="name" type="text" maxlength="80" required autocomplete="name" value="<?= $h($vals['name']) ?>">
                    <?php if (isset($errors['name'])): ?><span class="contact-err"><?= $h($errors['name']) ?></span><?php endif; ?>
                </label>
                <label>Email
                    <input name="email" type="email" maxlength="254" required autocomplete="email" value="<?= $h($vals['email']) ?>">
                    <?php if (isset($errors['email'])): ?><span class="contact-err"><?= $h($errors['email']) ?></span><?php endif; ?>
                </label>
                <label>Message
                    <textarea name="message" rows="6" maxlength="2000" required><?= $h($vals['message']) ?></textarea>
                    <?php if (isset($errors['message'])): ?><span class="contact-err"><?= $h($errors['message']) ?></span><?php endif; ?>
                </label>
                <div class="contact-hp" aria-hidden="true">
                    <label>Leave this field empty <input name="website" type="text" tabindex="-1" autocomplete="off"></label>
                </div>
                <input type="hidden" name="t" value="<?= $h(contact_token()) ?>">
                <?php if ($ts): ?><div class="cf-turnstile" data-sitekey="<?= $h(TURNSTILE_SITE_KEY) ?>" data-theme="dark"></div><?php endif; ?>
                <button type="submit" class="install-btn contact-send">Send message</button>
            </form>
        <?php endif; ?>
    </main>

    <footer class="footer container">
        <a class="footer-link" href="./">← Back to What To Watch</a>
    </footer>
</body>
</html>
