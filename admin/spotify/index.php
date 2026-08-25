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
        'display_start' => 'Start Display',
        'display_live' => 'Show Live',
        'display_logo' => 'Show Logo',
        'display_blank' => 'Blank',
        'display_restart' => 'Restart Display',
        'display_stop' => 'Stop Display',
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


function dttd_spotify_node_by_key($nodes, $nodeKey) {
    foreach ((array)$nodes as $node) {
        if ((string)($node['node_key'] ?? '') === (string)$nodeKey) {
            return $node;
        }
    }
    return null;
}

function dttd_spotify_audio_platform_map($platform) {
    $platform = strtolower(trim((string)$platform));
    if ($platform === 'pis') {
        return ['a' => 'dmx-desk-a', 'b' => 'dmx-desk-b'];
    }
    return ['a' => 'dmx-lenovo-a', 'b' => 'dmx-lenovo-b'];
}

function dttd_spotify_detect_audio_platform($nodes) {
    $assigned = ['a' => '', 'b' => ''];
    foreach ((array)$nodes as $node) {
        $deck = strtolower(trim((string)($node['assigned_deck'] ?? '')));
        if (isset($assigned[$deck])) {
            $assigned[$deck] = (string)($node['node_key'] ?? '');
        }
    }
    if ($assigned === dttd_spotify_audio_platform_map('lenovo')) return 'lenovo';
    if ($assigned === dttd_spotify_audio_platform_map('pis')) return 'pis';
    return 'mixed';
}

