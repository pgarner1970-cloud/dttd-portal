<?php
function dttd_site_root(): string {
    return dirname(__DIR__);
}

function dttd_upload_dir(string $subdir = ''): string {
    $subdir = trim($subdir, "/\\");
    $path = dttd_site_root() . DIRECTORY_SEPARATOR . 'uploads';

    if ($subdir !== '') {
        $path .= DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $subdir);
    }

    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }

    return $path;
}

function dttd_event_upload_dir(): string {
    return dttd_upload_dir('events');
}

function dttd_public_upload_url(string $path): string {
    $path = trim((string)$path);

    if ($path === '') {
        return '';
    }

    if (preg_match('~^https?://~i', $path)) {
        return $path;
    }

    $path = ltrim($path, '/');

    if (str_starts_with($path, 'uploads/')) {
        return 'https://dancethruthedecades.co.uk/' . $path;
    }

    if (str_contains($path, '/')) {
        return 'https://dancethruthedecades.co.uk/' . $path;
    }

    return 'https://dancethruthedecades.co.uk/uploads/events/' . $path;
}
