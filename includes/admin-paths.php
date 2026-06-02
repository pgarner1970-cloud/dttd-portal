<?php
/**
 * Admin path helpers.
 *
 * These allow the same admin codebase to work either at:
 * - https://dancethruthedecades.co.uk/admin/
 * - https://dj.dancethruthedecades.co.uk/
 */
function admin_url(string $path = ''): string {
    $path = ltrim($path, '/');

    // Admin pages should stay explicit. Public clean URLs belong to the public site only.
    $dttd_admin_route_aliases = [
        'events' => 'events.php',
        'venues' => 'venues.php',
        'partners' => 'partners.php',
        'sponsors' => 'sponsors.php',
        'event-sponsors' => 'event-sponsors.php',
        'event-photos' => 'event-photos.php',
        'requests' => 'requests.php',
        'settings' => 'settings.php',
        'tools' => 'tools.php',
    ];

    if (isset($dttd_admin_route_aliases[$path])) {
        $path = $dttd_admin_route_aliases[$path];
    }

    $host = $_SERVER['HTTP_HOST'] ?? '';

    if (stripos($host, 'dj.') === 0) {
        return '/' . $path;
    }

    return '/admin/' . $path;
}

function admin_redirect(string $path = ''): void {
    header('Location: ' . admin_url($path));
    exit;
}
