<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

function dttd_spotify_table_exists($table) {
    static $cache = [];
    $table = (string)$table;
    if (isset($cache[$table])) {
        return $cache[$table];
    }

    try {
        $stmt = db()->prepare("SHOW TABLES LIKE ?");
        $stmt->execute([$table]);
        $cache[$table] = (bool)$stmt->fetch();
    } catch (Throwable $e) {
        $cache[$table] = false;
    }

    return $cache[$table];
}

function dttd_spotify_player_nodes() {
    if (!dttd_spotify_table_exists('player_nodes')) {
        return [];
    }

    try {
        return db()->query("
            SELECT *,
                CASE
                    WHEN last_seen IS NULL THEN 'offline'
                    WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 45 SECOND) THEN 'online'
                    WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 90 SECOND) THEN 'warning'
                    ELSE 'offline'
                END AS live_status,
                TIMESTAMPDIFF(SECOND, last_seen, NOW()) AS seconds_since_seen
            FROM player_nodes
            ORDER BY
                CASE
                    WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 45 SECOND) THEN 0
                    WHEN last_seen >= DATE_SUB(NOW(), INTERVAL 90 SECOND) THEN 1
                    ELSE 2
                END,
                COALESCE(display_name, hostname, node_key) ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_spotify_recent_node_commands($limit = 8) {
    if (!dttd_spotify_table_exists('node_commands')) {
        return [];
    }

    $limit = max(1, min(20, (int)$limit));

    try {
        return db()->query("
            SELECT c.*, n.display_name, n.hostname
            FROM node_commands c
            LEFT JOIN player_nodes n ON n.node_key = c.node_key
            ORDER BY c.created_at DESC, c.id DESC
            LIMIT $limit
        ")->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_spotify_node_label($node) {
    $label = trim((string)($node['display_name'] ?? ''));
    if ($label === '') {
        $label = trim((string)($node['hostname'] ?? ''));
    }
    if ($label === '') {
        $label = trim((string)($node['node_key'] ?? ''));
    }
    return $label !== '' ? $label : 'Unnamed node';
}

function dttd_spotify_last_seen_label($node) {
    if (empty($node['last_seen'])) {
        return 'Never seen';
    }

    $seconds = isset($node['seconds_since_seen']) ? (int)$node['seconds_since_seen'] : null;
    if ($seconds !== null && $seconds >= 0) {
        if ($seconds < 60) {
            return $seconds . ' sec ago';
        }
        if ($seconds < 3600) {
            return floor($seconds / 60) . ' min ago';
        }
        if ($seconds < 86400) {
            return floor($seconds / 3600) . ' hr ago';
        }
    }

    return date('d M Y H:i', strtotime((string)$node['last_seen']));
}

function dttd_spotify_command_label($command) {
    $labels = [
        'restart_raspotify' => 'Restart Spotify',
        'restart_agent' => 'Restart Agent',
        'reboot' => 'Reboot Node',
    ];
    return $labels[$command] ?? $command;
}

$flash = $_SESSION['spotify_flash'] ?? '';
unset($_SESSION['spotify_flash']);

$nodeFlash = '';
$nodeError = '';

$allowedNodeCommands = [
    'restart_raspotify',
    'restart_agent',
    'reboot',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['node_action'] ?? '') === 'send_command') {
    $nodeKey = trim((string)($_POST['node_key'] ?? ''));
    $command = trim((string)($_POST['command'] ?? ''));

    if (!dttd_spotify_table_exists('player_nodes') || !dttd_spotify_table_exists('node_commands')) {
        $nodeError = 'Player node tables are not available yet.';
    } elseif ($nodeKey === '' || !in_array($command, $allowedNodeCommands, true)) {
        $nodeError = 'Invalid player node command.';
    } else {
        try {
            $check = db()->prepare("SELECT node_key, display_name, hostname FROM player_nodes WHERE node_key = ? LIMIT 1");
            $check->execute([$nodeKey]);
            $node = $check->fetch();

            if (!$node) {
                $nodeError = 'Player node was not found.';
            } else {
                $stmt = db()->prepare("
                    INSERT INTO node_commands (node_key, command, payload, status)
                    VALUES (?, ?, NULL, 'pending')
                ");
                $stmt->execute([$nodeKey, $command]);
                $nodeFlash = dttd_spotify_command_label($command) . ' command queued for ' . dttd_spotify_node_label($node) . '.';
            }
        } catch (Throwable $e) {
            $nodeError = 'Could not queue command: ' . $e->getMessage();
        }
    }
}

$configured = dttd_spotify_config_loaded();
$connected = dttd_spotify_queue_connected();
$devices = [];
$playback = null;
$error = '';

if ($connected) {
    try {
        $devices = dttd_spotify_get_devices();
        try { $playback = dttd_spotify_current_playback(); } catch (Throwable $ignored) { $playback = null; }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$playerNodes = dttd_spotify_player_nodes();
$recentNodeCommands = dttd_spotify_recent_node_commands(8);

admin_header('Spotify Tools - DJ Portal');
?>
<style>
.pi-node-panel{margin-top:22px}.pi-node-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.pi-node-toolbar p{margin:4px 0 0;color:#9fb5cd}.pi-node-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:14px}.pi-node-card{border:1px solid rgba(96,145,205,.32);border-radius:18px;background:linear-gradient(180deg,rgba(14,28,44,.94),rgba(8,18,30,.94));padding:14px;box-shadow:0 14px 36px rgba(0,0,0,.22)}.pi-node-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.pi-node-head h3{margin:0;font-size:18px}.pi-node-key{display:block;color:#8fa6bd;font-size:12px;margin-top:3px;word-break:break-all}.pi-node-status{border-radius:999px;padding:6px 10px;font-weight:950;font-size:12px;text-transform:uppercase;border:1px solid rgba(148,163,184,.45);color:#cbd5e1;background:rgba(148,163,184,.12)}.pi-node-status.online{border-color:rgba(34,197,94,.65);color:#74ff9b;background:rgba(34,197,94,.12)}.pi-node-status.warning{border-color:rgba(245,158,11,.65);color:#ffc55a;background:rgba(245,158,11,.12)}.pi-node-status.offline{border-color:rgba(239,68,68,.55);color:#ff9ca3;background:rgba(239,68,68,.12)}.pi-node-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0}.pi-node-meta div{border:1px solid rgba(96,145,205,.18);border-radius:12px;background:rgba(255,255,255,.025);padding:9px}.pi-node-meta span{display:block;color:#8fa6bd;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.pi-node-meta strong{display:block;color:#fff;margin-top:3px;word-break:break-word}.pi-node-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:12px}.pi-node-actions button{border:1px solid rgba(96,145,205,.42);background:rgba(16,28,44,.9);color:#fff;border-radius:12px;padding:10px 8px;font-weight:950;cursor:pointer}.pi-node-actions button:hover{border-color:rgba(52,152,255,.7);color:#9fd1ff}.pi-node-actions button.danger{border-color:rgba(239,68,68,.52);color:#ff9ca3}.pi-node-empty{border:1px dashed rgba(96,145,205,.36);border-radius:16px;padding:18px;color:#c8d7e8;background:rgba(255,255,255,.025)}.pi-command-table{width:100%;border-collapse:collapse;margin-top:12px}.pi-command-table th,.pi-command-table td{border-bottom:1px solid rgba(96,145,205,.18);padding:9px 8px;text-align:left;vertical-align:top}.pi-command-table th{color:#9fb5cd;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.pi-command-status{border-radius:999px;padding:3px 8px;font-weight:900;font-size:12px;background:rgba(148,163,184,.12);color:#cbd5e1}.pi-command-status.pending{background:rgba(245,158,11,.12);color:#ffc55a}.pi-command-status.completed{background:rgba(34,197,94,.12);color:#74ff9b}.pi-command-status.failed{background:rgba(239,68,68,.12);color:#ff9ca3}@media(max-width:720px){.pi-node-toolbar{display:block}.pi-node-meta{grid-template-columns:1fr}.pi-node-actions{grid-template-columns:1fr}.pi-command-table{font-size:13px}}
</style>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="panel-head">
      <div>
        <h1 class="touch-panel-title">Spotify Tools</h1>
        <p class="touch-subtitle">Connect the DJ Spotify account, monitor Raspberry Pi players and test queue control safely.</p>
      </div>
    </div>

    <?php if ($flash): ?><p class="notice"><?= h($flash) ?></p><?php endif; ?>
    <?php if ($nodeFlash): ?><p class="notice"><?= h($nodeFlash) ?></p><?php endif; ?>
    <?php if ($error): ?><p class="notice error"><?= h($error) ?></p><?php endif; ?>
    <?php if ($nodeError): ?><p class="notice error"><?= h($nodeError) ?></p><?php endif; ?>

    <div class="settings-grid">
      <div class="setting-card">
        <h2>Connection</h2>
        <p>API config: <strong><?= $configured ? 'configured' : 'missing' ?></strong></p>
        <p>DJ account: <strong><?= $connected ? 'connected' : 'not connected' ?></strong></p>
        <p><a class="touch-button primary" href="connect.php">Connect / Reconnect Spotify</a></p>
        <p class="touch-subtitle">Requires Spotify Premium for playback queue control.</p>
      </div>

      <div class="setting-card">
        <h2>Available Spotify devices</h2>
        <?php if (!$connected): ?>
          <p>Connect Spotify first, then open Spotify on the tablet/player so it appears here.</p>
        <?php elseif (!$devices): ?>
          <p>No active Spotify Connect devices found. This can happen even when a Raspberry Pi node is online.</p>
        <?php else: ?>
          <ul>
            <?php foreach ($devices as $device): ?>
              <li>
                <strong><?= h($device['name'] ?? 'Unnamed device') ?></strong>
                <?= !empty($device['is_active']) ? ' — active' : '' ?>
                <small><?= h($device['type'] ?? '') ?></small>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>

      <div class="setting-card">
        <h2>Public search profile</h2>
        <?php $publicProfile = dttd_spotify_profile_by_role('public_search'); ?>
        <?php if ($publicProfile && !empty($publicProfile['client_id'])): ?>
          <p>Profile: <strong><?= h($publicProfile['label'] ?? 'Public Search') ?></strong></p>
          <p>Status: <strong><?= !empty($publicProfile['enabled']) ? 'enabled' : 'disabled' ?></strong></p>
          <p>Client ID: <strong><?= h(substr((string)$publicProfile['client_id'], 0, 8)) ?>…</strong></p>
        <?php else: ?>
          <p>No secondary public-search profile configured. Public search will use the primary DJ app, then cache/text-only fallback.</p>
        <?php endif; ?>
        <?php
          try {
            $cacheCount = db()->query('SELECT COUNT(*) FROM spotify_track_cache')->fetchColumn();
          } catch (Throwable $e) { $cacheCount = 'unavailable'; }
        ?>
        <p>Cached tracks: <strong><?= h((string)$cacheCount) ?></strong></p>
      </div>

      <div class="setting-card">
        <h2>Currently playing</h2>
        <?php if (!empty($playback['item'])): ?>
          <p><strong><?= h($playback['item']['name'] ?? '') ?></strong></p>
          <p><?= h(implode(', ', array_map(fn($a) => $a['name'] ?? '', $playback['item']['artists'] ?? []))) ?></p>
        <?php else: ?>
          <p>Nothing reported as currently playing.</p>
        <?php endif; ?>
      </div>
    </div>

    <section class="pi-node-panel">
      <div class="pi-node-toolbar">
        <div>
          <h2>Registered Player Nodes</h2>
          <p>Persistent Raspberry Pi heartbeat registry. This is separate from Spotify Connect device discovery.</p>
        </div>
        <p><a class="touch-button" href="<?= h(admin_url('spotify/index.php')) ?>">Refresh</a></p>
      </div>

      <?php if (!dttd_spotify_table_exists('player_nodes') || !dttd_spotify_table_exists('node_commands')): ?>
        <div class="pi-node-empty">
          Player node tables were not found. Run the player_nodes and node_commands SQL before using Raspberry Pi control.
        </div>
      <?php elseif (!$playerNodes): ?>
        <div class="pi-node-empty">
          No Raspberry Pi nodes have checked in yet. Start the node agent and wait for the first heartbeat.
        </div>
      <?php else: ?>
        <div class="pi-node-grid">
          <?php foreach ($playerNodes as $node): ?>
            <?php
              $status = (string)($node['live_status'] ?? 'offline');
              $raspotifyRunning = !empty($node['raspotify_running']);
            ?>
            <article class="pi-node-card">
              <div class="pi-node-head">
                <div>
                  <h3><?= h(dttd_spotify_node_label($node)) ?></h3>
                  <span class="pi-node-key"><?= h($node['node_key'] ?? '') ?></span>
                </div>
                <span class="pi-node-status <?= h($status) ?>"><?= h($status) ?></span>
              </div>

              <div class="pi-node-meta">
                <div><span>IP</span><strong><?= h($node['ip_address'] ?? '—') ?></strong></div>
                <div><span>Hostname</span><strong><?= h($node['hostname'] ?? '—') ?></strong></div>
                <div><span>Spotify name</span><strong><?= h($node['spotify_name'] ?? '—') ?></strong></div>
                <div><span>Raspotify</span><strong><?= $raspotifyRunning ? 'Running' : 'Not running' ?></strong></div>
                <div><span>Deck</span><strong><?= h($node['assigned_deck'] ?? 'Unassigned') ?></strong></div>
                <div><span>Last seen</span><strong><?= h(dttd_spotify_last_seen_label($node)) ?></strong></div>
              </div>

              <form class="pi-node-actions" method="post">
                <input type="hidden" name="node_action" value="send_command">
                <input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>">
                <button type="submit" name="command" value="restart_raspotify">Restart Spotify</button>
                <button type="submit" name="command" value="restart_agent">Restart Agent</button>
                <button class="danger" type="submit" name="command" value="reboot" onclick="return confirm('Reboot <?= h(dttd_spotify_node_label($node)) ?>?')">Reboot</button>
              </form>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($recentNodeCommands): ?>
        <h3 style="margin-top:22px">Recent node commands</h3>
        <table class="pi-command-table">
          <thead>
            <tr>
              <th>Time</th>
              <th>Node</th>
              <th>Command</th>
              <th>Status</th>
              <th>Result</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($recentNodeCommands as $command): ?>
              <tr>
                <td><?= h(date('H:i:s d M', strtotime((string)$command['created_at']))) ?></td>
                <td><?= h(dttd_spotify_node_label($command)) ?></td>
                <td><?= h(dttd_spotify_command_label((string)$command['command'])) ?></td>
                <td><span class="pi-command-status <?= h((string)($command['status'] ?? '')) ?>"><?= h($command['status'] ?? '') ?></span></td>
                <td><?= h($command['result'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </section>
</main>

<p style="margin-top:14px"><a class="btn btn-primary" href="<?= h(admin_url('spotify/mixer.php')) ?>">Open Spotify Mixer</a></p>

<?php admin_footer(); ?>
