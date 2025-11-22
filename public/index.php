<?php

    // Define your authorized username and password
    define('DASHBOARD_USER', getenv('DASHBOARD_USER') ?: 'admin');
    define('DASHBOARD_PASS', getenv('DASHBOARD_PASS') ?: 'secretpassword123');

    if (! isset($_SERVER['PHP_AUTH_USER']) || ! isset($_SERVER['PHP_AUTH_PW']) ||
        $_SERVER['PHP_AUTH_USER'] !== DASHBOARD_USER || $_SERVER['PHP_AUTH_PW'] !== DASHBOARD_PASS) {
        header('WWW-Authenticate: Basic realm="Debug Dashboard"');
        header('HTTP/1.0 401 Unauthorized');
        echo 'Authentication Required.';
        exit;
    }

    require __DIR__ . '/../vendor/autoload.php';

    define('DB_DATABASE', '/app/databases/telescope.sqlite');

    // Database Configuration
    $db = DB::instance();

    // Timezones 
    $timezones = array_filter(
        timezone_identifiers_list(),
        fn($tz) => $tz !== 'UTC'
    );
    $timezones = array_values($timezones); // reindex array
    $tzPref = $db
        ->query("SELECT value FROM preferences WHERE identifier_code = 'APP_TIMEZONE'")
        ->fetch();
    $tzPref = !! $tzPref && ! is_null($tzPref) && (
      in_array($tzPref['value'], $timezones) || $tzPref['value'] === 'UTC'
    ) ? $tzPref['value'] : 'UTC';

    // ---- select services ----
    $svcStmt = $db->query(<<<QRY
      SELECT DISTINCT svc FROM (
        SELECT json_extract(content, '$.resource.\"service.name\"') AS svc
        FROM debug_entries
      )
      WHERE svc IS NOT NULL AND svc <> ''
      ORDER BY svc
    QRY);
    $services = array_column($svcStmt->fetchAll(), 'svc');

    // ---- filtering inputs ----
    $currentType = $_GET['type'] ?? 'all'; // Used for filtering
    $conditions  = $bindings  = [];

    if (isset($_GET['q'])) {
        $conditions[] = "uuid LIKE ?";
        $bindings[]   = $_GET['q'] . '%';
    } else {
        $conditions[] = "1=1";
    }

    $sqlCond = implode(" AND ", $conditions);

    // ---- group counts ----
    $typeCounts = [];
    $countStmt  = $db->query("SELECT type, COUNT(*) as count FROM debug_entries WHERE {$sqlCond} GROUP BY type", ...$bindings);
    foreach ($countStmt->fetchAll() as $row) {
        $typeCounts[$row['type']] = $row['count'];
    }

    $conditions[] = match ($currentType) {
        'all'            => '1=1',
        'request'        => "type = 'request'",
        'client-request' => "type = 'client-request'",
        'exception'      => "type = 'exception'",
        'log'            => "type = 'log'",
        'query'          => "type = 'query'",
        'unknown'        => "type = 'unknown'",
        default          => '0=1'
    };

    // ---- select service ----
    $svc = isset($_GET['svc']) ? trim($_GET['svc']) : '';
    if ($svc !== '') {
        $conditions[] = "json_extract(content, '$.resource.\"service.name\"') = ?";
        $bindings[]   = $svc;
    }

    $sqlCond = implode(" AND ", $conditions);

    // ---- pagination inputs ----
    $page   = max(1, (int) ($_GET['page'] ?? 1));
    $per    = (int) ($_GET['per'] ?? 50);
    $per    = max(10, min($per, 200)); // clamp 10..200
    $offset = ($page - 1) * $per;

    $stmt = $db->query(<<<QRY
        SELECT COUNT(*) AS count
        FROM debug_entries
        WHERE {$sqlCond}
    QRY,...$bindings);
    $total = $stmt->fetch()['count'];

    $bindings[] = $per;
    $bindings[] = $offset;

    $stmt = $db->query(<<<QRY
        SELECT uuid, type, content, created_at
        FROM debug_entries
        WHERE {$sqlCond}
        ORDER BY created_at DESC
        LIMIT ? OFFSET ?
    QRY,...$bindings);
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
                <form method="GET" action="<?php echo $phpSelf?>" class="flex items-center gap-2">
                    <select name="svc" class="border rounded px-2 py-1 text-sm text-black">
                        <option value="">All services</option>
                        <?php foreach ($services as $name): ?>
                        <option value="<?php echo htmlspecialchars($name)?>" <?php echo $svc === $name ? 'selected' : ''?>>
                            <?php echo htmlspecialchars($name)?>
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
                    function renderSidebarLink($type, $count, $current, $params)
                    {
                        $isActive = $type == $current ? 'bg-indigo-600' : 'hover:bg-gray-800';

                        $params = array_merge($params, $type !== 'all' ? ['type' => $type] : []);
                        if ($type === 'all') {
                            unset($params['type']);
                        }
                        $url         = "/?" . http_build_query($params);
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
            <h2 class="text-3xl font-semibold mb-6 text-gray-700">Recent Entries: <?php echo ! empty($svc) ? $svc : "All Services"?></h2>
            <?php unset($params['q']);
            $phpSelf = http_build_query($params); ?>
            <form method="GET" class="mb-4 flex items-center gap-2">
                <?php foreach ($params as $k => $v): ?>
                <input type="hidden" name="<?php echo $k?>" value="<?php echo htmlspecialchars($v)?>" />
                <?php endforeach?>
                <input type="text" name="q" value="<?php echo htmlspecialchars($_GET['q'] ?? '')?>"
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
                                'request'        => $data['method'] . ' ' . $data['target'],
                                'client-request' => $data['method'] . ' ' . $data['target'],
                                'query'          => $data['sql'] . ' (Took: ' . ($data['time'] ?? 'N/A') . 'ms)',
                                'log'            => $data['message'],
                                'exception'      => $data['exception']['type'] . ': ' . $data['exception']['message'],
                                'unknown'        => $data['name'],
                                default          => 'View Details',
                            };
                            $typeClass = match ($entry['type']) {
                                'request'        => 'bg-green-100 text-green-800',
                                'client-request' => 'bg-purple-100 text-green-800',
                                'query'          => 'bg-blue-100 text-blue-800',
                                'log'            => 'bg-gray-100 text-gray-800',
                                'exception'      => 'bg-red-100 text-red-800',
                                default          => 'bg-yellow-100 text-yellow-800',
                            };
                        ?>
                        <tr class="hover:bg-indigo-50 transition-colors cursor-pointer" onclick="window.location.href='detail.php?uuid=<?php echo $entry['uuid']?>'">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full <?php echo $typeClass?>">
                                    <?php echo ucfirst($entry['type'])?>
                                </span>
                            </td>
                            <td class="px-6 py-4 text-sm text-gray-900 max-w-lg truncate"><?php echo htmlspecialchars($detail)?></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500" title="<?php echo date('Y-m-d H:i:s', strtotime($entry['created_at']))?>">
                              <?php 
                                echo (new DateTime($entry['created_at']))
                                  ->setTimeZone(new DateTimeZone($tzPref))
                                  ->format('Y-m-d H:i:s');
                              ?>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-xs font-mono text-gray-400"><?php echo $entry['uuid']?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (empty($entries)): ?>
                    <div class="p-6 text-center text-gray-500">No debug entries found. Start collecting data!</div>
                <?php endif; ?>
                <?php
                    $totalPages = $total ? (int) ceil($total / $per) : 1;
                    $hasPrev    = $page > 1;
                    $hasNext    = $page < $totalPages;
                ?>
                <div class="px-4 py-3 flex items-center justify-between border-t bg-white">
                    <div class="text-sm text-gray-600">
                        Showing <span class="font-medium"><?php echo htmlentities($fromN) ?></span>–<span class="font-medium"><?php echo htmlentities($toN) ?></span>
                        of <span class="font-medium"><?php echo number_format($total)?></span>
                    </div>
                    <div class="flex items-center space-x-2">
                        <form method="get" class="hidden md:flex items-center space-x-2">
                        <?php foreach (['type', 'q', 'from', 'to'] as $p): if (isset($_GET[$p])): ?>
	                            <input type="hidden" name="<?php echo htmlspecialchars($p)?>" value="<?php echo htmlspecialchars($_GET[$p])?>">
	                        <?php endif;endforeach; ?>
                        <label class="text-sm text-gray-600">Per page</label>
                        <select name="per" class="border rounded px-2 py-1 text-sm" onchange="this.form.submit()">
                            <?php foreach ([10, 25, 50, 100, 200] as $opt): ?>
                            <option value="<?php echo $opt?>" <?php echo $per === $opt ? 'selected' : ''?>><?php echo $opt?></option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" name="page" value="1">
                        </form>

                        <a href="<?php echo htmlentities($hasPrev ? url_with(['page' => $page - 1]) : 'javascript:void(0)') ?>"
                        class="px-3 py-1 rounded border text-sm <?php echo $hasPrev ? 'hover:bg-gray-50' : 'opacity-40 cursor-not-allowed'?>">Prev</a>
                        <span class="text-sm text-gray-600">Page <?php echo htmlentities($page) ?> / <?php echo htmlentities($totalPages) ?></span>
                        <a href="<?php echo htmlentities($hasNext ? url_with(['page' => $page + 1]) : 'javascript:void(0)') ?>"
                        class="px-3 py-1 rounded border text-sm <?php echo $hasNext ? 'hover:bg-gray-50' : 'opacity-40 cursor-not-allowed'?>">Next</a>
                    </div>
                </div>
            </div>
        </main>
    </div>
