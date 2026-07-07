<?php
/**
 * Lightweight Spotify API usage counters for DTTD.
 *
 * This intentionally records endpoint categories and timings only. It never
 * stores access tokens, request bodies, guest search text, or full query strings.
 */

if (!function_exists('dttd_spotify_api_usage_table_available')) {
    function dttd_spotify_api_usage_table_available() {
        static $available = null;
        if ($available !== null) return $available;
        try {
            db()->query('SELECT 1 FROM spotify_api_usage_log LIMIT 1');
            $available = true;
        } catch (Throwable $e) {
            $available = false;
        }
        return $available;
    }
}

if (!function_exists('dttd_spotify_api_endpoint_key')) {
    function dttd_spotify_api_endpoint_key($url) {
        $parts = @parse_url((string)$url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        $methodHint = strtolower((string)($parts['method'] ?? ''));

        if (strpos($host, 'accounts.spotify.com') !== false) return 'token';
        if ($path === '/v1/search') return 'search';
        if ($path === '/v1/me') return 'me';
        if ($path === '/v1/me/playlists') return 'playlists';
        if (preg_match('#^/v1/playlists/[^/]+/tracks#', $path)) return 'playlist_tracks';
        if ($path === '/v1/me/player/devices') return 'devices';
        if ($path === '/v1/me/player') return 'player_state';
        if ($path === '/v1/me/player/queue') return 'queue';
        if ($path === '/v1/me/player/play') return 'play';
        if ($path === '/v1/me/player/pause') return 'pause';
        if ($path === '/v1/me/player/seek') return 'seek';
        if (preg_match('#^/v1/tracks/[^/]+#', $path)) return 'track_lookup';
        return $path !== '' ? trim(str_replace('/v1/', '', $path), '/') : 'unknown';
    }
}

if (!function_exists('dttd_spotify_api_endpoint_path')) {
    function dttd_spotify_api_endpoint_path($url) {
        $parts = @parse_url((string)$url);
        $host = strtolower((string)($parts['host'] ?? ''));
        $path = (string)($parts['path'] ?? '');
        if ($path === '') return $host ?: 'unknown';
        // Remove IDs from noisy paths but keep the useful endpoint shape.
        $path = preg_replace('#/v1/playlists/[^/]+/tracks#', '/v1/playlists/{playlist}/tracks', $path);
        $path = preg_replace('#/v1/tracks/[^/]+#', '/v1/tracks/{track}', $path);
        return $path;
    }
}

if (!function_exists('dttd_spotify_api_retry_after')) {
    function dttd_spotify_api_retry_after($headers) {
        $headers = (string)$headers;
        if (preg_match('/Retry-After:\s*(\d+)/i', $headers, $m)) {
            return (int)$m[1];
        }
        return null;
    }
}

if (!function_exists('dttd_spotify_api_usage_log')) {
    function dttd_spotify_api_usage_log($method, $url, $status, $durationMs = null, array $context = []) {
        if (!function_exists('db') || !dttd_spotify_api_usage_table_available()) return false;

        $method = strtoupper(substr((string)$method, 0, 10));
        $status = (int)$status;
        $durationMs = $durationMs === null ? null : max(0, (int)$durationMs);
        $retryAfter = isset($context['retry_after']) && $context['retry_after'] !== null ? (int)$context['retry_after'] : null;
        $endpointKey = dttd_spotify_api_endpoint_key($url);
        $endpointPath = dttd_spotify_api_endpoint_path($url);

        try {
            $stmt = db()->prepare("\n                INSERT INTO spotify_api_usage_log\n                    (logged_at, source, action, method, endpoint_key, endpoint_path, http_status, duration_ms, retry_after_seconds, deck, account_role, account_label, cache_status)\n                VALUES\n                    (NOW(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)\n            ");
            return $stmt->execute([
                substr((string)($context['source'] ?? 'server'), 0, 50),
                substr((string)($context['action'] ?? ''), 0, 80),
                $method,
                substr($endpointKey, 0, 80),
                substr($endpointPath, 0, 160),
                $status ?: null,
                $durationMs,
                $retryAfter,
                substr((string)($context['deck'] ?? ''), 0, 10),
                substr((string)($context['account_role'] ?? ''), 0, 50),
                substr((string)($context['account_label'] ?? ''), 0, 120),
                substr((string)($context['cache_status'] ?? ''), 0, 40),
            ]);
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('dttd_spotify_api_usage_summary')) {
    function dttd_spotify_api_usage_summary($minutes = 30) {
        $minutes = max(1, min(1440, (int)$minutes));
        $empty = [
            'available' => false,
            'window_minutes' => $minutes,
            'total' => 0,
            'last_30_seconds' => 0,
            'rate_limited' => 0,
            'by_endpoint' => [],
            'by_source' => [],
            'recent_429' => null,
        ];
        if (!function_exists('db') || !dttd_spotify_api_usage_table_available()) return $empty;

        try {
            $totalStmt = db()->prepare("\n                SELECT\n                    COUNT(*) AS total,\n                    SUM(CASE WHEN logged_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND) THEN 1 ELSE 0 END) AS last_30_seconds,\n                    SUM(CASE WHEN http_status = 429 THEN 1 ELSE 0 END) AS rate_limited\n                FROM spotify_api_usage_log\n                WHERE logged_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)\n            ");
            $totalStmt->bindValue(1, $minutes, PDO::PARAM_INT);
            $totalStmt->execute();
            $totals = $totalStmt->fetch() ?: [];

            $endpointStmt = db()->prepare("\n                SELECT endpoint_key, COUNT(*) AS calls,\n                       SUM(CASE WHEN logged_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND) THEN 1 ELSE 0 END) AS last_30_seconds,\n                       SUM(CASE WHEN http_status = 429 THEN 1 ELSE 0 END) AS rate_limited,\n                       ROUND(AVG(duration_ms)) AS avg_ms,\n                       MAX(logged_at) AS last_seen\n                FROM spotify_api_usage_log\n                WHERE logged_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)\n                GROUP BY endpoint_key\n                ORDER BY calls DESC, endpoint_key ASC\n                LIMIT 12\n            ");
            $endpointStmt->bindValue(1, $minutes, PDO::PARAM_INT);
            $endpointStmt->execute();

            $sourceStmt = db()->prepare("\n                SELECT COALESCE(NULLIF(source, ''), 'server') AS source, COUNT(*) AS calls\n                FROM spotify_api_usage_log\n                WHERE logged_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)\n                GROUP BY COALESCE(NULLIF(source, ''), 'server')\n                ORDER BY calls DESC\n                LIMIT 8\n            ");
            $sourceStmt->bindValue(1, $minutes, PDO::PARAM_INT);
            $sourceStmt->execute();

            $rateStmt = db()->query("\n                SELECT logged_at, endpoint_key, retry_after_seconds\n                FROM spotify_api_usage_log\n                WHERE http_status = 429\n                ORDER BY logged_at DESC\n                LIMIT 1\n            ");

            return [
                'available' => true,
                'window_minutes' => $minutes,
                'total' => (int)($totals['total'] ?? 0),
                'last_30_seconds' => (int)($totals['last_30_seconds'] ?? 0),
                'rate_limited' => (int)($totals['rate_limited'] ?? 0),
                'by_endpoint' => array_map(function ($row) {
                    return [
                        'endpoint' => (string)($row['endpoint_key'] ?? ''),
                        'calls' => (int)($row['calls'] ?? 0),
                        'last_30_seconds' => (int)($row['last_30_seconds'] ?? 0),
                        'rate_limited' => (int)($row['rate_limited'] ?? 0),
                        'avg_ms' => isset($row['avg_ms']) ? (int)$row['avg_ms'] : null,
                        'last_seen' => (string)($row['last_seen'] ?? ''),
                    ];
                }, $endpointStmt->fetchAll() ?: []),
                'by_source' => array_map(function ($row) {
                    return ['source' => (string)($row['source'] ?? ''), 'calls' => (int)($row['calls'] ?? 0)];
                }, $sourceStmt->fetchAll() ?: []),
                'recent_429' => ($rateStmt && ($r = $rateStmt->fetch())) ? [
                    'logged_at' => (string)($r['logged_at'] ?? ''),
                    'endpoint' => (string)($r['endpoint_key'] ?? ''),
                    'retry_after_seconds' => isset($r['retry_after_seconds']) ? (int)$r['retry_after_seconds'] : null,
                ] : null,
            ];
        } catch (Throwable $e) {
            return $empty;
        }
    }
}
