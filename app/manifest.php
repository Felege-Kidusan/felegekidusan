<?php
require_once __DIR__ . '/../school_config.php';
header('Content-Type: application/manifest+json');
header('Cache-Control: public, max-age=86400');

echo json_encode([
    'name' => PWA_NAME,
    'short_name' => PWA_SHORT_NAME,
    'description' => PWA_DESCRIPTION,
    'start_url' => '/app/',
    'display' => 'standalone',
    'background_color' => '#f0fdf4',
    'theme_color' => THEME_PRIMARY,
    'orientation' => 'portrait',
    'icons' => [
        ['src' => '/app/icons/icon-192.png', 'sizes' => '192x192', 'type' => 'image/png'],
        ['src' => '/app/icons/icon-512.png', 'sizes' => '512x512', 'type' => 'image/png'],
    ]
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
