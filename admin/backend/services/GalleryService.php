<?php
/**
 * Gallery storage — one place for upload, thumbs, and public listing.
 * Public pages never talk to the database directly.
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

    /** Copy files saved to the old (wrong) folder into the public uploads folder. */
    public static function rescueStrayFiles(): void
    {
        $right = self::uploadDir('gallery');
        $wrong = dirname(self::diskRoot()) . '/uploads/gallery';
        if (!is_dir($wrong) || realpath($wrong) === realpath($right)) {
            return;
        }
        $found = array_merge(
            glob($wrong . '/*.jpg') ?: [],
            glob($wrong . '/*.jpeg') ?: [],
            glob($wrong . '/*.png') ?: [],
            glob($wrong . '/*.gif') ?: [],
            glob($wrong . '/*.webp') ?: []
        );
        foreach ($found as $src) {
            $dest = $right . '/' . basename($src);
            if (!is_file($dest)) {
                @copy($src, $dest);
            }
        }
    }

    public static function ensureSchema(\mysqli $conn): void
    {
        try {
            $chk = $conn->query("SHOW COLUMNS FROM cms_gallery_photos LIKE 'thumb_path'");
            if ($chk && $chk->num_rows === 0) {
                $conn->query("ALTER TABLE cms_gallery_photos ADD COLUMN thumb_path VARCHAR(255) DEFAULT NULL AFTER image_path");
            }
        } catch (\Throwable $e) {
            // table may not exist yet
        }
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
        if ((int)$_FILES[$field]['size'] > self::MAX_BYTES) {
            return ['error' => 'Image is too large (max 8MB).'];
        }

        $tmp = (string)$_FILES[$field]['tmp_name'];
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
        $webPath = (string)$webPath;
        if ($webPath === '' || $webPath[0] !== '/' || strpos($webPath, '..') !== false) {
            return;
        }
        if (strpos($webPath, '/uploads/') !== 0) {
            return;
        }
        $full = self::diskRoot() . $webPath;
        $root = realpath(self::diskRoot() . '/uploads');
        $real = realpath($full);
        if ($root && $real && strpos($real, $root) === 0 && is_file($real)) {
            @unlink($real);
        }
        // matching thumb
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
        $full = (string)($row['image_path'] ?? '');
        $thumb = (string)($row['thumb_path'] ?? '');
        if ($thumb === '') {
            $thumb = $full;
        }
        return [
            'id' => (int)$row['id'],
            'thumb' => $thumb,
            'full' => $full,
            'caption' => (string)($row['caption'] ?? ''),
            'caption_am' => (string)($row['caption_am'] ?? ''),
            'featured' => (int)($row['is_featured'] ?? 0) === 1,
        ];
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
