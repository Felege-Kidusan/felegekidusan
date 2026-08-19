<?php
/**
 * ============================================================
 * FKSS CMS API — content management backend
 * ============================================================
 * Handles all CRUD for: gallery, gallery categories, registration
 * submissions, social links, teachers, schedule, programs.
 *
 * Access: super_admin, school_admin, info_dept, content_editor
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/GalleryService.php';

// ── Auth ──
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'session_expired', 'message' => 'Not authenticated.', 'action' => 'reload']);
    exit;
}

$adminId   = (int)($_SESSION['admin_id'] ?? 0);
$adminRole = $_SESSION['admin_role'] ?? '';

// ── Role gate: only these roles may manage content ──
$allowedRoles = ['super_admin', 'school_admin', 'info_dept', 'content_editor'];
if (!in_array($adminRole, $allowedRoles)) {
    http_response_code(403);
    echo json_encode(['status' => 'error', 'message' => 'You do not have permission to manage content.']);
    exit;
}

// ── CSRF on all POST ──
requireCsrfForPost();

$action = $_REQUEST['action'] ?? '';

\App\Services\GalleryService::ensureSchema($conn);
\App\Services\GalleryService::rescueStrayFiles();

/** Clean JSON exit */
function out($data) { echo json_encode($data, JSON_UNESCAPED_UNICODE); exit; }

/** Helper: trimmed POST field */
function f($k, $d = '') { return isset($_POST[$k]) ? trim($_POST[$k]) : $d; }

/**
 * Save an uploaded image under /uploads/<subdir> (inside the website).
 * Returns web path, or ['error'=>msg], or null if no file.
 */
function saveImage($field, $subDir = 'gallery') {
    $pack = \App\Services\GalleryService::saveUpload($field, $subDir);
    if ($pack === null) {
        return null;
    }
    if (isset($pack['error'])) {
        return $pack;
    }
    return $pack['image'];
}

/** Delete a previously-uploaded image by its web path */
function deleteImage($webPath) {
    \App\Services\GalleryService::deleteByWebPath($webPath);
}

/** @return array{id:int,image:string}|array{error:string} */
function cmsInsertPhoto($conn, $catId, $caption, $captionAm, $featured, $adminId, $field) {
    $pack = \App\Services\GalleryService::saveUpload($field, 'gallery');
    if ($pack === null) {
        return ['error' => 'No image selected.'];
    }
    if (isset($pack['error'])) {
        return $pack;
    }
    $img = $pack['image'];
    $thumb = $pack['thumb'];
    $stmt = $conn->prepare("INSERT INTO cms_gallery_photos (category_id, image_path, thumb_path, caption, caption_am, is_featured, uploaded_by) VALUES (?,?,?,?,?,?,?)");
    if (!$stmt) {
        $stmt = $conn->prepare("INSERT INTO cms_gallery_photos (category_id, image_path, caption, caption_am, is_featured, uploaded_by) VALUES (?,?,?,?,?,?)");
        $stmt->bind_param('isssii', $catId, $img, $caption, $captionAm, $featured, $adminId);
    } else {
        $stmt->bind_param('issssii', $catId, $img, $thumb, $caption, $captionAm, $featured, $adminId);
    }
    $stmt->execute();
    $newId = (int)$conn->insert_id;
    $stmt->close();
    return ['id' => $newId, 'image' => $img];
}

