<?php

header('Content-Type: application/json');

require_once __DIR__ . '/../../includes/db.php';

$secret = 'DMX_NODE_SECRET_7f2c9e4a1b8d6f0c3e5a9d7b2f4c8e1';

$providedSecret =
    $_SERVER['HTTP_X_DMX_NODE_SECRET']
    ?? ($_SERVER['REDIRECT_HTTP_X_DMX_NODE_SECRET'] ?? '')
    ?? '';

if (!hash_equals($secret, $providedSecret)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$data = json_decode(file_get_contents('php://input'), true);

$nodeKey = trim($data['node_key'] ?? '');
$mode = trim($data['mode'] ?? 'poll');

if ($nodeKey === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing node_key']);
    exit;
}

if ($mode === 'complete') {
    $commandId = (int)($data['command_id'] ?? 0);
    $status = trim($data['status'] ?? 'completed');
    $result = trim($data['result'] ?? '');

    if (!$commandId) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Missing command_id']);
        exit;
    }

    $stmt = db()->prepare("
        UPDATE node_commands
        SET status = ?, result = ?, completed_at = NOW()
        WHERE id = ? AND node_key = ?
    ");
    $stmt->execute([$status, $result, $commandId, $nodeKey]);

    echo json_encode(['ok' => true]);
    exit;
}

$stmt = db()->prepare("
    SELECT id, command, payload
    FROM node_commands
    WHERE node_key = ?
      AND status = 'pending'
    ORDER BY created_at ASC
    LIMIT 1
");
$stmt->execute([$nodeKey]);
$command = $stmt->fetch();

db()->prepare("
    UPDATE player_nodes
    SET last_command_check = NOW()
    WHERE node_key = ?
")->execute([$nodeKey]);

if (!$command) {
    echo json_encode(['ok' => true, 'command' => null]);
    exit;
}

db()->prepare("
    UPDATE node_commands
    SET status = 'running', picked_up_at = NOW()
    WHERE id = ?
")->execute([$command['id']]);

echo json_encode([
    'ok' => true,
    'command' => [
        'id' => (int)$command['id'],
        'name' => $command['command'],
        'payload' => $command['payload'] ? json_decode($command['payload'], true) : null
    ]
]);