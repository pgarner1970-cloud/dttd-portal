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


function dttd_spotify_has_column($table, $column) {
    try {
        $stmt = db()->prepare("SHOW COLUMNS FROM `" . str_replace('`', '', $table) . "` LIKE ?");
        $stmt->execute([$column]);
        return (bool)$stmt->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function dttd_spotify_command_label($command) {
    $labels = [
        'restart_raspotify' => 'Restart Spotify',
        'restart_agent' => 'Restart Agent',
        'reboot' => 'Reboot Node',
        'shutdown' => 'Shutdown Node',
        'healthcheck' => 'Health Check',
        'update_agent' => 'Update Agent',
        'set_volume' => 'Set Volume',
        'display_start' => 'Start / Apply Display',
        'display_logo' => 'Show Logo Screen',
        'display_restart' => 'Restart Display',
        'display_wake' => 'Wake Display',
    ];
    return $labels[$command] ?? $command;
}

function dttd_spotify_tool_setting($key, $default = '') {
    try {
        $stmt = db()->prepare("SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1");
        $stmt->execute([(string)$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function dttd_spotify_tool_set($key, $value) {
    $stmt = db()->prepare("
        INSERT INTO app_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ");
    $stmt->execute([(string)$key, (string)$value]);
}

function dttd_spotify_prepare_track_id($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return '';
    }
    if (preg_match('~spotify:track:([A-Za-z0-9]+)~', $value, $m)) {
        return $m[1];
    }
    if (preg_match('~/track/([A-Za-z0-9]+)~', $value, $m)) {
        return $m[1];
    }
    return preg_replace('/[^A-Za-z0-9]/', '', $value);
}

function dttd_spotify_node_match_terms($node) {
    $terms = [];
    foreach (['spotify_name', 'display_name', 'hostname', 'node_key'] as $field) {
        $value = strtolower(trim((string)($node[$field] ?? '')));
        if ($value !== '') {
            $terms[] = $value;
        }
    }
    return array_values(array_unique($terms));
}

function dttd_spotify_find_device_for_node($node, $deck) {
    $devices = dttd_spotify_get_devices_for_deck($deck);
    $terms = dttd_spotify_node_match_terms($node);

    foreach ($devices as $device) {
        $name = strtolower(trim((string)($device['name'] ?? '')));
        if ($name === '') {
            continue;
        }

        foreach ($terms as $term) {
            if ($term !== '' && ($name === $term || str_contains($name, $term) || str_contains($term, $name))) {
                return $device;
            }
        }
    }

    return null;
}

function dttd_spotify_prepare_put($url, $body, $deck) {
    $token = dttd_spotify_user_access_token_for_deck($deck);
    return dttd_spotify_http_put($url, [
        'Authorization: Bearer ' . $token,
        'Content-Type: application/json',
        'Accept: application/json',
    ], $body);
}

function dttd_spotify_prepare_player_device($deviceId, $deck, $testTrackId = '') {
    $deviceId = trim((string)$deviceId);
    $deck = strtolower((string)$deck) === 'b' ? 'b' : 'a';
    $testTrackId = dttd_spotify_prepare_track_id($testTrackId);

    if ($deviceId === '') {
        throw new RuntimeException('No Spotify device id was available.');
    }

    if ($testTrackId !== '') {
        dttd_spotify_prepare_put(
            'https://api.spotify.com/v1/me/player/play?device_id=' . rawurlencode($deviceId),
            json_encode([
                'uris' => ['spotify:track:' . $testTrackId],
                'position_ms' => 0,
            ]),
            $deck
        );
        return;
    }

    // No test track configured: just transfer control to the player without starting playback.
    dttd_spotify_prepare_put('https://api.spotify.com/v1/me/player', json_encode([
        'device_ids' => [$deviceId],
        'play' => false,
    ]), $deck);
}

function dttd_spotify_wait_for_node_device($node, $deck, $seconds = 20) {
    $deadline = time() + max(3, min(45, (int)$seconds));
    $lastDevices = [];

    do {
        try {
            $lastDevices = dttd_spotify_get_devices_for_deck($deck);
            foreach ($lastDevices as $device) {
                $name = strtolower(trim((string)($device['name'] ?? '')));
                if ($name === '') {
                    continue;
                }

                foreach (dttd_spotify_node_match_terms($node) as $term) {
                    if ($term !== '' && ($name === $term || str_contains($name, $term) || str_contains($term, $name))) {
                        return [$device, $lastDevices];
                    }
                }
            }
        } catch (Throwable $ignored) {}

        sleep(2);
    } while (time() < $deadline);

    return [null, $lastDevices];
}

function dttd_spotify_device_names_for_message($devices) {
    $names = [];
    foreach ((array)$devices as $device) {
        $name = trim((string)($device['name'] ?? ''));
        if ($name !== '') {
            $names[] = $name;
        }
    }
    return $names ? implode(', ', array_unique($names)) : 'none';
}

$flash = $_SESSION['spotify_flash'] ?? '';
unset($_SESSION['spotify_flash']);

$nodeFlash = '';
$nodeError = '';

$allowedNodeCommands = [
    'restart_raspotify',
    'restart_agent',
    'reboot',
    'shutdown',
    'healthcheck',
    'update_agent',
    'set_volume',
    'display_start',
    'display_logo',
    'display_restart',
    'display_wake',
];

$prepareTestTrack = dttd_spotify_prepare_track_id(dttd_spotify_tool_setting('spotify_prepare_test_track_id', ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['node_action'] ?? '') === 'save_prepare_settings') {
    $prepareTestTrack = dttd_spotify_prepare_track_id($_POST['prepare_test_track'] ?? '');
    try {
        dttd_spotify_tool_set('spotify_prepare_test_track_id', $prepareTestTrack);
        $nodeFlash = 'Prepare Player test track saved.';
    } catch (Throwable $e) {
        $nodeError = 'Could not save Prepare Player settings: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['node_action'] ?? '') === 'prepare_player') {
    $nodeKey = trim((string)($_POST['node_key'] ?? ''));
    $deck = strtolower((string)($_POST['deck'] ?? 'a'));
    $deck = in_array($deck, ['a', 'b', 'c', 'd'], true) ? $deck : 'a';

    if (!dttd_spotify_table_exists('player_nodes') || !dttd_spotify_table_exists('node_commands')) {
        $nodeError = 'Player node tables are not available yet.';
    } elseif ($nodeKey === '') {
        $nodeError = 'No player node was selected.';
    } else {
        try {
            $stmt = db()->prepare("SELECT * FROM player_nodes WHERE node_key = ? LIMIT 1");
            $stmt->execute([$nodeKey]);
            $node = $stmt->fetch();

            if (!$node) {
                throw new RuntimeException('Player node was not found.');
            }

            db()->prepare("UPDATE player_nodes SET assigned_deck = ? WHERE node_key = ?")->execute([strtoupper($deck), $nodeKey]);

            // Wake/re-advertise librespot first. The agent already understands this command.
            db()->prepare("
                INSERT INTO node_commands (node_key, command, payload, status)
                VALUES (?, 'restart_raspotify', ?, 'pending')
            ")->execute([$nodeKey, json_encode([
                'reason' => 'prepare_player',
                'deck' => strtoupper($deck),
                'created_by' => 'spotify_tools',
            ])]);

            $device = null;
            $lastDevices = [];

            if (dttd_spotify_queue_connected_for_deck($deck)) {
                $device = dttd_spotify_find_device_for_node($node, $deck);
                if (!$device) {
                    [$device, $lastDevices] = dttd_spotify_wait_for_node_device($node, $deck, 18);
                }
            }

            if ($device && !empty($device['id'])) {
                dttd_spotify_tool_set('spotify_mixer_device_' . $deck, (string)$device['id']);
                dttd_spotify_prepare_player_device((string)$device['id'], $deck, $prepareTestTrack);

                $nodeFlash = 'Prepared ' . dttd_spotify_node_label($node) . ' for Deck ' . strtoupper($deck) . ' using Spotify device "' . (string)($device['name'] ?? 'device') . '".';
                if ($prepareTestTrack !== '') {
                    $nodeFlash .= ' The configured test track has been started and left playing.';
                } else {
                    $nodeFlash .= ' No test track is configured, so playback was not started.';
                }
            } else {
                $seen = dttd_spotify_device_names_for_message($lastDevices ?: (dttd_spotify_queue_connected_for_deck($deck) ? dttd_spotify_get_devices_for_deck($deck) : []));
                $nodeFlash = 'Wake command queued for ' . dttd_spotify_node_label($node) . ' and assigned to Deck ' . strtoupper($deck) . ', but Spotify API still does not list a matching device. Spotify API currently sees: ' . $seen . '.';
            }
        } catch (Throwable $e) {
            $nodeError = 'Prepare Player failed: ' . $e->getMessage();
        }
    }
}


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
                $payload = null;
                $volume = null;

                if ($command === 'set_volume') {
                    $volume = max(0, min(100, (int)($_POST['volume'] ?? 85)));
                    $payload = json_encode(['volume' => $volume]);

                    if (dttd_spotify_has_column('player_nodes', 'audio_volume_percent')) {
                        db()->prepare("UPDATE player_nodes SET audio_volume_percent = ? WHERE node_key = ?")->execute([$volume, $nodeKey]);
                    }
                }

                $queuedCommand = $command;

                if (in_array($command, ['display_start', 'display_restart'], true)) {
                    $mode = strtolower(trim((string)($_POST['display_mode'] ?? 'lite')));
                    $mode = $mode === 'full' ? 'full' : 'lite';
                    $payload = json_encode(['mode' => $mode]);
                }

                if ($command === 'display_logo') {
                    $queuedCommand = 'display_start';
                    $payload = json_encode(['mode' => 'logo']);
                }

                $stmt = db()->prepare("
                    INSERT INTO node_commands (node_key, command, payload, status)
                    VALUES (?, ?, ?, 'pending')
                ");
                $stmt->execute([$nodeKey, $queuedCommand, $payload]);

                $nodeFlash = dttd_spotify_command_label($command) . ' command queued for ' . dttd_spotify_node_label($node) . ($command === 'set_volume' ? ' at ' . $volume . '%.' : '.');
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
.pi-node-panel{margin-top:22px}.pi-node-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.pi-node-toolbar p{margin:4px 0 0;color:#9fb5cd}.pi-node-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;align-items:start}.pi-node-card{border:1px solid rgba(96,145,205,.32);border-radius:18px;background:linear-gradient(180deg,rgba(14,28,44,.96),rgba(8,18,30,.96));padding:16px;box-shadow:0 14px 36px rgba(0,0,0,.22)}.pi-node-card.empty{opacity:.78;border-style:dashed}.pi-deck-title{font-size:24px;margin:0}.pi-deck-node-name{font-size:14px;color:#9fd1ff;font-weight:900;margin-top:4px}.pi-empty-deck-msg{border:1px dashed rgba(96,145,205,.35);border-radius:14px;padding:14px;color:#cfe0f4;background:rgba(255,255,255,.025);margin-top:12px}.pi-empty-deck-msg strong{display:block;color:#fff;margin-bottom:4px}.pi-node-select-actions{display:grid;gap:8px;margin-top:10px}.pi-node-select-actions button{border:1px solid rgba(34,197,94,.55);background:rgba(22,200,116,.13);color:#7dffa8;border-radius:12px;padding:10px 8px;font-weight:950;cursor:pointer;text-align:left}.pi-volume-actions{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:8px}.pi-volume-actions input{min-width:0;background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:12px;padding:10px 12px;font-weight:900}.pi-volume-actions button{border:1px solid rgba(52,152,255,.55);background:rgba(52,152,255,.13);color:#9fd1ff;border-radius:12px;padding:10px 12px;font-weight:950;cursor:pointer}.pi-node-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.pi-node-head h3{margin:0;font-size:22px}.pi-node-subtitle{margin-top:4px;color:#9fd1ff;font-size:13px;font-weight:800}.pi-node-key{display:block;color:#8fa6bd;font-size:12px;margin-top:3px;word-break:break-all}.pi-node-status{border-radius:999px;padding:6px 10px;font-weight:950;font-size:12px;text-transform:uppercase;border:1px solid rgba(148,163,184,.45);color:#cbd5e1;background:rgba(148,163,184,.12)}.pi-node-status.online{border-color:rgba(34,197,94,.65);color:#74ff9b;background:rgba(34,197,94,.12)}.pi-node-status.warning{border-color:rgba(245,158,11,.65);color:#ffc55a;background:rgba(245,158,11,.12)}.pi-node-status.offline{border-color:rgba(239,68,68,.55);color:#ff9ca3;background:rgba(239,68,68,.12)}.pi-node-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0}.pi-node-meta div{border:1px solid rgba(96,145,205,.18);border-radius:12px;background:rgba(255,255,255,.025);padding:9px}.pi-node-meta span{display:block;color:#8fa6bd;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.pi-node-meta strong{display:block;color:#fff;margin-top:3px;word-break:break-word}.pi-node-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}.pi-maint-actions{grid-template-columns:repeat(2,1fr)}.pi-prepare-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:10px}.pi-prepare-actions button{border:1px solid rgba(34,197,94,.55);background:rgba(22,200,116,.13);color:#7dffa8;border-radius:12px;padding:10px 8px;font-weight:950;cursor:pointer}.pi-prepare-actions button.secondary{border-color:rgba(96,145,205,.42);background:rgba(16,28,44,.9);color:#cfe0f4}.pi-assigned-deck{display:inline-flex;align-items:center;gap:8px;border-radius:999px;border:1px solid rgba(34,197,94,.55);background:rgba(34,197,94,.12);color:#7dffa8;font-weight:950;padding:7px 10px;margin:6px 0 2px}.pi-control-block{margin-top:12px}.pi-control-label{display:block;color:#8fa6bd;font-size:11px;font-weight:950;text-transform:uppercase;letter-spacing:.06em;margin:12px 0 6px}.pi-deck-assign-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.pi-deck-assign-actions button{border:1px solid rgba(96,145,205,.42);background:rgba(16,28,44,.9);color:#cfe0f4;border-radius:12px;padding:10px 8px;font-weight:950;cursor:pointer}.pi-deck-assign-actions button.active{border-color:rgba(34,197,94,.55);background:rgba(22,200,116,.13);color:#7dffa8}.pi-prepare-settings{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:10px;align-items:end;margin:10px 0 16px}.pi-prepare-settings label{display:grid;gap:5px;color:#9fb5cd;font-weight:900;font-size:12px;text-transform:uppercase}.pi-prepare-settings input[type=text]{width:100%;box-sizing:border-box;background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:12px;padding:10px 12px;font-weight:800}.pi-prepare-settings .checkline{display:flex;align-items:center;gap:7px;color:#c8d7e8;font-weight:900;text-transform:none;font-size:13px}.pi-node-actions button{border:1px solid rgba(96,145,205,.42);background:rgba(16,28,44,.9);color:#fff;border-radius:12px;padding:10px 8px;font-weight:950;cursor:pointer}.pi-node-actions button:hover{border-color:rgba(52,152,255,.7);color:#9fd1ff}.pi-node-actions button.danger{border-color:rgba(239,68,68,.52);color:#ff9ca3}.pi-node-actions button.update{border-color:rgba(34,197,94,.55);color:#74ff9b}.pi-node-actions button.health{border-color:rgba(52,152,255,.55);color:#9fd1ff}.pi-node-empty{border:1px dashed rgba(96,145,205,.36);border-radius:16px;padding:18px;color:#c8d7e8;background:rgba(255,255,255,.025)}.pi-command-table{width:100%;border-collapse:collapse;margin-top:12px}.pi-command-table th,.pi-command-table td{border-bottom:1px solid rgba(96,145,205,.18);padding:9px 8px;text-align:left;vertical-align:top}.pi-command-table th{color:#9fb5cd;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.pi-command-status{border-radius:999px;padding:3px 8px;font-weight:900;font-size:12px;background:rgba(148,163,184,.12);color:#cbd5e1}.pi-command-status.pending{background:rgba(245,158,11,.12);color:#ffc55a}.pi-command-status.completed{background:rgba(34,197,94,.12);color:#74ff9b}.pi-command-status.failed{background:rgba(239,68,68,.12);color:#ff9ca3}.pi-command-result{max-width:520px;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;font-size:12px;color:#dbeafe}.pi-diagnostics-toggle{margin:8px 0 16px;border:1px solid rgba(96,145,205,.28);border-radius:14px;background:rgba(255,255,255,.025);padding:10px 12px}.pi-diagnostics-toggle summary{cursor:pointer;color:#9fb5cd;font-weight:950;font-size:12px;text-transform:uppercase;letter-spacing:.05em}.pi-diagnostics-toggle .pi-prepare-settings{margin:10px 0 0}.pi-command-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:22px}.pi-command-heading h3{margin:0}.pi-command-refresh{display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(52,152,255,.55);background:rgba(52,152,255,.13);color:#9fd1ff;border-radius:12px;padding:10px 14px;font-weight:950;text-decoration:none}.pi-command-refresh:hover{border-color:rgba(52,152,255,.8);color:#fff}.pi-prepare-actions button{min-width:0}@media(max-width:720px){.pi-node-toolbar{display:block}.pi-node-meta{grid-template-columns:1fr}.pi-node-actions,.pi-prepare-actions,.pi-prepare-settings{grid-template-columns:1fr}.pi-command-table{font-size:13px}}
.pi-display-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:10px}.pi-display-actions button{border:1px solid rgba(168,85,247,.55);background:rgba(168,85,247,.13);color:#d8b4fe;border-radius:12px;padding:10px 8px;font-weight:950;cursor:pointer}.pi-display-actions button.stop{border-color:rgba(239,68,68,.55);background:rgba(239,68,68,.12);color:#ffb4ba}.pi-display-actions button.wake{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.12);color:#8dffb2}.pi-display-actions select{min-width:0;background:#0b1524;color:#fff;border:1px solid rgba(168,85,247,.38);border-radius:12px;padding:10px 12px;font-weight:900}.pi-display-actions .wide{grid-column:1/-1}.pi-display-status{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}.pi-display-status div{border:1px solid rgba(168,85,247,.18);border-radius:12px;background:rgba(255,255,255,.025);padding:9px}.pi-display-status span{display:block;color:#bfa7dc;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.pi-display-status strong{display:block;color:#fff;margin-top:3px;word-break:break-word}.pi-display-hint{color:#9fb5cd;font-size:12px;margin:8px 0 0;line-height:1.35}
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
      </div>

      <details class="pi-diagnostics-toggle">
        <summary>Advanced diagnostics / prepare settings</summary>
        <form class="pi-prepare-settings" method="post">
          <input type="hidden" name="node_action" value="save_prepare_settings">
          <label>
            Test track URI / ID
            <input type="text" name="prepare_test_track" value="<?= h($prepareTestTrack) ?>" placeholder="Optional: Spotify track URI or ID">
          </label>
          <button class="touch-button" type="submit">Save Prepare Settings</button>
        </form>
      </details>

      <?php if (!dttd_spotify_table_exists('player_nodes') || !dttd_spotify_table_exists('node_commands')): ?>
        <div class="pi-node-empty">
          Player node tables were not found. Run the player_nodes and node_commands SQL before using Raspberry Pi control.
        </div>
      <?php elseif (!$playerNodes): ?>
        <div class="pi-node-empty">
          No Raspberry Pi nodes have checked in yet. Start the node agent and wait for the first heartbeat.
        </div>
      <?php else: ?>
        <?php
          $deckSlots = ['A', 'B', 'C', 'D'];
          $nodesByDeck = [];
          $unassignedNodes = [];

          foreach ($playerNodes as $candidateNode) {
              $candidateDeck = strtoupper(trim((string)($candidateNode['assigned_deck'] ?? '')));
              if (in_array($candidateDeck, $deckSlots, true) && empty($nodesByDeck[$candidateDeck])) {
                  $nodesByDeck[$candidateDeck] = $candidateNode;
              } else {
                  $unassignedNodes[] = $candidateNode;
              }
          }
        ?>

        <div class="pi-node-grid">
          <?php foreach ($deckSlots as $deckSlot): ?>
            <?php
              $node = $nodesByDeck[$deckSlot] ?? null;
              $hasNode = (bool)$node;
              $status = $hasNode ? (string)($node['live_status'] ?? 'offline') : 'offline';
              $raspotifyRunning = $hasNode && !empty($node['raspotify_running']);
              $nodeLabel = $hasNode ? dttd_spotify_node_label($node) : '';
              $deckLower = strtolower($deckSlot);
            ?>

            <article class="pi-node-card <?= $hasNode ? '' : 'empty' ?>">
              <div class="pi-node-head">
                <div>
                  <h3 class="pi-deck-title">Deck <?= h($deckSlot) ?></h3>
                  <?php if ($hasNode): ?>
                    <div class="pi-deck-node-name"><?= h($nodeLabel) ?></div>
                    <span class="pi-node-key"><?= h($node['node_key'] ?? '') ?></span>
                  <?php else: ?>
                    <div class="pi-deck-node-name">No player assigned</div>
                  <?php endif; ?>
                </div>
                <span class="pi-node-status <?= h($status) ?>"><?= $hasNode ? h($status) : 'empty' ?></span>
              </div>

              <?php if ($hasNode): ?>
                <div class="pi-node-meta">
                  <div><span>IP</span><strong><?= h($node['ip_address'] ?? '—') ?></strong></div>
                  <div><span>Hostname</span><strong><?= h($node['hostname'] ?? '—') ?></strong></div>
                  <div><span>Spotify name</span><strong><?= h($node['spotify_name'] ?? '—') ?></strong></div>
                  <div><span>Raspotify</span><strong><?= $raspotifyRunning ? 'Running' : 'Not running' ?></strong></div>
                  <?php if (dttd_spotify_has_column('player_nodes', 'display_browser_running')): ?>
                    <div><span>Display</span><strong><?= !empty($node['display_browser_running']) ? 'Running' : 'Stopped' ?></strong></div>
                  <?php endif; ?>
                  <div><span>Deck</span><strong>Deck <?= h($deckSlot) ?></strong></div>
                  <div><span>Last seen</span><strong><?= h(dttd_spotify_last_seen_label($node)) ?></strong></div>
                </div>

                <div class="pi-control-block">
                  <span class="pi-control-label">Deck <?= h($deckSlot) ?> readiness</span>
                  <form class="pi-prepare-actions" method="post">
                    <input type="hidden" name="node_action" value="prepare_player">
                    <input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>">
                    <button type="submit" name="deck" value="<?= h($deckLower) ?>">Check Deck <?= h($deckSlot) ?> Ready</button>
                  </form>
                </div>

                <div class="pi-control-block">
                  <span class="pi-control-label">Deck <?= h($deckSlot) ?> controls</span>
                  <form class="pi-node-actions pi-maint-actions" method="post">
                    <input type="hidden" name="node_action" value="send_command">
                    <input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>">
                    <button class="health" type="submit" name="command" value="healthcheck">Health Check Deck <?= h($deckSlot) ?></button>
                    <button class="update" type="submit" name="command" value="update_agent" onclick="return confirm('Update Deck <?= h($deckSlot) ?> node <?= h($nodeLabel) ?> from Git?')">Update Deck <?= h($deckSlot) ?></button>
                  </form>

                  <form class="pi-node-actions" method="post">
                    <input type="hidden" name="node_action" value="send_command">
                    <input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>">
                    <button type="submit" name="command" value="restart_raspotify">Restart Spotify</button>
                    <button type="submit" name="command" value="restart_agent">Restart Agent</button>
                    <button class="danger" type="submit" name="command" value="reboot" onclick="return confirm('Reboot Deck <?= h($deckSlot) ?> node <?= h($nodeLabel) ?>?')">Reboot Deck <?= h($deckSlot) ?></button>
                    <button class="danger" type="submit" name="command" value="shutdown" onclick="return confirm('Shutdown Deck <?= h($deckSlot) ?> node <?= h($nodeLabel) ?>? You will need to physically power it back on.')">Shutdown Deck <?= h($deckSlot) ?></button>
                  </form>

                  <div class="pi-display-status">
                    <?php if (dttd_spotify_has_column('player_nodes', 'display_mode')): ?>
                      <div><span>Display mode</span><strong><?= h((string)($node['display_mode'] ?? '—')) ?></strong></div>
                    <?php endif; ?>
                    <?php if (dttd_spotify_has_column('player_nodes', 'display_url')): ?>
                      <div><span>Display URL</span><strong><?= h((string)($node['display_url'] ?? '—')) ?></strong></div>
                    <?php endif; ?>
                  </div>

                  <form class="pi-display-actions" method="post">
                    <input type="hidden" name="node_action" value="send_command">
                    <input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>">
                    <select name="display_mode" aria-label="Deck <?= h($deckSlot) ?> display mode">
                      <option value="lite" <?= strtolower((string)($node['display_mode'] ?? '')) === 'lite' ? 'selected' : '' ?>>Lite display</option>
                      <option value="full" <?= strtolower((string)($node['display_mode'] ?? '')) === 'full' ? 'selected' : '' ?>>Full display</option>
                    </select>
                    <button type="submit" name="command" value="display_start">Start / Apply</button>
                    <button type="submit" name="command" value="display_restart">Restart Display</button>
                    <button type="submit" name="command" value="display_logo">Show Logo Screen</button>
                    <button class="wake" type="submit" name="command" value="display_wake">Wake Screen</button>
                  </form>
                  <p class="pi-display-hint">Start / Apply launches the HDMI player in the selected Lite or Full mode. Show Logo Screen keeps the HDMI output on a clean black branded holding screen. Wake Screen restores the HDMI output if the screen has been powered down.</p>

                  <form class="pi-volume-actions" method="post">
                    <input type="hidden" name="node_action" value="send_command">
                    <input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>">
                    <input type="number" name="volume" value="<?= h((string)($node['audio_volume_percent'] ?? 85)) ?>" min="0" max="100" step="1" aria-label="Deck <?= h($deckSlot) ?> volume percent">
                    <button type="submit" name="command" value="set_volume">Set Deck <?= h($deckSlot) ?> Volume</button>
                  </form>
                </div>
              <?php else: ?>
                <div class="pi-empty-deck-msg">
                  <strong>Deck <?= h($deckSlot) ?> has no Raspberry Pi assigned.</strong>
                  Assign one of the available players below, then check the deck is ready.
                </div>

                <?php if ($unassignedNodes): ?>
                  <form class="pi-node-select-actions" method="post">
                    <input type="hidden" name="node_action" value="prepare_player">
                    <input type="hidden" name="deck" value="<?= h($deckLower) ?>">
                    <?php foreach ($unassignedNodes as $freeNode): ?>
                      <button type="submit" name="node_key" value="<?= h($freeNode['node_key'] ?? '') ?>">
                        Assign <?= h(dttd_spotify_node_label($freeNode)) ?> to Deck <?= h($deckSlot) ?>
                      </button>
                    <?php endforeach; ?>
                  </form>
                <?php else: ?>
                  <div class="pi-empty-deck-msg">
                    No unassigned Raspberry Pi players are currently available.
                  </div>
                <?php endif; ?>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>


<?php if ($recentNodeCommands): ?>
        <div class="pi-command-heading">
          <h3>Recent node commands</h3>
          <a class="pi-command-refresh" href="<?= h(admin_url('spotify/index.php')) ?>">Refresh Status</a>
        </div>
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
                <td class="pi-command-result"><?= h($command['result'] ?? '') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>
  </section>
</main>


<?php admin_footer(); ?>
