<?php
/**
 * Open Graph / Twitter card data for link previews (WhatsApp, Facebook...).
 * Picks the most popular Indian movie with a backdrop from the weekly feed cache; falls back to a static banner.
 */
function og_pick_movie(): ?array
{
    $files = glob(__DIR__ . '/../cache/feed/*_movie_IN_*_v2.json') ?: [];
    usort($files, fn($a, $b) => filemtime($b) <=> filemtime($a));
    foreach ($files as $f) {
        $data = json_decode((string)@file_get_contents($f), true);
        $best = null;
        foreach (($data['releases'] ?? []) as $r) {
            if (empty($r['backdrop']) || !in_array($r['language_code'] ?? '', ['ta', 'te', 'hi', 'ml', 'kn'], true)) continue;
            if ($best === null || $r['popularity'] > $best['popularity']) $best = $r;
        }
        if ($best) return $best;
    }
    return null;
}

function og_meta(string $baseUrl): string
{
    $movie = og_pick_movie();
    $title = 'Bored this weekend? 🍿 Find your next watch';
    $desc = 'Movies & shows for date night or family time, on OTT and in theatres.';
    $image = $baseUrl . 'assets/icons/og-default.png';
    if ($movie) {
        $image = str_replace('/t/p/w780/', '/t/p/w1280/', $movie['backdrop']);
        $desc .= " Now: {$movie['title']} ({$movie['language']}).";
    }
    $e = fn($s) => htmlspecialchars($s, ENT_QUOTES);
    return implode("\n    ", [
        '<meta property="og:type" content="website">',
        '<meta property="og:site_name" content="What To Watch">',
        '<meta property="og:title" content="' . $e($title) . '">',
        '<meta property="og:description" content="' . $e($desc) . '">',
        '<meta property="og:url" content="' . $e($baseUrl) . '">',
        '<meta property="og:image" content="' . $e($image) . '">',
        '<meta property="og:image:width" content="1200">',
        '<meta property="og:image:height" content="630">',
        '<meta name="twitter:card" content="summary_large_image">',
        '<meta name="twitter:title" content="' . $e($title) . '">',
        '<meta name="twitter:image" content="' . $e($image) . '">',
    ]);
}