function dttd_spotify_set_audio_platform($platform) {
    $platform = strtolower(trim((string)$platform));
    if (!in_array($platform, ['lenovo', 'pis'], true)) {
        throw new RuntimeException('Invalid audio player platform.');
    }
    if (!dttd_spotify_table_exists('player_nodes')) {
        throw new RuntimeException('Player node table is not available.');
    }

    $map = dttd_spotify_audio_platform_map($platform);
    $placeholders = implode(',', array_fill(0, count($map), '?'));
    $stmt = db()->prepare("SELECT node_key FROM player_nodes WHERE node_key IN ($placeholders)");
    $stmt->execute(array_values($map));
    $found = array_map(static fn($row) => (string)$row['node_key'], $stmt->fetchAll());
    foreach ($map as $deck => $nodeKey) {
        if (!in_array($nodeKey, $found, true)) {
            throw new RuntimeException('Required player node ' . $nodeKey . ' has not registered yet.');
        }
    }

    db()->beginTransaction();
    try {
        // These four records are alternative hardware for the same two logical decks.
        // Clear all operational assignments first so only logical Deck A/B remain assigned.
        $knownNodes = array_values(array_unique(array_merge(
            array_values(dttd_spotify_audio_platform_map('lenovo')),
            array_values(dttd_spotify_audio_platform_map('pis'))
        )));
        $clearPlaceholders = implode(',', array_fill(0, count($knownNodes), '?'));
        db()->prepare("UPDATE player_nodes SET assigned_deck = NULL WHERE node_key IN ($clearPlaceholders)")
            ->execute($knownNodes);
        db()->prepare("UPDATE player_nodes SET assigned_deck = 'A' WHERE node_key = ?")
            ->execute([$map['a']]);
        db()->prepare("UPDATE player_nodes SET assigned_deck = 'B' WHERE node_key = ?")
            ->execute([$map['b']]);
        dttd_spotify_tool_set('audio_player_platform', $platform);
        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    // Never leave the mixer pointing at the previous platform's Spotify Connect IDs.
    // Resolve the newly-selected devices if Spotify currently exposes them; otherwise
    // clear the IDs so a readiness check can populate them safely.
    $nodes = dttd_spotify_player_nodes();
    foreach (['a', 'b'] as $deck) {
        $node = dttd_spotify_node_by_key($nodes, $map[$deck]);
        $device = $node ? dttd_spotify_find_device_for_node($node, $deck) : null;
        dttd_spotify_tool_set('spotify_mixer_device_' . $deck, (string)($device['id'] ?? ''));
    }

    return $map;
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
    'display_live',
    'display_logo',
    'display_blank',
    'display_restart',
    'display_stop',
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

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['node_action'] ?? '') === 'set_audio_platform') {
    $platform = strtolower(trim((string)($_POST['audio_platform'] ?? '')));
    try {
        $map = dttd_spotify_set_audio_platform($platform);
        $label = $platform === 'pis' ? 'Raspberry Pis' : 'Lenovo';
        $nodeFlash = 'Audio playback switched to ' . $label . ': Deck A → ' . $map['a'] . ', Deck B → ' . $map['b'] . '. HDMI display selection is unchanged.';
    } catch (Throwable $e) {
        $nodeError = 'Could not switch audio platform: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['node_action'] ?? '') === 'prepare_player') {
    $nodeKey = trim((string)($_POST['node_key'] ?? ''));
    $deck = strtolower((string)($_POST['deck'] ?? 'a'));
    $deck = in_array($deck, ['a', 'b'], true) ? $deck : 'a';

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

            if ($prepareTestTrack === '') {
                throw new RuntimeException('No Prepare Player test track is configured. Save a Spotify test track first, then run the readiness check again.');
            }

            if (!dttd_spotify_queue_connected_for_deck($deck)) {
                throw new RuntimeException('The Spotify account for Deck ' . strtoupper($deck) . ' is not connected.');
            }

            // Readiness checks are deliberately non-destructive. Do not restart
            // Raspotify or queue any node command here.
            $device = dttd_spotify_find_device_for_node($node, $deck);
            $lastDevices = [];
            if (!$device) {
                [$device, $lastDevices] = dttd_spotify_wait_for_node_device($node, $deck, 6);
            }

            if (!$device || empty($device['id'])) {
                $seen = dttd_spotify_device_names_for_message($lastDevices ?: dttd_spotify_get_devices_for_deck($deck));
                throw new RuntimeException(
                    'Spotify does not currently list the assigned player for Deck ' . strtoupper($deck) .
                    '. No restart was attempted. Spotify API currently sees: ' . $seen .
                    '. Use Restart Spotify separately only if recovery is required.'
                );
            }

            dttd_spotify_tool_set('spotify_mixer_device_' . $deck, (string)$device['id']);
            dttd_spotify_prepare_player_device((string)$device['id'], $deck, $prepareTestTrack);

            $nodeFlash = 'Deck ' . strtoupper($deck) . ' readiness check passed on ' . dttd_spotify_node_label($node) .
                ' using Spotify device "' . (string)($device['name'] ?? 'device') .
                '". The configured test track has been started and left playing. No Spotify restart was performed.';
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
            $check = db()->prepare("SELECT * FROM player_nodes WHERE node_key = ? LIMIT 1");
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

                if (in_array($command, ['display_live', 'display_logo', 'display_blank'], true)) {
                    $screenMode = $command === 'display_logo' ? 'logo' : ($command === 'display_blank' ? 'blank' : 'live');
                    $modeKey = 'display_operating_mode_' . preg_replace('/[^a-z0-9_-]+/i', '_', $nodeKey);
                    dttd_spotify_tool_set($modeKey, $screenMode);

                    // The screen-mode buttons should always do the obvious thing.
                    // Queue Start as a harmless safety net: an already-running Chromium
                    // instance is left untouched by the agent, while a stopped/headless
                    // backup display is brought up automatically.
                    $renderMode = strtolower((string)($node['display_mode'] ?? 'lite'));
                    $renderMode = $renderMode === 'full' ? 'full' : 'lite';
                    $stmt = db()->prepare("
                        INSERT INTO node_commands (node_key, command, payload, status)
                        VALUES (?, 'display_start', ?, 'pending')
                    ");
                    $stmt->execute([$nodeKey, json_encode(['mode' => $renderMode])]);

                    $nodeFlash = dttd_spotify_command_label($command) . ' selected for ' . dttd_spotify_node_label($node) . '. The existing display will switch in place; if it was stopped it will be started automatically.';
                } else {
                    if (in_array($command, ['display_start', 'display_restart'], true)) {
                        $mode = strtolower(trim((string)($_POST['display_mode'] ?? ($node['display_mode'] ?? 'lite'))));
                        $mode = $mode === 'full' ? 'full' : 'lite';
                        $payload = json_encode(['mode' => $mode]);
                    }

                    $stmt = db()->prepare("
                        INSERT INTO node_commands (node_key, command, payload, status)
                        VALUES (?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$nodeKey, $queuedCommand, $payload]);

                    $nodeFlash = dttd_spotify_command_label($command) . ' command queued for ' . dttd_spotify_node_label($node) . ($command === 'set_volume' ? ' at ' . $volume . '%.' : '.');
                }
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
.pi-node-panel{margin-top:22px}.pi-node-toolbar{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.pi-node-toolbar p{margin:4px 0 0;color:#9fb5cd}.pi-section{margin-top:18px;border-top:1px solid rgba(96,145,205,.22);padding-top:18px}.pi-section:first-of-type{border-top:0;padding-top:0}.pi-section-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:12px}.pi-section-head h3{margin:0;font-size:21px}.pi-section-head p{margin:4px 0 0;color:#9fb5cd}.pi-platform-switch{display:flex;gap:8px;flex-wrap:wrap}.pi-platform-switch button{min-width:145px;border:1px solid rgba(96,145,205,.42);background:rgba(16,28,44,.9);color:#cfe0f4;border-radius:12px;padding:11px 14px;font-weight:950;cursor:pointer}.pi-platform-switch button.active{border-color:rgba(34,197,94,.65);background:rgba(22,200,116,.15);color:#86ffac}.pi-platform-switch button.recommended:not(.active){border-color:rgba(52,152,255,.55);color:#9fd1ff}.pi-node-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px;align-items:start}.pi-node-card{border:1px solid rgba(96,145,205,.32);border-radius:18px;background:linear-gradient(180deg,rgba(14,28,44,.96),rgba(8,18,30,.96));padding:16px;box-shadow:0 14px 36px rgba(0,0,0,.22)}.pi-node-card.standby{opacity:.82}.pi-node-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.pi-node-head h4{margin:0;font-size:20px}.pi-node-subtitle{margin-top:4px;color:#9fd1ff;font-size:13px;font-weight:800}.pi-node-key{display:block;color:#8fa6bd;font-size:12px;margin-top:3px;word-break:break-all}.pi-node-status{border-radius:999px;padding:6px 10px;font-weight:950;font-size:12px;text-transform:uppercase;border:1px solid rgba(148,163,184,.45);color:#cbd5e1;background:rgba(148,163,184,.12)}.pi-node-status.online{border-color:rgba(34,197,94,.65);color:#74ff9b;background:rgba(34,197,94,.12)}.pi-node-status.warning{border-color:rgba(245,158,11,.65);color:#ffc55a;background:rgba(245,158,11,.12)}.pi-node-status.offline{border-color:rgba(239,68,68,.55);color:#ff9ca3;background:rgba(239,68,68,.12)}.pi-node-meta{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin:12px 0}.pi-node-meta div{border:1px solid rgba(96,145,205,.18);border-radius:12px;background:rgba(255,255,255,.025);padding:9px}.pi-node-meta span{display:block;color:#8fa6bd;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.pi-node-meta strong{display:block;color:#fff;margin-top:3px;word-break:break-word}.pi-control-label{display:block;color:#8fa6bd;font-size:11px;font-weight:950;text-transform:uppercase;letter-spacing:.06em;margin:12px 0 6px}.pi-node-actions{display:grid;grid-template-columns:repeat(2,1fr);gap:8px;margin-top:8px}.pi-node-actions button,.pi-prepare-actions button{border:1px solid rgba(96,145,205,.42);background:rgba(16,28,44,.9);color:#fff;border-radius:12px;padding:10px 8px;font-weight:950;cursor:pointer}.pi-node-actions button.health,.pi-prepare-actions button{border-color:rgba(34,197,94,.55);background:rgba(22,200,116,.13);color:#7dffa8}.pi-node-actions button.update{border-color:rgba(34,197,94,.55);color:#74ff9b}.pi-node-actions button.danger{border-color:rgba(239,68,68,.52);color:#ff9ca3}.pi-prepare-actions{display:grid;grid-template-columns:1fr;gap:8px;margin-top:8px}.pi-volume-actions{display:grid;grid-template-columns:1fr auto;gap:8px;margin-top:8px}.pi-volume-actions input{min-width:0;background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:12px;padding:10px 12px;font-weight:900}.pi-volume-actions button{border:1px solid rgba(52,152,255,.55);background:rgba(52,152,255,.13);color:#9fd1ff;border-radius:12px;padding:10px 12px;font-weight:950;cursor:pointer}.pi-display-actions{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-top:10px}.pi-display-actions button{border:1px solid rgba(168,85,247,.55);background:rgba(168,85,247,.13);color:#d8b4fe;border-radius:12px;padding:10px 8px;font-weight:950;cursor:pointer}.pi-display-actions button.wake{border-color:rgba(34,197,94,.55);background:rgba(34,197,94,.12);color:#8dffb2}.pi-display-actions button.danger{border-color:rgba(239,68,68,.55);background:rgba(239,68,68,.12);color:#ffb4ba}.pi-display-status{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:8px}.pi-display-status div{border:1px solid rgba(168,85,247,.18);border-radius:12px;background:rgba(255,255,255,.025);padding:9px}.pi-display-status span{display:block;color:#bfa7dc;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.pi-display-status strong{display:block;color:#fff;margin-top:3px;word-break:break-word}.pi-display-hint,.pi-section-note{color:#9fb5cd;font-size:12px;margin:8px 0 0;line-height:1.4}.pi-role-pill{display:inline-flex;margin-top:6px;border-radius:999px;padding:5px 9px;border:1px solid rgba(52,152,255,.45);color:#9fd1ff;background:rgba(52,152,255,.1);font-size:11px;font-weight:950;text-transform:uppercase}.pi-role-pill.primary{border-color:rgba(34,197,94,.5);color:#86ffac;background:rgba(34,197,94,.1)}.pi-empty{border:1px dashed rgba(96,145,205,.36);border-radius:16px;padding:18px;color:#c8d7e8;background:rgba(255,255,255,.025)}.pi-prepare-settings{display:grid;grid-template-columns:minmax(220px,1fr) auto;gap:10px;align-items:end;margin:10px 0 16px}.pi-prepare-settings label{display:grid;gap:5px;color:#9fb5cd;font-weight:900;font-size:12px;text-transform:uppercase}.pi-prepare-settings input[type=text]{width:100%;box-sizing:border-box;background:#0b1524;color:#fff;border:1px solid rgba(96,145,205,.38);border-radius:12px;padding:10px 12px;font-weight:800}.pi-diagnostics-toggle{margin:8px 0 16px;border:1px solid rgba(96,145,205,.28);border-radius:14px;background:rgba(255,255,255,.025);padding:10px 12px}.pi-diagnostics-toggle summary{cursor:pointer;color:#9fb5cd;font-weight:950;font-size:12px;text-transform:uppercase;letter-spacing:.05em}.pi-command-table{width:100%;border-collapse:collapse;margin-top:12px}.pi-command-table th,.pi-command-table td{border-bottom:1px solid rgba(96,145,205,.18);padding:9px 8px;text-align:left;vertical-align:top}.pi-command-table th{color:#9fb5cd;font-size:12px;text-transform:uppercase;letter-spacing:.04em}.pi-command-status{border-radius:999px;padding:3px 8px;font-weight:900;font-size:12px;background:rgba(148,163,184,.12);color:#cbd5e1}.pi-command-status.pending{background:rgba(245,158,11,.12);color:#ffc55a}.pi-command-status.completed{background:rgba(34,197,94,.12);color:#74ff9b}.pi-command-status.failed{background:rgba(239,68,68,.12);color:#ff9ca3}.pi-command-result{max-width:520px;white-space:pre-wrap;word-break:break-word;font-family:ui-monospace,SFMono-Regular,Menlo,Monaco,Consolas,'Liberation Mono','Courier New',monospace;font-size:12px;color:#dbeafe}.pi-command-heading{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-top:22px}.pi-command-heading h3{margin:0}.pi-command-refresh{display:inline-flex;align-items:center;justify-content:center;border:1px solid rgba(52,152,255,.55);background:rgba(52,152,255,.13);color:#9fd1ff;border-radius:12px;padding:10px 14px;font-weight:950;text-decoration:none}@media(max-width:720px){.pi-node-toolbar,.pi-section-head{display:block}.pi-platform-switch{margin-top:10px}.pi-node-meta,.pi-node-actions,.pi-display-actions,.pi-prepare-settings{grid-template-columns:1fr}.pi-volume-actions{grid-template-columns:1fr}.pi-command-table{font-size:13px}}
</style>
<main class="touch-wrap">
  <section class="touch-panel">
    <div class="panel-head">
      <div>
        <h1 class="touch-panel-title">Spotify Tools</h1>
        <p class="touch-subtitle">Choose the audio player platform independently from the Raspberry Pi HDMI live display, then monitor and recover each physical system.</p>
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
        <h2>Available devices</h2>
        <?php if (!$devices): ?>
          <p>No Spotify Connect devices currently visible.</p>
        <?php else: ?>
          <ul class="touch-list">
            <?php foreach ($devices as $device): ?>
              <li><strong><?= h($device['name'] ?? 'Unnamed device') ?></strong><?= !empty($device['is_active']) ? ' — active' : '' ?> <small><?= h($device['type'] ?? '') ?></small></li>
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
        <?php else: ?>
          <p>No secondary public-search profile configured.</p>
        <?php endif; ?>
      </div>
    </div>

    <section class="pi-node-panel">
      <div class="pi-node-toolbar">
        <div>
          <h2>Player & Display Control</h2>
          <p>There are only two logical decks. Lenovo and Raspberry Pi nodes are alternative audio hardware; HDMI display control remains Pi-only and independent.</p>
        </div>
      </div>

      <details class="pi-diagnostics-toggle">
        <summary>Advanced diagnostics / prepare settings</summary>
        <form class="pi-prepare-settings" method="post">
          <input type="hidden" name="node_action" value="save_prepare_settings">
          <label>Test track URI / ID<input type="text" name="prepare_test_track" value="<?= h($prepareTestTrack) ?>" placeholder="Optional: Spotify track URI or ID"></label>
          <button class="touch-button" type="submit">Save Prepare Settings</button>
        </form>
      </details>

      <?php if (!dttd_spotify_table_exists('player_nodes') || !dttd_spotify_table_exists('node_commands')): ?>
        <div class="pi-empty">Player node tables were not found.</div>
      <?php elseif (!$playerNodes): ?>
        <div class="pi-empty">No player nodes have checked in yet.</div>
      <?php else: ?>
        <?php
          $lenovoA = dttd_spotify_node_by_key($playerNodes, 'dmx-lenovo-a');
          $lenovoB = dttd_spotify_node_by_key($playerNodes, 'dmx-lenovo-b');
          $piA = dttd_spotify_node_by_key($playerNodes, 'dmx-desk-a');
          $piB = dttd_spotify_node_by_key($playerNodes, 'dmx-desk-b');
          $audioPlatform = dttd_spotify_detect_audio_platform($playerNodes);
          $activeMap = $audioPlatform === 'pis' ? ['a' => $piA, 'b' => $piB] : ($audioPlatform === 'lenovo' ? ['a' => $lenovoA, 'b' => $lenovoB] : ['a' => null, 'b' => null]);
        ?>

        <div class="pi-section">
          <div class="pi-section-head">
            <div><h3>Audio Playback</h3><p>Lenovo is the normal player platform. Switch both decks to the Pis together if backup playback is required.</p></div>
            <form class="pi-platform-switch" method="post">
              <input type="hidden" name="node_action" value="set_audio_platform">
              <button class="recommended <?= $audioPlatform === 'lenovo' ? 'active' : '' ?>" type="submit" name="audio_platform" value="lenovo">Use Lenovo<?= $audioPlatform === 'lenovo' ? ' ✓' : '' ?></button>
              <button class="<?= $audioPlatform === 'pis' ? 'active' : '' ?>" type="submit" name="audio_platform" value="pis">Use Raspberry Pis<?= $audioPlatform === 'pis' ? ' ✓' : '' ?></button>
            </form>
          </div>
          <?php if ($audioPlatform === 'mixed'): ?><p class="notice error">Audio assignments are not in a recognised A/B platform pair. Choose Lenovo or Raspberry Pis above to correct them automatically.</p><?php endif; ?>
          <div class="pi-node-grid">
            <?php foreach (['a' => 'A', 'b' => 'B'] as $deckLower => $deckUpper): $node = $activeMap[$deckLower] ?? null; ?>
              <article class="pi-node-card <?= $node ? '' : 'standby' ?>">
                <div class="pi-node-head"><div><h4>Deck <?= h($deckUpper) ?></h4><div class="pi-node-subtitle"><?= $node ? h(dttd_spotify_node_label($node)) : 'Select an audio platform' ?></div><?php if ($node): ?><span class="pi-node-key"><?= h($node['node_key'] ?? '') ?></span><?php endif; ?></div><span class="pi-node-status <?= h((string)($node['live_status'] ?? 'offline')) ?>"><?= $node ? h((string)($node['live_status'] ?? 'offline')) : 'unassigned' ?></span></div>
                <?php if ($node): ?>
                  <div class="pi-node-meta"><div><span>IP</span><strong><?= h($node['ip_address'] ?? '—') ?></strong></div><div><span>Spotify</span><strong><?= !empty($node['raspotify_running']) ? 'Running' : 'Not running' ?></strong></div><div><span>Spotify name</span><strong><?= h($node['spotify_name'] ?? '—') ?></strong></div><div><span>Last seen</span><strong><?= h(dttd_spotify_last_seen_label($node)) ?></strong></div></div>
                  <span class="pi-control-label">Audio controls</span>
                  <form class="pi-prepare-actions" method="post"><input type="hidden" name="node_action" value="prepare_player"><input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>"><button type="submit" name="deck" value="<?= h($deckLower) ?>">Check Deck <?= h($deckUpper) ?> Ready</button></form>
                  <form class="pi-node-actions" method="post"><input type="hidden" name="node_action" value="send_command"><input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>"><button type="submit" name="command" value="restart_raspotify">Restart Spotify</button><?php if (str_starts_with((string)($node['node_key'] ?? ''), 'dmx-lenovo-')): ?><button type="submit" name="command" value="restart_agent">Restart Agent</button><?php endif; ?></form>
                  <form class="pi-volume-actions" method="post"><input type="hidden" name="node_action" value="send_command"><input type="hidden" name="node_key" value="<?= h($node['node_key'] ?? '') ?>"><input type="number" name="volume" value="<?= h((string)($node['audio_volume_percent'] ?? 85)) ?>" min="0" max="100" step="1" aria-label="Deck <?= h($deckUpper) ?> volume percent"><button type="submit" name="command" value="set_volume">Set Volume</button></form>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
          <p class="pi-section-note">Spotify account assignments on Settings remain attached to logical Deck A and Deck B. Switching hardware here does not change which Spotify account controls either deck.</p>
        </div>

        <div class="pi-section">
          <div class="pi-section-head"><div><h3>HDMI / Live Display</h3><p>Pi display control remains available regardless of whether Lenovo or the Pis are providing Spotify audio.</p></div></div>
          <div class="pi-node-grid">
            <?php foreach ([['node'=>$piA,'role'=>'Primary display','primary'=>true],['node'=>$piB,'role'=>'Backup display','primary'=>false]] as $displayItem): $node=$displayItem['node']; ?>
              <article class="pi-node-card <?= $node ? '' : 'standby' ?>">
                <?php if (!$node): ?><div class="pi-empty"><?= h($displayItem['role']) ?> Pi has not registered.</div><?php else: $nodeKey=(string)$node['node_key']; $modeKey='display_operating_mode_'.preg_replace('/[^a-z0-9_-]+/i','_',$nodeKey); $operatingMode=strtolower(dttd_spotify_tool_setting($modeKey,'live')); if(!in_array($operatingMode,['live','logo','blank'],true))$operatingMode='live'; ?>
                  <div class="pi-node-head"><div><h4><?= h(dttd_spotify_node_label($node)) ?></h4><span class="pi-node-key"><?= h($nodeKey) ?></span><span class="pi-role-pill <?= $displayItem['primary']?'primary':'' ?>"><?= h($displayItem['role']) ?></span></div><span class="pi-node-status <?= h((string)($node['live_status'] ?? 'offline')) ?>"><?= h((string)($node['live_status'] ?? 'offline')) ?></span></div>
                  <div class="pi-display-status"><div><span>Browser</span><strong><?= !empty($node['display_browser_running'])?'Running':'Stopped' ?></strong></div><div><span>Screen mode</span><strong><?= h(ucfirst($operatingMode)) ?></strong></div><?php if(dttd_spotify_has_column('player_nodes','display_mode')):?><div><span>Render profile</span><strong><?= h((string)($node['display_mode']??'—')) ?></strong></div><?php endif; ?></div>
                  <form class="pi-display-actions" method="post"><input type="hidden" name="node_action" value="send_command"><input type="hidden" name="node_key" value="<?= h($nodeKey) ?>"><button class="wake" type="submit" name="command" value="display_live">Show Live</button><button type="submit" name="command" value="display_logo">Show Logo</button><button class="danger" type="submit" name="command" value="display_blank">Blank</button><input type="hidden" name="display_mode" value="<?= h(strtolower((string)($node['display_mode']??'lite'))==='full'?'full':'lite') ?>"><button type="submit" name="command" value="display_start">Start Display</button><button type="submit" name="command" value="display_restart">Restart Display</button><button class="danger" type="submit" name="command" value="display_stop" onclick="return confirm('Stop the display browser on <?= h(dttd_spotify_node_label($node)) ?>?')">Stop Display</button></form>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="pi-section">
          <div class="pi-section-head"><div><h3>Raspberry Pi Hardware / Maintenance</h3><p>These controls remain available even while Lenovo is the active audio platform, because the Pis may still be providing HDMI or standing by for emergency audio.</p></div></div>
          <div class="pi-node-grid">
            <?php foreach ([$piA,$piB] as $node): if(!$node) continue; $nodeLabel=dttd_spotify_node_label($node); ?>
              <article class="pi-node-card"><div class="pi-node-head"><div><h4><?= h($nodeLabel) ?></h4><span class="pi-node-key"><?= h($node['node_key']??'') ?></span></div><span class="pi-node-status <?= h((string)($node['live_status']??'offline')) ?>"><?= h((string)($node['live_status']??'offline')) ?></span></div><div class="pi-node-meta"><div><span>IP</span><strong><?= h($node['ip_address']??'—') ?></strong></div><div><span>Hostname</span><strong><?= h($node['hostname']??'—') ?></strong></div><div><span>Last seen</span><strong><?= h(dttd_spotify_last_seen_label($node)) ?></strong></div><div><span>Audio role</span><strong><?= $audioPlatform==='pis'?'Active':'Backup' ?></strong></div></div><form class="pi-node-actions" method="post"><input type="hidden" name="node_action" value="send_command"><input type="hidden" name="node_key" value="<?= h($node['node_key']??'') ?>"><button class="health" type="submit" name="command" value="healthcheck">Health Check</button><button class="update" type="submit" name="command" value="update_agent" onclick="return confirm('Update <?= h($nodeLabel) ?> from Git?')">Update</button><button type="submit" name="command" value="restart_agent">Restart Agent</button><button class="danger" type="submit" name="command" value="reboot" onclick="return confirm('Reboot <?= h($nodeLabel) ?>?')">Reboot</button><button class="danger" type="submit" name="command" value="shutdown" onclick="return confirm('Shutdown <?= h($nodeLabel) ?>? You will need to physically power it back on.')">Shutdown</button></form></article>
            <?php endforeach; ?>
          </div>
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
