<?php
require_once __DIR__ . '/../_auth.php';
require_once __DIR__ . '/../../includes/spotify.php';

function dttd_spotify_profile_id_for_slot($slot) {
    $slot = max(1, min(3, (int)$slot));
    $profiles = [];
    try {
        $profiles = db()->query("SELECT id FROM spotify_profiles ORDER BY id ASC LIMIT 3")->fetchAll();
    } catch (Throwable $e) {
        throw new RuntimeException('spotify_profiles table is not available.');
    }

    if (!empty($profiles[$slot - 1]['id'])) {
        return (int)$profiles[$slot - 1]['id'];
    }

    $label = 'Account ' . $slot;
    $role = $slot === 3 ? 'public_search' : 'playback';
    $enabled = $slot === 3 ? 0 : 1;
    $deckA = $slot === 1 ? 1 : 0;
    $deckB = $slot === 2 ? 1 : 0;
    $public = $slot === 3 ? 1 : 0;

    $stmt = db()->prepare("INSERT INTO spotify_profiles (label, role, enabled, use_for_deck_a, use_for_deck_b, use_for_public_search) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$label, $role, $enabled, $deckA, $deckB, $public]);
    return (int)db()->lastInsertId();
}

function dttd_spotify_fetch_profile_from_token($accessToken) {
    try {
        return dttd_spotify_http_get('https://api.spotify.com/v1/me', [
            'Authorization: Bearer ' . $accessToken,
            'Accept: application/json',
        ]);
    } catch (Throwable $e) {
        return [];
    }
}

function dttd_spotify_save_profile_token($slot, array $token) {
    if (empty($token['access_token'])) {
        throw new RuntimeException('Spotify did not return an access token.');
    }

    $profileId = dttd_spotify_profile_id_for_slot($slot);
    $me = dttd_spotify_fetch_profile_from_token($token['access_token']);
    $email = trim((string)($me['email'] ?? ''));
    $display = trim((string)($me['display_name'] ?? ''));
    $scopes = trim((string)($token['scope'] ?? ''));
    $expiresAt = date('Y-m-d H:i:s', time() + (int)($token['expires_in'] ?? 3600));

    $fields = [
        'access_token = ?',
        'granted_scopes = ?',
        'expires_at = ?',
    ];
    $values = [$token['access_token'], $scopes, $expiresAt];

    if (!empty($token['refresh_token'])) {
        $fields[] = 'refresh_token = ?';
        $values[] = $token['refresh_token'];
    }
    if ($email !== '') {
        $fields[] = 'account_email = ?';
        $values[] = $email;
    }
    if ($display !== '') {
        $fields[] = 'label = ?';
        $values[] = $display;
    }

    $values[] = $profileId;
    $sql = 'UPDATE spotify_profiles SET ' . implode(', ', $fields) . ' WHERE id = ?';
    $stmt = db()->prepare($sql);
    $stmt->execute($values);

    return [$profileId, $email ?: $display ?: ('Account ' . (int)$slot)];
}

try {
    if (!empty($_GET['error'])) {
        throw new RuntimeException('Spotify returned: ' . $_GET['error']);
    }
    $state = $_GET['state'] ?? '';
    if (empty($_SESSION['spotify_oauth_state']) || !hash_equals($_SESSION['spotify_oauth_state'], $state)) {
        throw new RuntimeException('Spotify security state check failed.');
    }

    $profileSlot = (int)($_SESSION['spotify_oauth_profile_slot'] ?? 0);
    unset($_SESSION['spotify_oauth_state'], $_SESSION['spotify_oauth_profile_slot']);

    if ($profileSlot < 1 || $profileSlot > 3) {
        $decoded = json_decode((string)base64_decode($state), true);
        $profileSlot = isset($decoded['profile_slot']) ? max(1, min(3, (int)$decoded['profile_slot'])) : 0;
    }

    $code = $_GET['code'] ?? '';
    if ($code === '') {
        throw new RuntimeException('Spotify did not provide an authorisation code.');
    }

    $token = dttd_spotify_exchange_code($code);

    if ($profileSlot >= 1 && $profileSlot <= 3) {
        [$profileId, $accountLabel] = dttd_spotify_save_profile_token($profileSlot, $token);
        if ($profileSlot === 1) {
            dttd_spotify_save_user_token($token);
        }
        $_SESSION['spotify_flash'] = 'Spotify Account ' . $profileSlot . ' connected: ' . $accountLabel . '.';
    } else {
        dttd_spotify_save_user_token($token);
        $_SESSION['spotify_flash'] = 'Spotify account connected. You can now test Add to Spotify Queue.';
    }
} catch (Throwable $e) {
    $_SESSION['spotify_flash'] = 'Spotify connection failed: ' . $e->getMessage();
}

header('Location: index.php');
exit;
