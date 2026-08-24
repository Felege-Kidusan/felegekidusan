<?php
/**
 * Gallery storage — one place for upload, thumbs, public listing, and serving.
 * Public pages never talk to the database directly.
 *
 * Older CMS uploads were written to dirname(ROOT_PATH)/uploads/gallery
 * (outside the website). This class finds those files and serves them.
 */
namespace App\Services;

class GalleryService
{
    public const MAX_BYTES = 8388608; // 8MB
    public const THUMB_W = 640;
    public const ORIG_MAX_W = 1920;
    public const PAGE_MAX = 48;

    /** @return list<string> */
    public static function allowedExt(): array
    {
        return ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    }

    public static function diskRoot(): string
    {
        return rtrim((string)(defined('ROOT_PATH') ? ROOT_PATH : dirname(__DIR__, 3)), '/');
    }

    public static function uploadDir(string $sub = 'gallery'): string
    {
        $sub = self::safeSub($sub);
        $dir = self::diskRoot() . '/uploads/' . $sub;
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $thumbs = $dir . '/thumbs';
        if (!is_dir($thumbs)) {
            @mkdir($thumbs, 0755, true);
        }
        return $dir;
    }

    /** Copy files saved to the old (wrong) folders into the public uploads folder. */
    public static function rescueStrayFiles(): void
    {
        foreach (['gallery', 'teachers'] as $sub) {
            $right = self::uploadDir($sub);
            foreach (self::strayDirs($sub) as $wrong) {
                if (!is_dir($wrong) || realpath($wrong) === realpath($right)) {
                    continue;
                }
                $found = array_merge(
                    glob($wrong . '/*.{jpg,jpeg,png,gif,webp,JPG,JPEG,PNG,GIF,WEBP}', GLOB_BRACE) ?: [],
                    glob($wrong . '/*.jpg') ?: [],
                    glob($wrong . '/*.jpeg') ?: [],
                    glob($wrong . '/*.png') ?: [],
                    glob($wrong . '/*.gif') ?: [],
                    glob($wrong . '/*.webp') ?: []
                );
                foreach ($found as $src) {
                    if (!is_file($src)) {
                        continue;
                    }
                    $dest = $right . '/' . basename($src);
                    if (!is_file($dest)) {
                        @copy($src, $dest);
                        @chmod($dest, 0644);
                    }
                }
            }
        }
    }

    /** Compatibility hook; schema is deployment-managed by migrations 002/013. */
    public static function ensureSchema(\mysqli $conn): void
    {
    }

    /**
     * Save an uploaded image. Returns web paths or ['error' => msg].
     * @return array{ok:true,image:string,thumb:string}|array{error:string}|null
     */
    public static function saveUpload(string $field, string $sub = 'gallery')
    {
        if (!isset($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
            return null;
        }
        $err = (int)$_FILES[$field]['error'];
        if ($err !== UPLOAD_ERR_OK) {
            $map = [
                UPLOAD_ERR_INI_SIZE => 'File is larger than the server allows.',
                UPLOAD_ERR_FORM_SIZE => 'File is too large.',
                UPLOAD_ERR_PARTIAL => 'Upload was interrupted. Try again.',
                UPLOAD_ERR_NO_TMP_DIR => 'Server storage is not ready.',
                UPLOAD_ERR_CANT_WRITE => 'Could not write the file.',
            ];
            return ['error' => $map[$err] ?? 'Upload failed.'];
        }
        $tmp = (string)($_FILES[$field]['tmp_name'] ?? '');
        $actualSize = $tmp !== '' ? @filesize($tmp) : false;
        if ($tmp === '' || !is_uploaded_file($tmp) || $actualSize === false || $actualSize <= 0) {
            return ['error' => 'The uploaded image could not be verified.'];
        }
        if ($actualSize > self::MAX_BYTES) {
            return ['error' => 'Image is too large (max 8MB).'];
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = (string)$finfo->file($tmp);
        $mimeMap = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
        ];
        if (!isset($mimeMap[$mime])) {
            return ['error' => 'Use a JPG, PNG, GIF, or WebP image.'];
        }
        if (@getimagesize($tmp) === false) {
            return ['error' => 'That file is not a valid image.'];
        }

        $sub = self::safeSub($sub);
        $dir = self::uploadDir($sub);
        $base = $sub . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4));
        $origName = $base . '.jpg';
        $thumbName = $base . '.jpg';
        $origPath = $dir . '/' . $origName;
        $thumbPath = $dir . '/thumbs/' . $thumbName;