<!-- === UI Enhancements: Dark Mode & Settings Modal === -->
<style>
  :root {
    --bg: #ffffff;
    --fg: #111827;
    --muted:#6b7280;
    --btn-bg:#f3f4f6;
    --btn-fg:#111827;
    --ring: rgba(59,130,246,0.5);
    --card:#ffffff;
    --border:#e5e7eb;
  }
  :root[data-theme="dark"] {
    --bg: #0b1020;
    --fg: #e5e7eb;
    --muted:#9ca3af;
    --btn-bg:#1f2937;
    --btn-fg:#e5e7eb;
    --ring: rgba(96,165,250,0.35);
    --card:#111827;
    --border:#374151;
  }
  html, body { background: var(--bg); color: var(--fg); }
  /* Top-right toolbar */
  .ui-toolbar {
    position: fixed;
    top: 12px;
    right: 12px;
    z-index: 2147483647;
    display: inline-flex;
    gap: 8px;
    background: var(--card);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 6px;
    box-shadow: 0 6px 18px rgba(0,0,0,0.1);
    backdrop-filter: blur(6px);
  }
  .ui-btn {
    all: unset;
    cursor: pointer;
    padding: 8px 12px;
    border-radius: 10px;
    background: var(--btn-bg);
    color: var(--btn-fg);
    font: 500 14px/1 system-ui, -apple-system, Segoe UI, Roboto, "Helvetica Neue", Arial, "Noto Sans", "Apple Color Emoji","Segoe UI Emoji";
    display: inline-flex;
    align-items: center;
    gap: 8px;
    border: 1px solid var(--border);
  }
  .ui-btn:focus { outline: 2px solid var(--ring); outline-offset: 2px; }
  .ui-btn:hover { filter: brightness(1.02); }
  .ui-icon { font-size: 16px; line-height: 0; }

  /* Modal */
  .modal-backdrop {
    position: fixed; inset: 0; background: rgba(0,0,0,0.45);
    display: none; align-items: center; justify-content: center;
    z-index: 2147483646;
  }
  .modal-backdrop.show { display: flex; }
  .modal-card {
    width: min(92vw, 680px);
    background: var(--card);
    color: var(--fg);
    border: 1px solid var(--border);
    border-radius: 16px;
    box-shadow: 0 24px 48px rgba(0,0,0,0.25);
    padding: 20px;
  }
  .modal-header { display:flex; align-items:center; justify-content:space-between; margin-bottom: 12px; }
  .modal-title { font-size: 18px; font-weight: 700; }
  .modal-close { all: unset; cursor:pointer; font-size: 20px; padding: 6px; border-radius: 8px; }
  .modal-close:hover { background: var(--btn-bg); }
  .grid {
    display: grid; gap: 12px;
    grid-template-columns: repeat(12, 1fr);
  }
  .row { grid-column: span 12; display:flex; align-items:center; justify-content:space-between; padding:10px; border:1px dashed var(--border); border-radius:10px; background: color-mix(in oklab, var(--card), var(--bg) 20%); }
  .label { font-weight:600; }
  .ctrl { display:flex; align-items:center; gap:8px; }
  .select, .input, .checkbox {
    padding:8px 10px; border:1px solid var(--border); border-radius:8px; background: var(--bg); color: var(--fg);
  }
  .modal-footer { display:flex; justify-content:flex-end; gap:10px; margin-top: 16px; }
