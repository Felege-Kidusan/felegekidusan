<?php
/**
 * Public gallery API — active photos only. No member PII. No writes.
 * Also serves image bytes (action=img / action=file) so photos still
 * display when the file sits in the older folder outside the website.
 */
if (!defined('WBWS_API_REQUEST')) {
    define('WBWS_API_REQUEST', true);
}
require_once __DIR__ . '/admin/config.php';
require_once __DIR__ . '/admin/backend/services/GalleryService.php';

$action = preg_replace('/[^a-z_]/', '', (string)($_GET['action'] ?? 'boot'));

if ($action === 'file') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        exit;
    }
    \App\Services\GalleryService::rescueStrayFiles();
    \App\Services\GalleryService::streamByName((string)($_GET['sub'] ?? 'gallery'), (string)($_GET['name'] ?? ''));
}

if ($action === 'img') {
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        exit;
    }
    if (!isset($conn) || $conn->connect_error) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Gallery unavailable';
        exit;
    }
    \App\Services\GalleryService::ensureSchema($conn);
    \App\Services\GalleryService::rescueStrayFiles();
    \App\Services\GalleryService::streamById($conn, (int)($_GET['id'] ?? 0), (string)($_GET['s'] ?? 't'));
}

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['status' => 'error', 'message' => 'Use GET.']);
    exit;
}

if (!isset($conn) || $conn->connect_error) {
    echo json_encode(['status' => 'error', 'message' => 'Gallery is temporarily unavailable.', 'albums' => [], 'items' => [], 'featured' => []]);
    exit;
}

\App\Services\GalleryService::ensureSchema($conn);
\App\Services\GalleryService::rescueStrayFiles();

try {
    if ($action === 'albums') {
        echo json_encode([
            'status' => 'success',
            'albums' => \App\Services\GalleryService::publicAlbums($conn),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'featured') {
        echo json_encode([
            'status' => 'success',
            'featured' => \App\Services\GalleryService::publicFeatured($conn, 8),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'photos') {
        $album = (int)($_GET['album'] ?? 0);
        $page = (int)($_GET['page'] ?? 1);
        $limit = (int)($_GET['limit'] ?? 18);
        $pack = \App\Services\GalleryService::publicPhotos($conn, $album, $page, $limit);
        echo json_encode([
            'status' => 'success',
            'items' => $pack['items'],
            'has_more' => $pack['has_more'],
            'total' => $pack['total'],
            'page' => max(1, $page),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $albums = \App\Services\GalleryService::publicAlbums($conn);
    $featured = \App\Services\GalleryService::publicFeatured($conn, 8);
    $pack = \App\Services\GalleryService::publicPhotos($conn, 0, 1, 18);
    echo json_encode([
        'status' => 'success',
        'albums' => $albums,
        'featured' => $featured,
        'items' => $pack['items'],
        'has_more' => $pack['has_more'],
        'total' => $pack['total'],
        'page' => 1,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('public_gallery: ' . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Could not load the gallery right now.',
        'albums' => [],
        'featured' => [],
        'items' => [],
        'has_more' => false,
        'total' => 0,
    ]);
}