        if (!self::writeJpeg($tmp, $origPath, self::ORIG_MAX_W, 86)) {
            $ext = $mimeMap[$mime];
            $origName = $base . '.' . $ext;
            $origPath = $dir . '/' . $origName;
            if (!move_uploaded_file($tmp, $origPath)) {
                return ['error' => 'Could not save the image.'];
            }
            @chmod($origPath, 0644);
        }
        self::writeJpeg($origPath, $thumbPath, self::THUMB_W, 78);

        $webOrig = '/uploads/' . $sub . '/' . $origName;
        $webThumb = is_file($thumbPath) ? '/uploads/' . $sub . '/thumbs/' . $thumbName : $webOrig;
        return ['ok' => true, 'image' => $webOrig, 'thumb' => $webThumb];
    }

    public static function deleteByWebPath(?string $webPath): void
    {
        if (!$webPath) {
            return;
        }
        $webPath = self::normalizeWebPath((string)$webPath);
        if ($webPath === null) {
            return;
        }
        $full = self::diskRoot() . $webPath;
        $root = realpath(self::diskRoot() . '/uploads');
        $real = realpath($full);
        if ($root && $real && strpos($real, $root) === 0 && is_file($real)) {
            @unlink($real);
        }
        if (strpos($webPath, '/thumbs/') === false) {
            $thumb = preg_replace('#/([^/]+)$#', '/thumbs/$1', $webPath);
            if (is_string($thumb) && $thumb !== $webPath) {
                $thumb = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $thumb);
                self::deleteByWebPath($thumb);
            }
        }
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function publicAlbums(\mysqli $conn): array
    {
        $out = [];
        try {
            $sql = "SELECT c.id, c.name, c.name_am, COUNT(p.id) AS photo_count
                    FROM cms_gallery_categories c
                    INNER JOIN cms_gallery_photos p ON p.category_id = c.id AND p.is_active = 1
                    WHERE c.is_active = 1
                    GROUP BY c.id, c.name, c.name_am
                    ORDER BY c.sort_order, c.id";
            $res = $conn->query($sql);
            if ($res) {
                while ($row = $res->fetch_assoc()) {
                    $out[] = [
                        'id' => (int)$row['id'],
                        'name' => (string)$row['name'],
                        'name_am' => (string)($row['name_am'] ?? ''),
                        'photo_count' => (int)$row['photo_count'],
                    ];
                }
            }
        } catch (\Throwable $e) {
        }
        return $out;
    }

    /**
     * @return array{items:list<array<string,mixed>>,has_more:bool,total:int}
     */
    public static function publicPhotos(\mysqli $conn, int $albumId, int $page, int $limit): array
    {
        $limit = max(1, min(self::PAGE_MAX, $limit));
        $page = max(1, $page);
        $offset = ($page - 1) * $limit;
        $items = [];
        $total = 0;
        try {
            if ($albumId > 0) {
                $c = $conn->prepare("SELECT COUNT(*) n FROM cms_gallery_photos WHERE is_active=1 AND category_id=?");
                $c->bind_param('i', $albumId);
                $c->execute();
                $total = (int)($c->get_result()->fetch_assoc()['n'] ?? 0);
                $c->close();
                $stmt = $conn->prepare("SELECT id, image_path, thumb_path, caption, caption_am, is_featured, category_id
                    FROM cms_gallery_photos WHERE is_active=1 AND category_id=?
                    ORDER BY is_featured DESC, sort_order, id DESC LIMIT ? OFFSET ?");
                $stmt->bind_param('iii', $albumId, $limit, $offset);
            } else {
                $r = $conn->query("SELECT COUNT(*) n FROM cms_gallery_photos WHERE is_active=1");
                $total = $r ? (int)$r->fetch_assoc()['n'] : 0;
                $stmt = $conn->prepare("SELECT id, image_path, thumb_path, caption, caption_am, is_featured, category_id
                    FROM cms_gallery_photos WHERE is_active=1
                    ORDER BY is_featured DESC, sort_order, id DESC LIMIT ? OFFSET ?");
                $stmt->bind_param('ii', $limit, $offset);
            }
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $items[] = self::publicCard($row);
            }
            $stmt->close();
        } catch (\Throwable $e) {
            return ['items' => [], 'has_more' => false, 'total' => 0];
        }
        return ['items' => $items, 'has_more' => ($offset + count($items)) < $total, 'total' => $total];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public static function publicFeatured(\mysqli $conn, int $limit = 8): array
    {
        $limit = max(1, min(12, $limit));
        $items = [];
        try {
            $stmt = $conn->prepare("SELECT id, image_path, thumb_path, caption, caption_am, is_featured, category_id
                FROM cms_gallery_photos WHERE is_active=1 AND is_featured=1
                ORDER BY sort_order, id DESC LIMIT ?");
            $stmt->bind_param('i', $limit);
            $stmt->execute();
            $res = $stmt->get_result();
            while ($row = $res->fetch_assoc()) {
                $items[] = self::publicCard($row);
            }
            $stmt->close();
            if (!$items) {
                $stmt = $conn->prepare("SELECT id, image_path, thumb_path, caption, caption_am, is_featured, category_id
                    FROM cms_gallery_photos WHERE is_active=1
                    ORDER BY id DESC LIMIT ?");
                $stmt->bind_param('i', $limit);
                $stmt->execute();
                $res = $stmt->get_result();
                while ($row = $res->fetch_assoc()) {
                    $items[] = self::publicCard($row);
                }
                $stmt->close();
            }
        } catch (\Throwable $e) {
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    public static function publicCard(array $row): array
    {
        $id = (int)($row['id'] ?? 0);
        return [
            'id' => $id,
            'thumb' => self::publicServeUrl($id, 't'),
            'full' => self::publicServeUrl($id, 'f'),
            'caption' => (string)($row['caption'] ?? ''),
            'caption_am' => (string)($row['caption_am'] ?? ''),
            'featured' => (int)($row['is_featured'] ?? 0) === 1,
        ];
    }

    public static function publicServeUrl(int $id, string $size = 't'): string
    {
        $size = $size === 'f' ? 'f' : 't';
        return '/public_gallery.php?action=img&id=' . $id . '&s=' . $size;
    }

    /** Active public photo only. */
    public static function streamById(\mysqli $conn, int $id, string $size): void
    {
        if ($id < 1) {
            self::streamNotFound();
        }
        $size = $size === 'f' ? 'f' : 't';
        $row = null;
        try {
            $stmt = $conn->prepare('SELECT image_path, thumb_path FROM cms_gallery_photos WHERE id=? LIMIT 1');
            $stmt->bind_param('i', $id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
        } catch (\Throwable $e) {
            $row = null;
        }
        if (!$row) {
            self::streamNotFound();
        }
        $full = (string)($row['image_path'] ?? '');
        $thumb = (string)($row['thumb_path'] ?? '');
        $path = $size === 't' ? ($thumb !== '' ? $thumb : $full) : $full;
        self::streamResolved($path);
    }

    public static function streamByName(string $sub, string $name): void
    {
        $sub = self::safeSub($sub);
        $name = str_replace('\\', '/', $name);
        $name = ltrim($name, '/');
        self::streamResolved('/uploads/' . $sub . '/' . $name);
    }

    public static function resolveOnDisk(string $webPath): ?string
    {
        $webPath = self::normalizeWebPath($webPath);
        if ($webPath === null) {
            return null;
        }
        $root = self::diskRoot();
        $bases = [$root, dirname($root), $root . '/admin'];
        $relatives = [$webPath];
        $baseName = basename($webPath);
        $dirName = dirname($webPath);
        if (strpos($webPath, '/thumbs/') === false) {
            $relatives[] = $dirName . '/thumbs/' . $baseName;
            $jpgThumb = preg_replace('/\.(png|gif|webp)$/i', '.jpg', $baseName);
            if (is_string($jpgThumb) && $jpgThumb !== $baseName) {
                $relatives[] = $dirName . '/thumbs/' . $jpgThumb;
            }
        } else {
            $relatives[] = str_replace('/thumbs/', '/', $webPath);
        }

        $canonical = $root . $webPath;
        foreach ($bases as $base) {
            foreach ($relatives as $rel) {
                $full = $base . $rel;
                if (!is_file($full) || !is_readable($full)) {
                    continue;
                }
                if ($full !== $canonical && !is_file($canonical)) {
                    $dir = dirname($canonical);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0755, true);
                    }
                    @copy($full, $canonical);
                    @chmod($canonical, 0644);
                    if (is_file($canonical) && is_readable($canonical)) {
                        return $canonical;
                    }
                }
                return $full;
            }
        }
        return null;
    }

    public static function normalizeWebPath(string $webPath): ?string
    {
        $webPath = str_replace('\\', '/', trim($webPath));
        $webPath = explode('?', $webPath, 2)[0];
        if ($webPath === '' || strpos($webPath, '..') !== false) {
            return null;
        }
        if ($webPath[0] !== '/') {
            $webPath = '/' . $webPath;
        }
        if (!preg_match('#^/uploads/(gallery|teachers)/(thumbs/)?[A-Za-z0-9._-]+\.(jpe?g|png|gif|webp)$#i', $webPath)) {
            return null;
        }
        return $webPath;
    }

    /** @return list<string> */
    private static function strayDirs(string $sub): array
    {
        $sub = self::safeSub($sub);
        $root = self::diskRoot();
        return [
            dirname($root) . '/uploads/' . $sub,
            $root . '/admin/uploads/' . $sub,
        ];
    }

    private static function safeSub(string $sub): string
    {
        $sub = strtolower(preg_replace('/[^a-z]/i', '', $sub) ?? '');
        return in_array($sub, ['gallery', 'teachers'], true) ? $sub : 'gallery';
    }

    private static function streamResolved(string $webPath): void
    {
        $disk = self::resolveOnDisk($webPath);
        if ($disk === null) {
            self::streamNotFound();
        }
        $mime = 'application/octet-stream';
        if (class_exists('finfo')) {
            $f = new \finfo(FILEINFO_MIME_TYPE);
            $detected = (string)$f->file($disk);
            if ($detected !== '') {
                $mime = $detected;
            }
        } else {
            $ext = strtolower((string)pathinfo($disk, PATHINFO_EXTENSION));
            $map = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp'];
            $mime = $map[$ext] ?? $mime;
        }
        if (strpos($mime, 'image/') !== 0) {
            self::streamNotFound();
        }
        if (function_exists('header_remove')) {
            @header_remove('Content-Type');
        }
        header('Content-Type: ' . $mime);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: public, max-age=86400');
        header('Content-Length: ' . (string)filesize($disk));
        if (function_exists('ob_get_level')) {
            while (ob_get_level() > 0) {
                @ob_end_clean();
            }
        }
        readfile($disk);
        exit;
    }

    private static function streamNotFound(): void
    {
        http_response_code(404);
        header('Content-Type: image/svg+xml; charset=utf-8');
        header('Cache-Control: no-store');
        echo '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200"><rect width="200" height="200" fill="#3a1818"/><text x="100" y="112" text-anchor="middle" fill="#c4a574" font-size="40">+</text></svg>';
        exit;
    }

    private static function writeJpeg(string $src, string $dest, int $maxW, int $quality): bool
    {
        if (!function_exists('imagecreatetruecolor')) {
            return false;
        }
        $info = @getimagesize($src);
        if (!$info) {
            return false;
        }
        $srcW = (int)$info[0];
        $srcH = (int)$info[1];
        if ($srcW < 1 || $srcH < 1) {
            return false;
        }
        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $im = @imagecreatefromjpeg($src);
                break;
            case IMAGETYPE_PNG:
                $im = @imagecreatefrompng($src);
                break;
            case IMAGETYPE_GIF:
                $im = @imagecreatefromgif($src);
                break;
            case IMAGETYPE_WEBP:
                $im = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false;
                break;
            default:
                $im = false;
        }
        if (!$im) {
            return false;
        }
        $w = $srcW;
        $h = $srcH;
        if ($w > $maxW) {
            $h = (int)round($h * ($maxW / $w));
            $w = $maxW;
        }
        $out = imagecreatetruecolor($w, $h);
        if (!$out) {
            imagedestroy($im);
            return false;
        }
        $white = imagecolorallocate($out, 255, 255, 255);
        imagefilledrectangle($out, 0, 0, $w, $h, $white);
        imagecopyresampled($out, $im, 0, 0, 0, 0, $w, $h, $srcW, $srcH);
        $ok = imagejpeg($out, $dest, $quality);
        imagedestroy($out);
        imagedestroy($im);
        if ($ok) {
            @chmod($dest, 0644);
        }
        return $ok;
    }
}
