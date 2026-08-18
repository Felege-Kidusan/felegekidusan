<?php
require_once __DIR__ . '/../school_config.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: no-store');
echo json_encode([
    'name' => SCHOOL_NAME_SHORT,
    'short_name' => SCHOOL_NAME_SHORT,
    'description' => 'Use the website or the FKSS app.',
    'start_url' => '/admin/',
    'scope' => '/admin/',
    'display' => 'browser',
    'background_color' => '#ffffff',
    'theme_color' => '#5A1212',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