</style>

<div class="ui-toolbar" role="toolbar" aria-label="Quick actions">
  <button id="btnTheme" class="ui-btn" type="button" aria-pressed="false" title="Toggle dark mode">
    <span class="ui-icon" aria-hidden="true">🌙</span><span class="ui-label">Dark</span>
  </button>
  <button id="btnSettings" class="ui-btn" type="button" title="Open settings">
    <span class="ui-icon" aria-hidden="true">⚙️</span><span class="ui-label">Settings</span>
  </button>
</div>

<div id="settingsModal" class="modal-backdrop" aria-hidden="true" aria-label="Settings dialog">
  <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="settingsTitle">
    <div class="modal-header">
      <div id="settingsTitle" class="modal-title">Application Settings</div>
      <button class="modal-close" id="closeSettings" title="Close">✕</button>
    </div>
    <div class="grid">
      <div class="row">
        <div class="label">Preferred Timezone</div>
        <div class="ctrl">
          <select id="set-timezone" class="select">
            <option <?= $tzPref == 'UTC' ? 'selected' : '' ?> value="UTC">UTC (Default)</option>
            <?php foreach($timezones as $tz): ?>
            <option <?= $tzPref == $tz ? 'selected' : '' ?> value="<?= $tz ?>"><?= $tz ?></option>
            <?php endforeach ?>
          </select>
        </div>
      </div>
      <!--
      <div class="row">
        <div class="label">Trace retention (days)</div>
        <div class="ctrl"><input id="set-retention" class="input" type="number" min="1" max="365" step="1" placeholder="7"></div>
      </div>
      -->
      <!--
      <div class="row">
        <div class="label">Resource filter strategy</div>
        <div class="ctrl">
          <select id="set-filter-strategy" class="select">
            <option value="exact">Exact match</option>
            <option value="prefix">Prefix</option>
            <option value="regex">Regex</option>
          </select>
        </div>
      </div>
      -->
      <!--
      <div class="row">
        <div class="label">Auto-refresh interval</div>
        <div class="ctrl">
          <select id="set-refresh" class="select">
            <option value="off">Off</option>
            <option value="5">5s</option>
            <option value="15">15s</option>
            <option value="30">30s</option>
            <option value="60">60s</option>
          </select>
        </div>
      </div>
      -->
    </div>
    <div class="modal-footer">
      <button class="ui-btn" id="btnSaveSettings" type="button">Save</button>
    </div>
  </div>
