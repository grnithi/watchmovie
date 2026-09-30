<?php
/**
 * What To Watch — Visitor Stats (ported from exchange-car).
 * If STATS_KEY is defined in secrets.php, open as /stats.php?key=YOUR_KEY.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/traffic.php';
if (defined('STATS_KEY') && STATS_KEY !== '' && !hash_equals(STATS_KEY, (string)($_GET['key'] ?? ''))) {
    http_response_code(404);
    exit('Not found');
}
$keyQs = defined('STATS_KEY') && STATS_KEY !== '' ? '&key=' . urlencode(STATS_KEY) : '';

$logFile = __DIR__ . '/cache/traffic_visits.json';

// Option to filter bot traffic
$showAll = isset($_GET['all']) && $_GET['all'] === '1';

$entries = [];
if (file_exists($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines) {
        foreach ($lines as $line) {
            $data = json_decode($line, true);
            if ($data) {
                // Apply bot filtering if not showing all
                if (!$showAll && !empty($data['is_bot'])) {
                    continue;
                }
                $entries[] = $data;
            }
        }
    }
}

// Compute Metrics
$now = time();
$todayStr = date('Y-m-d');
$currentMonthStr = date('Y-m');
$currentYearStr = date('Y');
$sevenDaysAgoTs = strtotime('-7 days');

$todayCount = 0;
$weekCount = 0;
$monthCount = 0;
$yearCount = 0;

$pageCategoryCounts = [];
$dailyCounts = [];

// Initialize last 30 days array
for ($i = 29; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $dailyCounts[$d] = 0;
}

foreach ($entries as $e) {
    $eDate = $e['date'] ?? date('Y-m-d', $e['timestamp'] ?? 0);
    $eTs = $e['timestamp'] ?? strtotime($e['time'] ?? 'now');
    $category = $e['category'] ?? 'Other';

    // Summary counts
    if ($eDate === $todayStr) {
        $todayCount++;
    }
    if ($eTs >= $sevenDaysAgoTs) {
        $weekCount++;
    }
    if (strpos($eDate, $currentMonthStr) === 0) {
        $monthCount++;
    }
    if (strpos($eDate, $currentYearStr) === 0) {
        $yearCount++;
    }

    // Category breakdown
    $pageCategoryCounts[$category] = ($pageCategoryCounts[$category] ?? 0) + 1;

    // Daily 30-day bucket
    if (isset($dailyCounts[$eDate])) {
        $dailyCounts[$eDate]++;
    }
}

// Sort categories descending
arsort($pageCategoryCounts);
$maxCategoryCount = !empty($pageCategoryCounts) ? max($pageCategoryCounts) : 1;

// Find max for 30-day chart scaling
$maxDailyHits = !empty($dailyCounts) ? max($dailyCounts) : 1;
if ($maxDailyHits < 10) {
    $maxDailyHits = 10;
}

// Recent visitors: last 100 entries, newest first
$recentVisitors = array_slice(array_reverse($entries), 0, 100);

// Fill in countries the log couldn't know (no CDN header) via cached IP lookup.
$geo = traffic_geo_resolve(array_column(array_filter($recentVisitors, fn($v) => ($v['country'] ?? 'Unknown') === 'Unknown'), 'ip'));
foreach ($recentVisitors as &$v) {
    if (($v['country'] ?? 'Unknown') === 'Unknown' && isset($geo[$v['ip'] ?? ''])) {
        $v['country'] = $geo[$v['ip']]['name'];
        $v['flag'] = $geo[$v['ip']]['flag'];
    }
}
unset($v);

$categoryColors = [
    'Home'          => 'bg-blue-500',
    'USA'           => 'bg-indigo-500',
    'India'         => 'bg-orange-500',
    'Installed App' => 'bg-emerald-500',
];

$categoryBadgeColors = [
    'Home'          => 'bg-blue-50 text-blue-700',
    'USA'           => 'bg-indigo-50 text-indigo-700',
    'India'         => 'bg-orange-50 text-orange-700',
    'Installed App' => 'bg-emerald-50 text-emerald-700',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="robots" content="noindex, nofollow" />
    <title>What To Watch — Visitor Stats</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 min-h-screen p-6 font-sans">
<div class="max-w-6xl mx-auto">

    <div class="mb-8 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-3xl font-bold text-gray-900 flex items-center gap-2">
                <span>🎬</span>
                <span>What To Watch — Visitor Stats</span>
            </h1>
            <p class="text-gray-500 mt-1 text-sm">
                <?= $showAll ? 'All page views (including bots)' : 'Human page views only' ?> · as of <?= date('F j, Y, g:i a') ?>
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="?all=<?= $showAll ? '0' : '1' ?><?= $keyQs ?>" class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 bg-white text-gray-700 hover:bg-gray-50 shadow-sm transition">
                <?= $showAll ? '✓ Showing All (Switch to Human Only)' : 'Filter: Human Only (Show Bots)' ?>
            </a>
            <button onclick="window.location.reload()" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-blue-600 text-white hover:bg-blue-700 shadow-sm transition">
                ↻ Refresh
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
        <div class="bg-white rounded-xl shadow-sm p-5 text-center border border-gray-100">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">Today</p>
            <p class="text-4xl font-black text-blue-600 mt-1"><?= number_format($todayCount) ?></p>
            <p class="text-xs text-gray-400 mt-1"><?= date('M j, Y') ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-5 text-center border border-gray-100">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">This Week</p>
            <p class="text-4xl font-black text-green-600 mt-1"><?= number_format($weekCount) ?></p>
            <p class="text-xs text-gray-400 mt-1">Last 7 days</p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-5 text-center border border-gray-100">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">This Month</p>
            <p class="text-4xl font-black text-purple-600 mt-1"><?= number_format($monthCount) ?></p>
            <p class="text-xs text-gray-400 mt-1"><?= date('F Y') ?></p>
        </div>
        <div class="bg-white rounded-xl shadow-sm p-5 text-center border border-gray-100">
            <p class="text-sm font-medium text-gray-500 uppercase tracking-wide">This Year</p>
            <p class="text-4xl font-black text-orange-500 mt-1"><?= number_format($yearCount) ?></p>
            <p class="text-xs text-gray-400 mt-1"><?= date('Y') ?></p>
        </div>
    </div>

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-8">

        <!-- Page Category Breakdown -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
            <h2 class="text-lg font-bold text-gray-800 mb-5">Views by Page / Source</h2>
            <?php if (empty($pageCategoryCounts)): ?>
                <p class="text-sm text-gray-400 italic py-6 text-center">No traffic recorded yet today.</p>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($pageCategoryCounts as $cat => $count): 
                        $pct = round(($count / $maxCategoryCount) * 100);
                        $barColor = $categoryColors[$cat] ?? 'bg-blue-500';
                    ?>
                        <div class="flex items-center gap-3">
                            <div class="w-24 text-sm font-medium text-gray-700 text-right shrink-0 truncate" title="<?= htmlspecialchars($cat) ?>">
                                <?= htmlspecialchars($cat) ?>
                            </div>
                            <div class="flex-1 bg-gray-100 rounded-full h-5 overflow-hidden">
                                <div class="<?= $barColor ?> h-5 rounded-full transition-all duration-500" style="width:<?= max(4, $pct) ?>%"></div>
                            </div>
                            <div class="w-12 text-sm font-bold text-gray-800 text-right shrink-0">
                                <?= number_format($count) ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Last 30 Days Bar Chart -->
        <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100 flex flex-col justify-between">
            <div>
                <h2 class="text-lg font-bold text-gray-800 mb-4">Last 30 Days</h2>
            </div>
            <div class="flex items-end gap-1" style="height:140px;">
                <?php foreach ($dailyCounts as $dateKey => $cnt): 
                    $heightPct = round(($cnt / $maxDailyHits) * 120);
                    $heightPx = max(4, $heightPct);
                    $isToday = ($dateKey === $todayStr);
                    $dayNum = date('j', strtotime($dateKey));
                ?>
                    <div class="flex-1 flex flex-col justify-end items-center group relative" style="height:140px;">
                        <div class="absolute bottom-full mb-1 left-1/2 -translate-x-1/2 bg-gray-900 text-white text-xs rounded px-2 py-1 opacity-0 group-hover:opacity-100 whitespace-nowrap z-20 pointer-events-none shadow-md transition-opacity">
                            <?= $dateKey ?>: <?= number_format($cnt) ?> views
                        </div>
                        <div class="w-full <?= $isToday ? 'bg-blue-600' : 'bg-blue-200 group-hover:bg-blue-400' ?> rounded-sm transition-colors" style="height:<?= $heightPx ?>px"></div>
                        <?php if ($dayNum == 1 || $dayNum % 7 == 0 || $isToday): ?>
                            <span class="text-[8px] text-gray-400 mt-1"><?= $dayNum ?></span>
                        <?php else: ?>
                            <span class="text-[8px] text-transparent mt-1">.</span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="flex justify-between text-xs text-gray-400 pt-2 border-t border-gray-100 mt-2">
                <span>30 days ago</span>
                <span>Today</span>
            </div>
        </div>

    </div>

    <!-- Recent Visitors Table -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 overflow-hidden">
        <div class="flex items-center justify-between mb-4">
            <h2 class="text-lg font-bold text-gray-800">Recent Visitors</h2>
            <span class="text-xs text-gray-400">Showing last <?= count($recentVisitors) ?> hits</span>
        </div>

        <?php if (empty($recentVisitors)): ?>
            <p class="text-sm text-gray-400 italic py-6 text-center">No visits recorded yet. Open the site to generate records.</p>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm border-collapse">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-400 text-xs uppercase tracking-wider">
                            <th class="py-2.5 pr-4 font-semibold">Timestamp</th>
                            <th class="py-2.5 pr-4 font-semibold">Page</th>
                            <th class="py-2.5 pr-4 font-semibold">Country</th>
                            <th class="py-2.5 pr-4 font-semibold">IP Address</th>
                            <th class="py-2.5 pr-4 font-semibold">Referrer</th>
                            <th class="py-2.5 font-semibold">User Agent</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100 text-xs">
                        <?php foreach ($recentVisitors as $v): 
                            $cat = $v['category'] ?? 'Other';
                            $badgeCls = $categoryBadgeColors[$cat] ?? 'bg-slate-100 text-slate-700';
                        ?>
                            <tr class="hover:bg-gray-50 transition-colors">
                                <td class="py-2.5 pr-4 text-gray-500 whitespace-nowrap">
                                    <?= htmlspecialchars($v['time'] ?? '') ?>
                                </td>
                                <td class="py-2.5 pr-4">
                                    <span class="inline-block px-2 py-0.5 rounded font-bold <?= $badgeCls ?>">
                                        <?= htmlspecialchars($v['page'] ?? '') ?>
                                    </span>
                                </td>
                                <td class="py-2.5 pr-4 whitespace-nowrap">
                                    <span class="text-sm mr-1"><?= $v['flag'] ?? '🌐' ?></span>
                                    <span class="text-gray-700"><?= htmlspecialchars($v['country'] ?? 'Unknown') ?></span>
                                </td>
                                <td class="py-2.5 pr-4 font-mono text-gray-600">
                                    <?= htmlspecialchars($v['ip'] ?? '') ?>
                                </td>
                                <td class="py-2.5 pr-4 text-gray-500 max-w-xs truncate" title="<?= htmlspecialchars($v['ref'] ?? '') ?>">
                                    <?php if (empty($v['ref']) || $v['ref'] === 'direct'): ?>
                                        <span class="text-gray-400">direct</span>
                                    <?php else: ?>
                                        <span class="text-blue-600 hover:underline"><?= htmlspecialchars($v['ref']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-2.5 text-gray-400 max-w-xs truncate" title="<?= htmlspecialchars($v['ua'] ?? '') ?>">
                                    <?= htmlspecialchars($v['ua'] ?? 'Unknown') ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <div class="text-center text-xs text-gray-400 mt-8">
        What To Watch Traffic Analyzer · Data stored in cache/traffic_visits.json
    </div>

</div>
</body>
</html>
