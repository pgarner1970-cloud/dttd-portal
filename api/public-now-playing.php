<?php
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/spotify.php';
require_once __DIR__ . '/../includes/track-history.php';

dttd_no_cache_headers();
header('Content-Type: application/json; charset=utf-8');

function public_np_json($payload) {
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function public_np_table_exists($table) {
    static $cache = [];
    $table = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$table);
    if ($table === '') return false;
    if (array_key_exists($table, $cache)) return $cache[$table];
    try {
        $stmt = db()->prepare('SHOW TABLES LIKE ?');
        $stmt->execute([$table]);
        return $cache[$table] = (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function public_np_setting($key, $default = '') {
    if (!public_np_table_exists('app_settings')) return $default;
    try {
        $stmt = db()->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
        $stmt->execute([(string)$key]);
        $row = $stmt->fetch();
        return $row ? (string)$row['setting_value'] : $default;
    } catch (Throwable $e) {
        return $default;
    }
}

function public_np_set($key, $value) {
    if (!public_np_table_exists('app_settings')) return false;
    try {
        $stmt = db()->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
        $stmt->execute([(string)$key, (string)$value]);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function public_np_active_event($eventId = 0) {
    $eventId = max(0, (int)$eventId);

    try {
        if ($eventId > 0) {
            // The HDMI display has already resolved the active event via display-state.php.
            // Trust an explicit event_id here so now-playing works before/after the public
            // guest portal availability window.
            $event = get_event($eventId);
            return ($event && !empty($event['id'])) ? $event : null;
        }

        return active_event();
    } catch (Throwable $e) {
        return null;
    }
}
function public_np_normalise_track_text($value) {
    $value = strtolower(trim((string)$value));
    $value = preg_replace('/\s+/', ' ', $value);
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value);
    return trim(preg_replace('/\s+/', ' ', $value));
}

function public_np_clean_display_title($title) {
    $original = trim((string)$title);
    if ($original === '') return '';

    $clean = preg_replace('/\s+/', ' ', $original);

    // Spotify often appends source/version text that is useful in search results
    // but too noisy for the HDMI live display. Keep the saved metadata intact;
    // only clean the public-display title payload.
    $patterns = [
        '/\s+[\-–—]\s+from\s+["“”]?[^"“”]*(?:soundtrack|motion picture|film|movie|album|original soundtrack)[^"“”]*["“”]?\s*$/iu',
        '/\s+[\-–—]\s+from\s+["“”][^"“”]+["“”]\s*$/iu',
        '/\s+[\-–—]\s+(?:\d{4}\s+)?(?:remaster(?:ed)?|remastered\s+\d{4}|\d{4}\s+remaster)\s*$/iu',
        '/\s+[\-–—]\s+(?:single|album|radio|video|edit|extended|club|dance|12\s*inch|7\s*inch|mono|stereo)\s+(?:version|edit|mix)\s*$/iu',
        '/\s+[\-–—]\s+(?:single version|album version|radio edit|video edit|extended mix|club mix|dance mix|original mix|mono version|stereo version)\s*$/iu',
        '/\s*\((?:from\s+)?[^)]*(?:soundtrack|motion picture|film|movie|original soundtrack)[^)]*\)\s*$/iu',
        '/\s*\((?:\d{4}\s+)?(?:remaster(?:ed)?|remastered\s+\d{4}|\d{4}\s+remaster)\)\s*$/iu',
        '/\s*\((?:single version|album version|radio edit|video edit|extended mix|club mix|dance mix|mono version|stereo version)\)\s*$/iu',
    ];

    do {
        $before = $clean;
        foreach ($patterns as $pattern) {
            $clean = preg_replace($pattern, '', $clean);
            $clean = trim(preg_replace('/\s+/', ' ', $clean));
        }
    } while ($clean !== $before && $clean !== '');

    return $clean !== '' ? $clean : $original;
}

function public_np_track_keys($track) {
    $keys = [];
    $id = strtolower(trim((string)($track['id'] ?? $track['spotify_track_id'] ?? '')));
    if ($id !== '') {
        $keys[] = 'id:' . $id;
    }

    $title = public_np_normalise_track_text($track['title'] ?? $track['song_title'] ?? '');
    $artist = public_np_normalise_track_text($track['artist'] ?? '');
    if ($title !== '' || $artist !== '') {
        $keys[] = 'txt:' . $title . '|' . $artist;
    }

    return array_values(array_unique($keys));
}

function public_np_track_seen($track, $seen) {
    foreach (public_np_track_keys($track) as $key) {
        if (isset($seen[$key])) return true;
    }
    return false;
}

function public_np_mark_track_seen($track, &$seen) {
    foreach (public_np_track_keys($track) as $key) {
        $seen[$key] = true;
    }
}

function public_np_public_track($track, $status, $playedAt = '') {
    $rawTitle = trim((string)($track['title'] ?? $track['song_title'] ?? ''));
    $title = public_np_clean_display_title($rawTitle);
    $artist = trim((string)($track['artist'] ?? ''));
    $id = trim((string)($track['id'] ?? $track['spotify_track_id'] ?? ''));
    $image = trim((string)($track['image'] ?? $track['spotify_album_image'] ?? ''));
    $url = trim((string)($track['url'] ?? $track['spotify_track_url'] ?? ''));
    if ($url === '' && $id !== '' && strpos($id, 'local:') !== 0) {
        $url = 'https://open.spotify.com/track/' . rawurlencode($id);
    }
    return [
        'id' => $id,
        'title' => $title !== '' ? $title : 'Unknown track',
        'original_title' => ($rawTitle !== '' && $rawTitle !== $title) ? $rawTitle : '',
        'title_cleaned' => ($rawTitle !== '' && $rawTitle !== $title),
        'artist' => $artist,
        'image' => $image,
        'url' => $url,
        'deck' => (string)($track['deck'] ?? ''),
        'progress_ms' => isset($track['progress_ms']) ? (int)$track['progress_ms'] : null,
        'duration_ms' => isset($track['duration_ms']) ? (int)$track['duration_ms'] : null,
        'status' => $status,
        'played_at' => $playedAt,
    ];
}

function public_np_best_spotify_image($images) {
    if (!is_array($images) || !$images) return '';
    usort($images, function($a, $b) {
        return ((int)($b['width'] ?? 0)) <=> ((int)($a['width'] ?? 0));
    });
    return (string)($images[0]['url'] ?? '');
}

function public_np_track_from_playback($playback, $deckLabel = '') {
    if (!is_array($playback) || empty($playback['is_playing'])) return null;
    $item = $playback['item'] ?? null;
    if (!is_array($item)) return null;
    $title = trim((string)($item['name'] ?? ''));
    $id = trim((string)($item['id'] ?? ''));
    if ($title === '' && $id === '') return null;

    $artists = [];
    foreach (($item['artists'] ?? []) as $artist) {
        if (!empty($artist['name'])) $artists[] = (string)$artist['name'];
    }

    $images = $item['album']['images'] ?? [];
    $image = public_np_best_spotify_image($images);

    return [
        'id' => $id,
        'title' => $title,
        'artist' => implode(', ', $artists),
        'image' => $image,
        'url' => (string)($item['external_urls']['spotify'] ?? ($id !== '' ? 'https://open.spotify.com/track/' . $id : '')),
        'deck' => $deckLabel,
        'progress_ms' => isset($playback['progress_ms']) ? (int)$playback['progress_ms'] : null,
        'duration_ms' => isset($item['duration_ms']) ? (int)$item['duration_ms'] : null,
    ];
}

function public_np_json_setting($key, $default = []) {
    $raw = public_np_setting($key, '');
    if ($raw === '') return $default;
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : $default;
}

function public_np_estimated_loaded_position_ms($loaded) {
    if (!is_array($loaded) || empty($loaded['id'])) return null;
    if (isset($loaded['paused_position_ms']) && $loaded['paused_position_ms'] !== null) {
        return max(0, (int)$loaded['paused_position_ms']);
    }
    if (!isset($loaded['position_base_ms']) || $loaded['position_base_ms'] === null) return null;
    $position = max(0, (int)$loaded['position_base_ms']);
    $updated = isset($loaded['position_updated_at']) ? (int)$loaded['position_updated_at'] : 0;
    if ($updated > 0 && empty($loaded['resume_locked'])) {
        $position += max(0, time() - $updated) * 1000;
    }
    return $position;
}

function public_np_track_ids_match($a, $b) {
    $a = trim((string)$a);
    $b = trim((string)$b);
    if ($a === '' || $b === '') return false;
    $a = str_replace('spotify:track:', '', $a);
    $b = str_replace('spotify:track:', '', $b);
    return $a === $b;
}

function public_np_track_from_loaded($loaded, $deckLabel = '') {
    if (!is_array($loaded) || empty($loaded['id'])) return null;
    $id = trim((string)($loaded['id'] ?? ''));
    $title = trim((string)($loaded['title'] ?? $loaded['song_title'] ?? ''));
    if ($id === '' && $title === '') return null;

    return public_np_upgrade_loaded_track_artwork([
        'id' => $id,
        'title' => $title,
        'artist' => trim((string)($loaded['artist'] ?? '')),
        'image' => trim((string)($loaded['image'] ?? $loaded['spotify_album_image'] ?? '')),
        'url' => trim((string)($loaded['url'] ?? $loaded['spotify_track_url'] ?? '')),
        'deck' => $deckLabel,
        'progress_ms' => public_np_estimated_loaded_position_ms($loaded),
        'duration_ms' => isset($loaded['duration_ms']) ? (int)$loaded['duration_ms'] : null,
    ]);
}

function public_np_spotify_track_id($track) {
    $id = trim((string)($track['id'] ?? $track['spotify_track_id'] ?? $track['spotify_uri'] ?? ''));
    if ($id === '' || strpos($id, 'local:') === 0) return '';
    if (strpos($id, 'spotify:track:') === 0) return substr($id, strlen('spotify:track:'));
    if (preg_match('~/track/([A-Za-z0-9]+)~', $id, $m)) return $m[1];
    if (preg_match('~^[A-Za-z0-9]{12,}$~', $id)) return $id;
    return '';
}

function public_np_cached_artwork_for_track_id($trackId) {
    $trackId = public_np_spotify_track_id(['id' => $trackId]);
    if ($trackId === '') return '';

    // v2 deliberately avoids older cached values that may have been saved from a
    // small loaded-deck thumbnail. Prefer a fresh Spotify track lookup where possible.
    $settingKey = 'display_spotify_artwork_v2_' . md5($trackId);
    $cached = public_np_setting($settingKey, '');
    if ($cached !== '') return $cached;

    if (function_exists('dttd_spotify_config_loaded') && dttd_spotify_config_loaded() && function_exists('dttd_spotify_user_access_token') && function_exists('dttd_spotify_http_get')) {
        try {
            $token = dttd_spotify_user_access_token();
            $data = dttd_spotify_http_get('https://api.spotify.com/v1/tracks/' . rawurlencode($trackId), [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ]);
            $image = public_np_best_spotify_image($data['album']['images'] ?? []);
            if ($image !== '') {
                public_np_set($settingKey, $image);
                return $image;
            }
        } catch (Throwable $e) {}
    }

    if (public_np_table_exists('spotify_track_cache')) {
        try {
            $stmt = db()->prepare("
                SELECT artwork_url
                FROM spotify_track_cache
                WHERE spotify_uri IN (?, ?, ?)
                ORDER BY last_seen_at DESC, id DESC
                LIMIT 1
            ");
            $stmt->execute([$trackId, 'spotify:track:' . $trackId, 'https://open.spotify.com/track/' . $trackId]);
            $image = trim((string)($stmt->fetchColumn() ?: ''));
            if ($image !== '') {
                public_np_set($settingKey, $image);
                return $image;
            }
        } catch (Throwable $e) {}
    }

    // Last chance: old cache key from the previous patch, but only after trying
    // Spotify/cache-table sources first.
    $oldCached = public_np_setting('display_spotify_artwork_' . md5($trackId), '');
    return $oldCached;
}

function public_np_upgrade_loaded_track_artwork($track) {
    if (!is_array($track)) return $track;
    $trackId = public_np_spotify_track_id($track);
    if ($trackId === '') return $track;

    $betterImage = public_np_cached_artwork_for_track_id($trackId);
    if ($betterImage !== '') $track['image'] = $betterImage;

    if (empty($track['url'])) {
        $track['url'] = 'https://open.spotify.com/track/' . $trackId;
    }

    return $track;
}

function public_np_loaded_track_looks_live($loaded) {
    if (!is_array($loaded) || empty($loaded['id'])) return false;

    $startedAt = isset($loaded['playback_started_at']) ? (int)$loaded['playback_started_at'] : 0;
    if ($startedAt <= 0) return false;

    // If the mixer has explicitly stored a paused position or resume lock, do not treat
    // the loaded track as currently playing.
    if (isset($loaded['paused_position_ms']) && $loaded['paused_position_ms'] !== null) return false;
    if (!empty($loaded['resume_locked'])) return false;

    $now = time();
    if ($startedAt > $now + 60) return false;

    $expectedFinish = isset($loaded['expected_finish_at']) ? (int)$loaded['expected_finish_at'] : 0;
    if ($expectedFinish > 0) {
        return $now <= ($expectedFinish + 180);
    }

    $duration = isset($loaded['duration_ms']) ? (int)$loaded['duration_ms'] : 0;
    if ($duration > 0) {
        return ($now - $startedAt) <= (int)ceil($duration / 1000) + 180;
    }

    // Last fallback for tracks without a duration: allow a generous DJ-track window.
    return ($now - $startedAt) <= 60 * 60 * 6;
}

function public_np_deck_current_candidate($deck, $deviceId, $playback, $loaded) {
    $deck = strtolower((string)$deck) === 'b' ? 'b' : 'a';
    $deckLabel = strtoupper($deck);
    $deviceId = trim((string)$deviceId);
    $loaded = is_array($loaded) ? $loaded : [];
    $candidate = null;
    $score = null;
    $startedAt = isset($loaded['playback_started_at']) ? (int)$loaded['playback_started_at'] : 0;

    // Local/MPD tracks are tracked by the mixer state rather than Spotify playback.
    if (!empty($loaded['local_is_playing'])) {
        $candidate = public_np_track_from_loaded($loaded, $deckLabel);
        $score = public_np_estimated_loaded_position_ms($loaded);
    }

    // Spotify can be slow to confirm the exact deck/device via the Web API. The mixer
    // already records playback_started_at/expected_finish_at when a play command is sent,
    // so use that as a display-safe fallback when the loaded deck state still looks live.
    if (!$candidate && public_np_loaded_track_looks_live($loaded)) {
        $candidate = public_np_track_from_loaded($loaded, $deckLabel);
        $score = public_np_estimated_loaded_position_ms($loaded);
    }

    if (is_array($playback) && !empty($playback['is_playing'])) {
        $activeDevice = (string)($playback['device']['id'] ?? '');
        $currentId = (string)($playback['item']['id'] ?? '');
        $loadedId = (string)($loaded['id'] ?? '');
        $sameDevice = $deviceId !== '' && $activeDevice === $deviceId;
        $sameLoadedTrack = $loadedId !== '' && public_np_track_ids_match($currentId, $loadedId);

        if ($sameDevice && $sameLoadedTrack) {
            $loadedTrack = public_np_track_from_loaded($loaded, $deckLabel);
            $playbackTrack = public_np_track_from_playback($playback, $deckLabel);
            $candidate = $loadedTrack ?: $playbackTrack;
            if ($candidate && $playbackTrack) {
                if (!empty($playbackTrack['image'])) $candidate['image'] = $playbackTrack['image'];
                if (!empty($playbackTrack['url'])) $candidate['url'] = $playbackTrack['url'];
            }
            if ($candidate) {
                if (isset($playback['progress_ms'])) $candidate['progress_ms'] = (int)$playback['progress_ms'];
                if (isset($playback['item']['duration_ms'])) $candidate['duration_ms'] = (int)$playback['item']['duration_ms'];
            }
            $score = isset($playback['progress_ms']) ? (int)$playback['progress_ms'] : public_np_estimated_loaded_position_ms($loaded);
        } elseif ($sameDevice && empty($candidate)) {
            // Fallback for Spotify playback that is genuinely on the assigned deck device
            // but has not yet been mirrored into the mixer loaded-track setting.
            $candidate = public_np_track_from_playback($playback, $deckLabel);
            $score = isset($playback['progress_ms']) ? (int)$playback['progress_ms'] : 0;
        }
    }

    if (!$candidate) return null;
    $candidate['deck'] = $deckLabel;
    $candidate['_score_ms'] = max(0, (int)($score ?? 0));
    $candidate['_started_at'] = $startedAt;
    return $candidate;
}

function public_np_current_spotify_track() {
    if (!function_exists('dttd_spotify_config_loaded') || !dttd_spotify_config_loaded()) return null;

    $deviceA = public_np_setting('spotify_mixer_device_a', '');
    $deviceB = public_np_setting('spotify_mixer_device_b', '');
    $loadedA = public_np_json_setting('spotify_mixer_loaded_a', []);
    $loadedB = public_np_json_setting('spotify_mixer_loaded_b', []);
    $playbackA = null;
    $playbackB = null;

    try { $playbackA = dttd_spotify_current_playback_for_deck('a'); } catch (Throwable $e) { $playbackA = null; }
    $shareProfile = function_exists('dttd_spotify_decks_share_profile') ? dttd_spotify_decks_share_profile() : true;
    if ($shareProfile) {
        $playbackB = $playbackA;
    } else {
        try { $playbackB = dttd_spotify_current_playback_for_deck('b'); } catch (Throwable $e) { $playbackB = null; }
    }

    $candidates = [];
    $candidateA = public_np_deck_current_candidate('a', $deviceA, $playbackA, $loadedA);
    if ($candidateA) $candidates[] = $candidateA;
    $candidateB = public_np_deck_current_candidate('b', $deviceB, $playbackB, $loadedB);
    if ($candidateB) $candidates[] = $candidateB;

    if ($candidates) {
        usort($candidates, function($a, $b) {
            $scoreCompare = ((int)($b['_score_ms'] ?? 0)) <=> ((int)($a['_score_ms'] ?? 0));
            if ($scoreCompare !== 0) return $scoreCompare;
            return ((int)($a['_started_at'] ?? 0)) <=> ((int)($b['_started_at'] ?? 0));
        });
        $current = $candidates[0];
        unset($current['_score_ms'], $current['_started_at']);
        return $current;
    }

    // Last-resort fallback: raw account playback only. The deck-state route above is
    // preferred because it avoids showing a short cue/preview track as the public current track
    // when the other deck has been playing longer.
    $fallbacks = [
        ['deck' => 'A', 'device' => $deviceA, 'playback' => $playbackA],
        ['deck' => 'B', 'device' => $deviceB, 'playback' => $playbackB],
    ];

    foreach ($fallbacks as $candidate) {
        $playback = $candidate['playback'];
        if (!is_array($playback) || empty($playback['is_playing'])) continue;
        $activeDevice = (string)($playback['device']['id'] ?? '');
        $deckDevice = trim((string)$candidate['device']);
        if ($deckDevice !== '' && $activeDevice === $deckDevice) {
            $track = public_np_track_from_playback($playback, $candidate['deck']);
            if ($track) return $track;
        }
    }

    foreach ($fallbacks as $candidate) {
        $track = public_np_track_from_playback($candidate['playback'], $candidate['deck']);
        if ($track) return $track;
    }

    return null;
}


function public_np_loaded_deck_tracks() {
    $out = [];
    foreach (['a' => 'A', 'b' => 'B'] as $deck => $label) {
        $loaded = public_np_json_setting('spotify_mixer_loaded_' . $deck, []);
        $track = public_np_track_from_loaded($loaded, $label);
        if (!$track) continue;
        $track['deck'] = $label;
        $track['_is_live'] = public_np_loaded_track_looks_live($loaded) || !empty($loaded['local_is_playing']);
        $out[] = $track;
    }
    return $out;
}

function public_np_up_next_track($current) {
    $loaded = public_np_loaded_deck_tracks();
    if (!$loaded) return null;

    $currentDeck = strtoupper(trim((string)($current['deck'] ?? '')));
    $currentId = trim((string)($current['id'] ?? ''));

    foreach ($loaded as $track) {
        $deck = strtoupper(trim((string)($track['deck'] ?? '')));
        $id = trim((string)($track['id'] ?? ''));

        if ($currentDeck !== '' && $deck === $currentDeck) continue;
        if ($currentId !== '' && $id !== '' && public_np_track_ids_match($currentId, $id)) continue;
        if (!empty($track['_is_live'])) continue;

        unset($track['_is_live']);
        return $track;
    }

    return null;
}


$requestedEventId = isset($_GET['event_id']) ? (int)$_GET['event_id'] : 0;
$event = public_np_active_event($requestedEventId);
if (!$event || empty($event['id'])) {
    public_np_json(['ok' => true, 'active_event' => false, 'tracks' => [], 'generated_at' => date('c')]);
}

$eventId = (int)$event['id'];
$cacheKey = 'public_now_playing_cache_' . $eventId;
$cacheTtl = 6;
$cachedRaw = public_np_setting($cacheKey, '');
if ($cachedRaw !== '') {
    $cached = json_decode($cachedRaw, true);
    if (is_array($cached) && isset($cached['_cache_time']) && ((int)$cached['_cache_time'] >= time() - $cacheTtl)) {
        unset($cached['_cache_time']);
        public_np_json($cached);
    }
}

$current = public_np_current_spotify_track();
$upNext = public_np_up_next_track($current);
$historyRows = function_exists('dttd_history_public_track_rows') ? dttd_history_public_track_rows($eventId, 10) : [];
$tracks = [];
$seen = [];

if ($current) {
    $currentPublic = public_np_public_track($current, 'current', date('c'));
    if (public_np_track_keys($currentPublic)) {
        public_np_mark_track_seen($currentPublic, $seen);
        $tracks[] = $currentPublic;
    }
}

foreach ($historyRows as $row) {
    $track = public_np_public_track($row, $current ? 'recent' : (empty($tracks) ? 'latest' : 'recent'), (string)($row['created_at'] ?? $row['played_at'] ?? ''));
    if (!public_np_track_keys($track) || public_np_track_seen($track, $seen)) continue;
    public_np_mark_track_seen($track, $seen);
    $tracks[] = $track;
    if (count($tracks) >= 6) break;
}

$payload = [
    'ok' => true,
    'active_event' => true,
    'event_id' => $eventId,
    'has_live_current' => (bool)$current,
    'has_up_next' => (bool)$upNext,
    'up_next' => $upNext ? public_np_public_track($upNext, 'up_next', '') : null,
    'track_count' => count($tracks),
    'tracks' => $tracks,
    'generated_at' => date('c'),
];

public_np_set($cacheKey, json_encode($payload + ['_cache_time' => time()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
public_np_json($payload);