try {
    switch ($action) {

    // ════════════════════════════════════════════════════════
    //  GALLERY CATEGORIES
    // ════════════════════════════════════════════════════════
    case 'cat_list': {
        $res = $conn->query("SELECT * FROM cms_gallery_categories ORDER BY sort_order, id");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        out(['status' => 'success', 'data' => $rows]);
    }

    case 'cat_save': {
        $id    = (int) f('id', 0);
        $name  = f('name');
        $nameAm = f('name_am');
        $desc  = f('description');
        $order = (int) f('sort_order', 0);
        if ($name === '') out(['status' => 'error', 'message' => 'Category name is required.']);

        // slug from name
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $name));
        $slug = trim($slug, '-') ?: ('cat-' . time());

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE cms_gallery_categories SET name=?, name_am=?, description=?, sort_order=? WHERE id=?");
            $stmt->bind_param('sssii', $name, $nameAm, $desc, $order, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO cms_gallery_categories (name, name_am, slug, description, sort_order) VALUES (?,?,?,?,?)");
            $stmt->bind_param('ssssi', $name, $nameAm, $slug, $desc, $order);
        }
        $stmt->execute();
        $newId = $id > 0 ? $id : $conn->insert_id;
        $stmt->close();
        out(['status' => 'success', 'message' => 'Category saved.', 'id' => $newId]);
    }

    case 'cat_delete': {
        $id = (int) f('id', 0);
        $stmt = $conn->prepare("DELETE FROM cms_gallery_categories WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Category deleted. Photos in it are now uncategorized.']);
    }

    // ════════════════════════════════════════════════════════
    //  GALLERY PHOTOS
    // ════════════════════════════════════════════════════════
    case 'photo_list': {
        $catId = (int) ($_REQUEST['category_id'] ?? 0);
        if ($catId > 0) {
            $stmt = $conn->prepare("SELECT p.*, c.name AS category_name FROM cms_gallery_photos p LEFT JOIN cms_gallery_categories c ON p.category_id=c.id WHERE p.category_id=? ORDER BY p.sort_order, p.id DESC");
            $stmt->bind_param('i', $catId);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query("SELECT p.*, c.name AS category_name FROM cms_gallery_photos p LEFT JOIN cms_gallery_categories c ON p.category_id=c.id ORDER BY p.sort_order, p.id DESC");
        }
        $rows = [];
        while ($r = $res->fetch_assoc()) {
            $path = (string)($r['image_path'] ?? '');
            $disk = \App\Services\GalleryService::resolveOnDisk($path);
            $r['file_exists'] = $disk !== null;
            if (empty($r['thumb_path'])) {
                $r['thumb_path'] = $r['image_path'];
            }
            $r['view_thumb'] = \App\Services\GalleryService::publicServeUrl((int)$r['id'], 't');
            $r['view_full'] = \App\Services\GalleryService::publicServeUrl((int)$r['id'], 'f');
            $rows[] = $r;
        }
        out(['status' => 'success', 'data' => $rows]);
    }

    case 'photo_upload': {
        $catId   = (int) f('category_id', 0) ?: null;
        $caption = f('caption');
        $captionAm = f('caption_am');
        $featured = isset($_POST['is_featured']) ? 1 : 0;

        $files = [];
        if (!empty($_FILES['images']) && is_array($_FILES['images']['name'])) {
            $n = count($_FILES['images']['name']);
            for ($i = 0; $i < $n; $i++) {
                $_FILES['_gal_one'] = [
                    'name' => $_FILES['images']['name'][$i],
                    'type' => $_FILES['images']['type'][$i],
                    'tmp_name' => $_FILES['images']['tmp_name'][$i],
                    'error' => $_FILES['images']['error'][$i],
                    'size' => $_FILES['images']['size'][$i],
                ];
                $files[] = '_gal_one';
                $saved = cmsInsertPhoto($conn, $catId, $caption, $captionAm, $featured, $adminId, '_gal_one');
                if (isset($saved['error'])) {
                    out(['status' => 'error', 'message' => $saved['error']]);
                }
            }
            out(['status' => 'success', 'message' => $n === 1 ? 'Photo uploaded.' : $n . ' photos uploaded.']);
        }

        $saved = cmsInsertPhoto($conn, $catId, $caption, $captionAm, $featured, $adminId, 'image');
        if (isset($saved['error'])) {
            out(['status' => 'error', 'message' => $saved['error']]);
        }
        out(['status' => 'success', 'message' => 'Photo uploaded.', 'id' => $saved['id'], 'image_path' => $saved['image']]);
    }

    case 'photo_update': {
        $id = (int) f('id', 0);
        if ($id <= 0) {
            out(['status' => 'error', 'message' => 'Photo not found.']);
        }
        $catId = (int) f('category_id', 0) ?: null;
        $caption = f('caption');
        $captionAm = f('caption_am');
        $featured = isset($_POST['is_featured']) ? 1 : 0;
        $pack = \App\Services\GalleryService::saveUpload('image', 'gallery');
        if (is_array($pack) && isset($pack['error'])) {
            out(['status' => 'error', 'message' => $pack['error']]);
        }
        if (is_array($pack) && !empty($pack['ok'])) {
            $old = $conn->prepare("SELECT image_path, thumb_path FROM cms_gallery_photos WHERE id=?");
            $old->bind_param('i', $id);
            $old->execute();
            $prev = $old->get_result()->fetch_assoc();
            $old->close();
            if ($prev) {
                deleteImage($prev['image_path'] ?? '');
                if (!empty($prev['thumb_path'])) {
                    deleteImage($prev['thumb_path']);
                }
            }
            $stmt = $conn->prepare("UPDATE cms_gallery_photos SET category_id=?, caption=?, caption_am=?, is_featured=?, image_path=?, thumb_path=? WHERE id=?");
            $stmt->bind_param('ississi', $catId, $caption, $captionAm, $featured, $pack['image'], $pack['thumb'], $id);
        } else {
            $stmt = $conn->prepare("UPDATE cms_gallery_photos SET category_id=?, caption=?, caption_am=?, is_featured=? WHERE id=?");
            $stmt->bind_param('issii', $catId, $caption, $captionAm, $featured, $id);
        }
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Photo updated.']);
    }

    case 'photo_delete': {
        $id = (int) f('id', 0);
        $stmt = $conn->prepare("SELECT image_path, thumb_path FROM cms_gallery_photos WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($row) {
            deleteImage($row['image_path'] ?? '');
            if (!empty($row['thumb_path'])) {
                deleteImage($row['thumb_path']);
            }
        }
        $stmt = $conn->prepare("DELETE FROM cms_gallery_photos WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Photo deleted.']);
    }

    // ════════════════════════════════════════════════════════
    //  REGISTRATION SUBMISSIONS (Option B — leads)
    // ════════════════════════════════════════════════════════
    case 'sub_list': {
        $filter = $_REQUEST['filter'] ?? 'all';
        if ($filter !== 'all' && in_array($filter, ['new', 'contacted', 'enrolled', 'rejected'])) {
            $stmt = $conn->prepare("SELECT * FROM cms_registration_submissions WHERE status=? ORDER BY created_at DESC");
            $stmt->bind_param('s', $filter);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query("SELECT * FROM cms_registration_submissions ORDER BY created_at DESC");
        }
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        // counts for badges
        $counts = ['new'=>0,'contacted'=>0,'enrolled'=>0,'rejected'=>0,'all'=>0];
        $cres = $conn->query("SELECT status, COUNT(*) c FROM cms_registration_submissions GROUP BY status");
        while ($c = $cres->fetch_assoc()) { $counts[$c['status']] = (int)$c['c']; $counts['all'] += (int)$c['c']; }
        out(['status' => 'success', 'data' => $rows, 'counts' => $counts]);
    }

    case 'sub_update_status': {
        $id = (int) f('id', 0);
        $status = f('status');
        $notes = f('admin_notes');
        if (!in_array($status, ['new','contacted','enrolled','rejected'])) {
            out(['status' => 'error', 'message' => 'Invalid status.']);
        }
        $stmt = $conn->prepare("UPDATE cms_registration_submissions SET status=?, admin_notes=? WHERE id=?");
        $stmt->bind_param('ssi', $status, $notes, $id);
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Submission updated.']);
    }

    case 'sub_delete': {
        $id = (int) f('id', 0);
        $stmt = $conn->prepare("DELETE FROM cms_registration_submissions WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Submission deleted.']);
    }

    // ════════════════════════════════════════════════════════
    //  SOCIAL LINKS
    // ════════════════════════════════════════════════════════
    case 'social_list': {
        $res = $conn->query("SELECT * FROM cms_social_links ORDER BY sort_order, id");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        out(['status' => 'success', 'data' => $rows]);
    }

    case 'social_save': {
        $id = (int) f('id', 0);
        $platform = f('platform');
        $url = f('url');
        $icon = f('icon_class', 'fa-solid fa-link');
        $label = f('label');
        $order = (int) f('sort_order', 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($platform === '' || $url === '') out(['status' => 'error', 'message' => 'Platform and URL are required.']);

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE cms_social_links SET platform=?, url=?, icon_class=?, label=?, sort_order=?, is_active=? WHERE id=?");
            $stmt->bind_param('ssssiii', $platform, $url, $icon, $label, $order, $active, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO cms_social_links (platform, url, icon_class, label, sort_order, is_active) VALUES (?,?,?,?,?,?)");
            $stmt->bind_param('ssssii', $platform, $url, $icon, $label, $order, $active);
        }
        $stmt->execute();
        $newId = $id > 0 ? $id : $conn->insert_id;
        $stmt->close();
        out(['status' => 'success', 'message' => 'Social link saved.', 'id' => $newId]);
    }

    case 'social_delete': {
        $id = (int) f('id', 0);
        $stmt = $conn->prepare("DELETE FROM cms_social_links WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Social link deleted.']);
    }

    // ════════════════════════════════════════════════════════
    //  TEACHERS
    // ════════════════════════════════════════════════════════
    case 'teacher_list': {
        $res = $conn->query("SELECT * FROM cms_teachers ORDER BY sort_order, id");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        out(['status' => 'success', 'data' => $rows]);
    }

    case 'teacher_save': {
        $id = (int) f('id', 0);
        $name = f('name');
        $nameAm = f('name_am');
        $title = f('role_title');
        $titleAm = f('role_title_am');
        $bio = f('bio');
        $order = (int) f('sort_order', 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($name === '') out(['status' => 'error', 'message' => 'Teacher name is required.']);

        // Optional photo
        $photo = saveImage('photo', 'teachers');
        if (is_array($photo)) out(['status' => 'error', 'message' => $photo['error']]);

        if ($id > 0) {
            if ($photo !== null) {
                // replace photo: delete old one
                $old = $conn->prepare("SELECT photo_path FROM cms_teachers WHERE id=?");
                $old->bind_param('i', $id); $old->execute();
                $r = $old->get_result()->fetch_assoc(); $old->close();
                if ($r) deleteImage($r['photo_path']);
                $stmt = $conn->prepare("UPDATE cms_teachers SET name=?, name_am=?, role_title=?, role_title_am=?, bio=?, photo_path=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->bind_param('ssssssiii', $name, $nameAm, $title, $titleAm, $bio, $photo, $order, $active, $id);
            } else {
                $stmt = $conn->prepare("UPDATE cms_teachers SET name=?, name_am=?, role_title=?, role_title_am=?, bio=?, sort_order=?, is_active=? WHERE id=?");
                $stmt->bind_param('sssssiii', $name, $nameAm, $title, $titleAm, $bio, $order, $active, $id);
            }
        } else {
            $photoVal = $photo ?? null;
            $stmt = $conn->prepare("INSERT INTO cms_teachers (name, name_am, role_title, role_title_am, bio, photo_path, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssssii', $name, $nameAm, $title, $titleAm, $bio, $photoVal, $order, $active);
        }
        $stmt->execute();
        $newId = $id > 0 ? $id : $conn->insert_id;
        $stmt->close();
        out(['status' => 'success', 'message' => 'Teacher saved.', 'id' => $newId]);
    }

    case 'teacher_delete': {
        $id = (int) f('id', 0);
        $stmt = $conn->prepare("SELECT photo_path FROM cms_teachers WHERE id=?");
        $stmt->bind_param('i', $id); $stmt->execute();
        $r = $stmt->get_result()->fetch_assoc(); $stmt->close();
        if ($r) deleteImage($r['photo_path']);
        $stmt = $conn->prepare("DELETE FROM cms_teachers WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Teacher deleted.']);
    }

    // ════════════════════════════════════════════════════════
    //  WEEKLY SCHEDULE
    // ════════════════════════════════════════════════════════
    case 'schedule_list': {
        $res = $conn->query("SELECT * FROM cms_schedule ORDER BY sort_order, id");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        out(['status' => 'success', 'data' => $rows]);
    }

    case 'schedule_save': {
        $id = (int) f('id', 0);
        $day = f('day_of_week');
        $dayAm = f('day_of_week_am');
        $time = f('time_label');
        $activity = f('activity');
        $activityAm = f('activity_am');
        $loc = f('location');
        $order = (int) f('sort_order', 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($day === '' || $activity === '') out(['status' => 'error', 'message' => 'Day and activity are required.']);

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE cms_schedule SET day_of_week=?, day_of_week_am=?, time_label=?, activity=?, activity_am=?, location=?, sort_order=?, is_active=? WHERE id=?");
            $stmt->bind_param('ssssssiii', $day, $dayAm, $time, $activity, $activityAm, $loc, $order, $active, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO cms_schedule (day_of_week, day_of_week_am, time_label, activity, activity_am, location, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssssii', $day, $dayAm, $time, $activity, $activityAm, $loc, $order, $active);
        }
        $stmt->execute();
        $newId = $id > 0 ? $id : $conn->insert_id;
        $stmt->close();
        out(['status' => 'success', 'message' => 'Schedule entry saved.', 'id' => $newId]);
    }

    case 'schedule_delete': {
        $id = (int) f('id', 0);
        $stmt = $conn->prepare("DELETE FROM cms_schedule WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Schedule entry deleted.']);
    }

    // ════════════════════════════════════════════════════════
    //  PROGRAMS
    // ════════════════════════════════════════════════════════
    case 'program_list': {
        $res = $conn->query("SELECT * FROM cms_programs ORDER BY sort_order, id");
        $rows = [];
        while ($r = $res->fetch_assoc()) $rows[] = $r;
        out(['status' => 'success', 'data' => $rows]);
    }

    case 'program_save': {
        $id = (int) f('id', 0);
        $title = f('title');
        $titleAm = f('title_am');
        $desc = f('description');
        $descAm = f('description_am');
        $icon = f('icon_class', 'fa-solid fa-book');
        $features = f('features'); // newline-separated list
        $order = (int) f('sort_order', 0);
        $active = isset($_POST['is_active']) ? 1 : 0;
        if ($title === '') out(['status' => 'error', 'message' => 'Program title is required.']);

        if ($id > 0) {
            $stmt = $conn->prepare("UPDATE cms_programs SET title=?, title_am=?, description=?, description_am=?, icon_class=?, features=?, sort_order=?, is_active=? WHERE id=?");
            $stmt->bind_param('ssssssiii', $title, $titleAm, $desc, $descAm, $icon, $features, $order, $active, $id);
        } else {
            $stmt = $conn->prepare("INSERT INTO cms_programs (title, title_am, description, description_am, icon_class, features, sort_order, is_active) VALUES (?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssssii', $title, $titleAm, $desc, $descAm, $icon, $features, $order, $active);
        }
        $stmt->execute();
        $newId = $id > 0 ? $id : $conn->insert_id;
        $stmt->close();
        out(['status' => 'success', 'message' => 'Program saved.', 'id' => $newId]);
    }

    case 'program_delete': {
        $id = (int) f('id', 0);
        $stmt = $conn->prepare("DELETE FROM cms_programs WHERE id=?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();
        out(['status' => 'success', 'message' => 'Program deleted.']);
    }

    default:
        out(['status' => 'error', 'message' => 'Unknown action: ' . $action]);
    }
} catch (Throwable $e) {
    error_log("CMS API error [$action]: " . $e->getMessage());
    out(['status' => 'error', 'message' => 'Server error: ' . $e->getMessage()]);
}
