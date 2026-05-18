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
