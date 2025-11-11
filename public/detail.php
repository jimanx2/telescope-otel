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

$tzPref = $db
    ->query("SELECT value FROM preferences WHERE identifier_code = 'APP_TIMEZONE'")
    ->fetch();
$tzPref = !! $tzPref && ! is_null($tzPref) ? $tzPref['value'] : 'UTC';

// --- Fetch Single Entry ---
$selectedUuid = $_GET['uuid'] ?? null;

if (!$selectedUuid) {
    header('Location: /'); // Redirect if no UUID is provided
    exit;
}

$stmt = $db->query("SELECT uuid, type, content, created_at FROM debug_entries WHERE uuid = ? LIMIT 1", $selectedUuid);
$entryDetail = $stmt->fetch();

if (!$entryDetail) {
    die("Entry not found.");
}

$data = json_decode($entryDetail['content'], true);

$data['attributes'] = $data['attributes'] + [
    'app.local_time' => (new DateTime($entryDetail['created_at']))->setTimeZone(new DateTimeZone($tzPref))->format('Y-m-d H:i:s O'),
    'app.timezone'   => $tzPref
];

$prettyJson = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

// Helper function for color coding
$typeClass = match ($entryDetail['type']) {
    'request' => 'bg-green-100 text-green-800',
    'query' => 'bg-blue-100 text-blue-800',
    'log' => 'bg-gray-100 text-gray-800',
    'exception' => 'bg-red-100 text-red-800',
    default => 'bg-yellow-100 text-yellow-800',
};

$typeCounts = [];
$typeUuids = [];
$currentType = $_GET['type'] ?? 'all'; // Used for filtering
$bindings = [explode('-', $selectedUuid)[0] . '%'];
$countStmt = $db->query(<<<CTE
    SELECT type, uuid, COUNT(*) as count 
    FROM debug_entries 
    WHERE uuid LIKE ?
    GROUP BY type
    ORDER BY created_at
CTE, ...$bindings);
foreach ($countStmt->fetchAll() as $row) {
    $typeCounts[$row['type']] = $row['count'];
    $typeUuids[$row['type']] = $row['uuid'];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Detail: <?= ucfirst($entryDetail['type']) ?> - Debug Dashboard</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-50">
    <div class="flex h-screen antialiased text-gray-800">
        <div class="flex flex-col w-64 bg-gray-900 text-white p-4">
            <h1 class="text-2xl font-bold mb-6 text-indigo-400">Debug Dashboard</h1>
            <a href="/" class="text-indigo-400 hover:text-indigo-200 pb-2">
                &larr; Back to Dashboard
            </a>
            <ul class="space-y-2">
                <?php
                // Function to generate sidebar links
                function renderSidebarLink($type, $count, $current, $uuid, $typeUuids) {
                    $isActive = $type == $current ? 'bg-indigo-600' : 'hover:bg-gray-800';

                    $url = $type === 'all' ? '/?q='.$uuid : '/?q='. $uuid.'&type=' . $type;
                    if ($count == 1) {
                        $url = '/detail.php?uuid='.$typeUuids[$type];
                    } 

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
                foreach ($typeCounts as $type => $count) {
                    renderSidebarLink($type, $count, $currentType, explode('-', $selectedUuid)[0], $typeUuids);
                }
                ?>
            </ul>
        </div>

        <main class="flex-1 overflow-y-auto p-8 content-area">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-3xl font-semibold text-gray-700">Entry Details: <?= ucfirst($entryDetail['type']) ?></h2>
            </div>

            <div class="bg-white shadow-lg rounded-lg p-6">
                <div class="flex items-center space-x-4 mb-4 pb-4 border-b border-gray-100">
                    <span class="px-3 py-1 inline-flex text-sm leading-5 font-bold rounded-full <?= $typeClass ?>">
                        <?= strtoupper($entryDetail['type']) ?>
                    </span>
                    <span class="text-gray-500 text-sm">
                        <strong>Time:</strong> <?= $data['attributes']['app.local_time'] ?>
                    </span>
                    <span class="text-gray-500 text-xs font-mono">
                        <strong>UUID:</strong> <?= $entryDetail['uuid'] ?>
                    </span>
                </div>

                <h3 class="text-xl font-medium mb-3 mt-4 text-gray-700">Full Payload (JSON):</h3>
                
                <pre class="bg-gray-800 text-gray-50 p-4 rounded-lg text-sm overflow-x-auto" style="max-height: 60vh;"><?= htmlspecialchars($prettyJson) ?></pre>
            </div>
        </main>
    </div>
</body>
</html>