</div>

<script>
(function(){
  const LS_THEME_KEY = "ui.theme";
  const LS_SETTINGS_KEY = "ui.settings";

  // ---------- Theme ----------
  const root = document.documentElement;
  const btnTheme = document.getElementById('btnTheme');
  const applyTheme = (mode) => {
    if (mode === 'dark') {
      root.setAttribute('data-theme','dark');
      btnTheme.setAttribute('aria-pressed', 'true');
      btnTheme.querySelector('.ui-icon').textContent = '☀️';
      btnTheme.querySelector('.ui-label').textContent = 'Light';
    } else {
      root.removeAttribute('data-theme');
      btnTheme.setAttribute('aria-pressed', 'false');
      btnTheme.querySelector('.ui-icon').textContent = '🌙';
      btnTheme.querySelector('.ui-label').textContent = 'Dark';
    }
  };
  const getInitialTheme = () => {
    const saved = localStorage.getItem(LS_THEME_KEY);
    if (saved === 'dark' || saved === 'light') return saved;
    // fallback to prefers-color-scheme
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
  };
  let theme = getInitialTheme();
  applyTheme(theme);

  btnTheme.addEventListener('click', () => {
    theme = theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem(LS_THEME_KEY, theme);
    applyTheme(theme);
  }, {passive:true});

  // ---------- Settings Modal ----------
  const modal = document.getElementById('settingsModal');
  const btnSettings = document.getElementById('btnSettings');
  const btnSave = document.getElementById('btnSaveSettings');
  const btnClose = document.getElementById('closeSettings');

  const $ = (id) => document.getElementById(id);
  const controls = {
    // retention: $('set-retention'),
    preferredTimezone: $('set-timezone'),
    // refresh: $('set-refresh'),
  };

  function openModal() {
    modal.classList.add('show');
    modal.setAttribute('aria-hidden','false');
  }
  function closeModal() {
    modal.classList.remove('show');
    modal.setAttribute('aria-hidden','true');
  }
  async function saveSettings() {
    const s = {
      // retention: parseInt(controls.retention.value || '7', 10),
      preferredTimezone: controls.preferredTimezone.value,
      // refresh: controls.refresh.value || 'off',
    };

    // contemplation: intend to do validation here but 
    // as a developer, it's expected that valid values
    // always can be tampered if it's referred from
    // client-sourced truth (eg: echo $timezonez)
    //
    // so, lets do validation in the api instead

    const response = await fetch('/api/settings.php', {
      method: 'POST',
      headers: {
        'content-type': 'application/x-www-form-urlencoded'
      },
      body: new URLSearchParams(s).toString()
    });

    if (response.ok) {
      return { ok: true };
    } else {
      return { ok: false, message: (await response.json()).message };
    }
  }

  btnSettings.addEventListener('click', () => { openModal(); }, {passive:true});
  btnClose.addEventListener('click', closeModal, {passive:true});
  btnSave.addEventListener('click', async (e) => {
    const {ok, message } = await saveSettings()
    if (! ok) {
      alert(`Settings could not be saved. ${message}`)
      e.stopPropagation()
      e.preventDefault()
      return
    }

    alert('Settings saved successfully. Now reloading page.')
    window.location.reload()
  }, {passive:true});
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

  // ESC to close
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && modal.classList.contains('show')) closeModal();
  });
})();
</script>
<!-- === End UI Enhancements === -->
</body>
</html>