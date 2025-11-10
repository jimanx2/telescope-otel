<?php

// Define your authorized username and password
define('DASHBOARD_USER', getenv('DASHBOARD_USER') ?: 'admin');
define('DASHBOARD_PASS', getenv('DASHBOARD_PASS') ?: 'secretpassword123');

if (!isset($_SERVER['PHP_AUTH_USER']) || !isset($_SERVER['PHP_AUTH_PW']) ||
    $_SERVER['PHP_AUTH_USER'] !== DASHBOARD_USER || $_SERVER['PHP_AUTH_PW'] !== DASHBOARD_PASS) {
    header('WWW-Authenticate: Basic realm="Debug Dashboard"');
    header('HTTP/1.0 401 Unauthorized');
    echo 'Authentication Required.';
    exit;
}

require __DIR__.'/../vendor/autoload.php';

define('DB_DATABASE', '/app/databases/telescope.sqlite');

// Database Configuration
$db = DB::instance();

// ---- select services ----
$svcStmt = $db->query("
  SELECT DISTINCT svc FROM (
    SELECT json_extract(content, '$.resource.\"service.name\"') AS svc
    FROM debug_entries
  )
  WHERE svc IS NOT NULL AND svc <> ''
  ORDER BY svc
");
$services = array_column($svcStmt->fetchAll(), 'svc');

// ---- filtering inputs ----
$currentType = $_GET['type'] ?? 'all'; // Used for filtering
$conditions = $bindings = [];

if (isset($_GET['q'])) {
    $conditions[] = "uuid LIKE ?";
    $bindings[] = $_GET['q'] . '%';
} else {
    $conditions[] = "1=1";
}

$sqlCond = implode(" AND ", $conditions);

// ---- group counts ----
$typeCounts = [];
$countStmt = $db->query("SELECT type, COUNT(*) as count FROM debug_entries WHERE {$sqlCond} GROUP BY type", ...$bindings);
foreach ($countStmt->fetchAll() as $row) {
    $typeCounts[$row['type']] = $row['count'];
}

$conditions[] = match($currentType) {
    'all' => '1=1',
    'request' => "type = 'request'",
    'client-request' => "type = 'client-request'",
    'exception' => "type = 'exception'",
    'log' => "type = 'log'",
    'query' => "type = 'query'",
    'unknown' => "type = 'unknown'",
    default => '0=1'
};

// ---- select service ----
$svc = isset($_GET['svc']) ? trim($_GET['svc']) : '';
if ($svc !== '') {
    $conditions[] = "json_extract(content, '$.resource.\"service.name\"') = ?";
    $bindings[] = $svc;
}

$sqlCond = implode(" AND ", $conditions);

// ---- pagination inputs ----
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = (int)($_GET['per']  ?? 50);
$per  = max(10, min($per, 200)); // clamp 10..200
$offset = ($page - 1) * $per;

$stmt = $db->query(<<<QRY
    SELECT COUNT(*) AS count
    FROM debug_entries 
    WHERE {$sqlCond} 
QRY, ...$bindings);
$total = $stmt->fetch()['count'];

$bindings[] = $per;
$bindings[] = $offset;

$stmt = $db->query(<<<QRY
    SELECT uuid, type, content, created_at 
    FROM debug_entries 
    WHERE {$sqlCond} 
    ORDER BY created_at DESC
    LIMIT ? OFFSET ?
QRY, ...$bindings);
$entries = $stmt->fetchAll();

// ---- compute page ranges ----
$fromN = $total ? ($offset + 1) : 0;
$toN   = min($offset + $per, $total);

parse_str($_SERVER['QUERY_STRING'] ?? '', $params);
$phpSelf = http_build_query($params);

function url_with($attrs)
{
    global $params;

    return "/?" . http_build_query(array_merge($params, $attrs ?? []));
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Debug Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        /* Custom scrollbar style for the main content */
        .content-area::-webkit-scrollbar { width: 4px; }
        .content-area::-webkit-scrollbar-thumb { background-color: #6366f1; border-radius: 2px; }
    </style>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen antialiased text-gray-800">
        <div class="flex flex-col w-64 bg-gray-900 text-white p-4">
            <h1 class="text-2xl font-bold mt-4 mb-8 text-indigo-400">Debug Dashboard</h1>
            <div class="flex items-center gap-3 mb-4">
                <form method="GET" action="<?= $phpSelf ?>" class="flex items-center gap-2">
                    <select name="svc" class="border rounded px-2 py-1 text-sm text-black">
                        <option value="">All services</option>
                        <?php foreach ($services as $name): ?>
                        <option value="<?= htmlspecialchars($name) ?>" <?= $svc === $name ? 'selected' : '' ?>>
                            <?= htmlspecialchars($name) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit"
                        class="px-2 py-1 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                        Go
                    </button>
                </form>
            </div>
            <ul class="space-y-2 mt-1">
                <?php
                // Function to generate sidebar links
                function renderSidebarLink($type, $count, $current, $params) {
                    $isActive = $type == $current ? 'bg-indigo-600' : 'hover:bg-gray-800';

                    $params = array_merge($params, $type !== 'all' ? ['type' => $type] : []);
                    if ($type === 'all') {
                        unset($params['type']);
                    }
                    $url = "/?" . http_build_query($params);
                    $typeDisplay = ucfirst($type);
                    echo <<<HTML
                    <li>
                        <a href="{$url}" class="flex items-center justify-between p-2 rounded-lg {$isActive} transition-colors">
                            <span>{$typeDisplay}</span>
                            <span class="px-2 text-xs font-semibold text-gray-100 bg-gray-700 rounded-full">{$count}</span>
                        </a>
                    </li>
                    HTML;
                }

                // Render Links
                $totalCount = array_sum($typeCounts);
                renderSidebarLink('all', $totalCount, $currentType, $params);

                foreach ($typeCounts as $type => $count) {
                    renderSidebarLink($type, $count, $currentType, $params);
                }
                ?>
            </ul>
        </div>

        <main class="flex-1 overflow-y-auto p-8 content-area">
            <h2 class="text-3xl font-semibold mb-6 text-gray-700">Recent Entries: <?= ! empty($svc) ? $svc : "All Services" ?></h2>
            <?php unset($params['q']); $phpSelf = http_build_query($params); ?>
            <form method="GET" class="mb-4 flex items-center gap-2">
                <?php foreach($params as $k => $v): ?>
                <input type="hidden" name="<?= $k ?>" value="<?= htmlspecialchars($v) ?>" />
                <?php endforeach ?>
                <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>"
                    placeholder="Search by UUID (left%)" 
                    class="flex-1 px-3 py-2 border rounded-lg focus:outline-none focus:ring-2 focus:ring-indigo-400">
                <button type="submit"
                    class="px-4 py-2 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 transition-colors">
                    Search
                </button>
            </form>
            <div class="bg-white shadow-lg rounded-lg overflow-hidden">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-indigo-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Type</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Details</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Time</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">UUID</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <?php foreach ($entries as $entry): ?>
                        <?php
                            $data = json_decode($entry['content'], true);
                            // Simple way to display content based on type
                            $detail = match ($entry['type']) {
                                'request' => $data['method'] . ' ' . $data['target'],
                                'client-request' => $data['method'] . ' ' . $data['target'],
                                'query' => $data['sql'] . ' (Took: ' . ($data['time'] ?? 'N/A') . 'ms)',
                                'log' => $data['message'],
                                'exception' => $data['exception']['type'] . ': ' . $data['exception']['message'],
                                'unknown' => $data['name'],
                                default => 'View Details',
                            };
                            $typeClass = match ($entry['type']) {
                                'request' => 'bg-green-100 text-green-800',
                                'client-request' => 'bg-purple-100 text-green-800',
                                'query' => 'bg-blue-100 text-blue-800',
                                'log' => 'bg-gray-100 text-gray-800',
                                'exception' => 'bg-red-100 text-red-800',
                                default => 'bg-yellow-100 text-yellow-800',
                            };
                        ?>
                        <tr class="hover:bg-indigo-50 transition-colors cursor-pointer" onclick="window.location.href='detail.php?uuid=<?= $entry['uuid'] ?>'">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?= $typeClass ?>">
                                    <?= ucfirst($entry['type']) ?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 max-w-lg truncate"><?= htmlspecialchars($detail) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" title="<?= date('Y-m-d H:i:s', strtotime($entry['created_at'])) ?>"><?= date('H:i:s', strtotime($entry['created_at'])) ?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-gray-400"><?= $entry['uuid'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (empty($entries)): ?>
                    <div class="p-6 text-center text-gray-500">No debug entries found. Start collecting data!</div>
                <?php endif; ?>
                <?php
                    $totalPages = $total ? (int)ceil($total / $per) : 1;
                    $hasPrev = $page > 1;
                    $hasNext = $page < $totalPages;
                ?>
                <div class="px-4 py-3 flex items-center justify-between border-t bg-white">
                    <div class="text-sm text-gray-600">
                        Showing <span class="font-medium"><?= $fromN ?></span>–<span class="font-medium"><?= $toN ?></span>
                        of <span class="font-medium"><?= number_format($total) ?></span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <form method="get" class="hidden md:flex items-center space-x-2">
                        <?php foreach (['type','q','from','to'] as $p): if(isset($_GET[$p])): ?>
                            <input type="hidden" name="<?= htmlspecialchars($p) ?>" value="<?= htmlspecialchars($_GET[$p]) ?>">
                        <?php endif; endforeach; ?>
                        <label class="text-sm text-gray-600">Per page</label>
                        <select name="per" class="border rounded px-2 py-1 text-sm" onchange="this.form.submit()">
                            <?php foreach ([10,25,50,100,200] as $opt): ?>
                            <option value="<?= $opt ?>" <?= $per===$opt ? 'selected' : '' ?>><?= $opt ?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="page" value="1">
                        </form>

                        <a href="<?= $hasPrev ? url_with(['page'=>$page-1]) : 'javascript:void(0)' ?>"
                        class="px-3 py-1 rounded border text-sm <?= $hasPrev ? 'hover:bg-gray-50' : 'opacity-40 cursor-not-allowed' ?>">Prev</a>
                        <span class="text-sm text-gray-600">Page <?= $page ?> / <?= $totalPages ?></span>
                        <a href="<?= $hasNext ? url_with(['page'=>$page+1]) : 'javascript:void(0)' ?>"
                        class="px-3 py-1 rounded border text-sm <?= $hasNext ? 'hover:bg-gray-50' : 'opacity-40 cursor-not-allowed' ?>">Next</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
</body>
</html>