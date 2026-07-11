<?php
/**
 * ============================================================
 * Server-Side PDF/DOCX/CSV Export
 * ============================================================
 * Generates exports server-side - NO CDN dependency
 * Works perfectly on Ethiopian hosting
 * ============================================================
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/ethiopian_date.php';

if (empty($_SESSION['admin_logged_in'])) {
    header('Location: index.php');
    exit;
}

$format = $_GET['format'] ?? 'pdf';
$filter = $_GET['filter'] ?? 'all';
$title = $_GET['title'] ?? SCHOOL_NAME_SHORT . ' Member Report';

// Build WHERE clause
$where = ["status != 'archived'"];
$params = [];
$types = '';

if (!empty($_GET['gender'])) { $where[] = "gender = ?"; $params[] = $_GET['gender']; $types .= 's'; }
if (!empty($_GET['age_group'])) { $where[] = "age_group = ?"; $params[] = $_GET['age_group']; $types .= 's'; }
if (!empty($_GET['f_status']) && $_GET['f_status'] !== '') {
    $where = array_values(array_filter($where, fn($w) => $w !== "status != 'archived'"));
    $where[] = "status = ?"; $params[] = $_GET['f_status']; $types .= 's';
}
if (!empty($_GET['member_type'])) { $where[] = "member_type = ?"; $params[] = $_GET['member_type']; $types .= 's'; }
if (!empty($_GET['registration_type'])) { $where[] = "registration_type = ?"; $params[] = $_GET['registration_type']; $types .= 's'; }
if (!empty($_GET['city'])) { $where[] = "city = ?"; $params[] = $_GET['city']; $types .= 's'; }
if (!empty($_GET['sub_city'])) { $where[] = "sub_city = ?"; $params[] = $_GET['sub_city']; $types .= 's'; }
if (!empty($_GET['education_level'])) { $where[] = "education_level = ?"; $params[] = $_GET['education_level']; $types .= 's'; }
if (!empty($_GET['has_id_card'])) {
    if ($_GET['has_id_card'] === 'yes') $where[] = "id_card_status = 'generated'";
    else $where[] = "(id_card_status IS NULL OR id_card_status != 'generated')";
}
if (!empty($_GET['has_phone'])) {
    if ($_GET['has_phone'] === 'yes') $where[] = "phone_number IS NOT NULL AND phone_number != ''";
    else $where[] = "(phone_number IS NULL OR phone_number = '')";
}
if (!empty($_GET['search'])) {
    $search = '%' . $_GET['search'] . '%';
    $where[] = "(student_name LIKE ? OR father_name LIKE ? OR grandfather_name LIKE ? OR member_code LIKE ? OR phone_number LIKE ?)";
    $params = array_merge($params, [$search, $search, $search, $search, $search]);
    $types .= 'sssss';
}
if (!empty($_GET['date_from'])) { $where[] = "created_at >= ?"; $params[] = $_GET['date_from'] . ' 00:00:00'; $types .= 's'; }
if (!empty($_GET['date_to'])) { $where[] = "created_at <= ?"; $params[] = $_GET['date_to'] . ' 23:59:59'; $types .= 's'; }

// Quick filter presets
if ($filter === 'active') { $where = ["status = 'active'"]; $params = []; $types = ''; $title = 'Active Members Report'; }
elseif ($filter === 'waiting') { $where = ["registration_type = 'waiting'", "status != 'archived'"]; $params = []; $types = ''; $title = 'Waiting Members Report'; }
elseif ($filter === 'no_id') { $where = ["(id_card_status IS NULL OR id_card_status != 'generated')", "status != 'archived'"]; $params = []; $types = ''; $title = 'Members Without ID Card'; }
elseif ($filter === 'male') { $where = ["gender = 'male'", "status != 'archived'"]; $params = []; $types = ''; $title = 'Male Members Report'; }
elseif ($filter === 'female') { $where = ["gender = 'female'", "status != 'archived'"]; $params = []; $types = ''; $title = 'Female Members Report'; }

$whereStr = !empty($where) ? implode(' AND ', $where) : '1=1';

$sql = "SELECT member_code, student_name, father_name, grandfather_name, baptismal_name,
               gender, age_group, current_section, phone_number, alt_phone_number,
               guardian_name, guardian_phone1, guardian_phone2,
               city, sub_city, woreda, work_profession, education_level,
               registration_type, member_type, status, created_at
        FROM members WHERE $whereStr ORDER BY student_name ASC LIMIT 5000";

$stmt = $conn->prepare($sql);
if (!empty($params)) $stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();
$members = [];
while ($row = $result->fetch_assoc()) {
    foreach ($row as $k => $v) { if ($v === null) $row[$k] = ''; }
    $members[] = $row;
}
$stmt->close();

// Count summary
$cSql = "SELECT COUNT(*) as total,
    COALESCE(SUM(gender='male'),0) as male, COALESCE(SUM(gender='female'),0) as female,
    COALESCE(SUM(status='active'),0) as active, COALESCE(SUM(status='warning'),0) as warning
    FROM members WHERE $whereStr";
$stmt2 = $conn->prepare($cSql);
if (!empty($params)) $stmt2->bind_param($types, ...$params);
$stmt2->execute();
$summary = $stmt2->get_result()->fetch_assoc();
$stmt2->close();

$total = $summary['total'] ?? count($members);
$today = new DateTime('now', new DateTimeZone('Africa/Addis_Ababa'));
// Ethiopian date for the report header (time-of-day is calendar-agnostic).
$dateStr = ethio_date_format($today, 'F j, Y') . ' ' . $today->format('g:i A');

$ageLabels = ['under6' => 'Under 6', '7_13' => '7-13', '14_17' => '14-17', '18_plus' => '18+'];

// ============================================================
// CSV EXPORT
// ============================================================
if ($format === 'csv') {
    $filename = EXPORT_PREFIX . '_' . preg_replace('/\s+/', '_', strtolower($title)) . '_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Amharic
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['#', 'Code', 'Student Name', 'Father Name', 'Grandfather', 'Baptismal', 'Gender', 'Age Group', 'Phone', 'Alt Phone', 'Guardian', 'Guardian Ph1', 'Guardian Ph2', 'City', 'Sub City', 'Woreda', 'Profession', 'Education', 'Reg Type', 'Member Type', 'Status']);
    foreach ($members as $i => $m) {
        fputcsv($fp, [$i+1, $m['member_code'], $m['student_name'], $m['father_name'], $m['grandfather_name'], $m['baptismal_name'], $m['gender'], $m['age_group'], $m['phone_number'], $m['alt_phone_number'], $m['guardian_name'], $m['guardian_phone1'], $m['guardian_phone2'], $m['city'], $m['sub_city'], $m['woreda'], $m['work_profession'], $m['education_level'], $m['registration_type'], $m['member_type'], $m['status']]);
    }
    fclose($fp);
    exit;
}

// ============================================================
// WORD DOC EXPORT
// ============================================================
if ($format === 'docx') {
    $filename = EXPORT_PREFIX . '_' . preg_replace('/\s+/', '_', strtolower($title)) . '_' . date('Y-m-d') . '.doc';
    header('Content-Type: application/msword; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
?>
<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:w="urn:schemas-microsoft-com:office:word" xmlns="http://www.w3.org/TR/REC-html40">
<head><meta charset="utf-8">
<style>
    @page { size: A4 landscape; margin: 1cm; }
    body { font-family: Calibri, 'Noto Sans Ethiopic', sans-serif; font-size: 10pt; margin: 0; }
    h1 { color: #16a34a; font-size: 16pt; border-bottom: 3px solid #16a34a; padding-bottom: 6pt; margin-bottom: 4pt; }
    .meta { color: #64748b; font-size: 8pt; margin-bottom: 10pt; }
    .summary span { display: inline-block; padding: 3pt 10pt; background: #f0fdf4; border: 1px solid #d1fae5; margin: 2pt; font-size: 9pt; }
    .summary .n { font-weight: bold; color: #16a34a; font-size: 11pt; }
    table { border-collapse: collapse; width: 100%; margin-top: 8pt; }
    th { background: #16a34a; color: white; font-size: 8pt; padding: 5pt 6pt; text-align: left; }
    td { border-bottom: 1px solid #e2e8f0; padding: 4pt 6pt; font-size: 8pt; }
    tr:nth-child(even) td { background: #f0fdf4; }
</style></head>
<body>
<h1><?= htmlspecialchars($title) ?></h1>
<p class="meta">Generated: <?= $dateStr ?> | Total: <?= $total ?> members</p>
<div class="summary">
    <span><span class="n"><?= $summary['male'] ?? 0 ?></span> Male</span>
    <span><span class="n"><?= $summary['female'] ?? 0 ?></span> Female</span>
    <span><span class="n"><?= $summary['active'] ?? 0 ?></span> Active</span>
    <span><span class="n"><?= $summary['warning'] ?? 0 ?></span> Warning</span>
</div>
<table>
    <tr><th>#</th><th>Name</th><th>Code</th><th>Gender</th><th>Age Grp</th><th>Phone</th><th>City</th><th>Sub-City</th><th>Status</th><th>Reg Type</th><th>Education</th><th>Profession</th></tr>
    <?php foreach ($members as $i => $m): ?>
    <tr>
        <td><?= $i+1 ?></td>
        <td><?= htmlspecialchars($m['student_name'] . ' ' . $m['father_name'] . ' ' . $m['grandfather_name']) ?></td>
        <td><?= htmlspecialchars($m['member_code']) ?></td>
        <td><?= $m['gender'] ?></td>
        <td><?= $ageLabels[$m['age_group']] ?? $m['age_group'] ?></td>
        <td><?= $m['phone_number'] ?></td>
        <td><?= htmlspecialchars($m['city']) ?></td>
        <td><?= htmlspecialchars($m['sub_city']) ?></td>
        <td><?= $m['status'] ?></td>
        <td><?= $m['registration_type'] ?></td>
        <td><?= htmlspecialchars($m['education_level']) ?></td>
        <td><?= htmlspecialchars($m['work_profession']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
</body></html>
<?php exit; }

// ============================================================
// PDF EXPORT (Server-side HTML-to-PDF via print dialog)
// ============================================================
$filename = EXPORT_PREFIX . '_' . preg_replace('/\s+/', '_', strtolower($title)) . '_' . date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($title) ?></title>
<style>
    @import url('https://fonts.googleapis.com/css2?family=Noto+Serif+Ethiopic:wght@400;600;700&display=swap');
    @page { size: A4 landscape; margin: 8mm 10mm; }
    @media print {
        body { margin: 0; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        .no-print { display: none !important; }
        table { page-break-inside: auto; }
        tr { page-break-inside: avoid; }
        thead { display: table-header-group; }
    }
    * { box-sizing: border-box; }
    body { font-family: 'Noto Serif Ethiopic', 'Segoe UI', Calibri, sans-serif; font-size: 9pt; color: #1e293b; margin: 0; padding: 0; }
    .header { background: linear-gradient(135deg, #16a34a, #0d9488); color: white; padding: 14px 20px; margin-bottom: 8px; }
    .header h1 { font-size: 16pt; margin: 0 0 2px 0; }
    .header .meta { font-size: 8pt; opacity: .85; }
    .summary { display: flex; flex-wrap: wrap; gap: 6px; padding: 6px 20px 12px; }
    .summary .pill { padding: 5px 14px; border-radius: 8px; font-size: 9pt; background: #f0fdf4; border: 1px solid #d1fae5; }
    .summary .pill b { color: #16a34a; font-size: 12pt; }
    table { width: 100%; border-collapse: collapse; margin: 0 auto; }
    th { background: #16a34a; color: white; font-size: 7pt; padding: 6px 5px; text-align: left; text-transform: uppercase; letter-spacing: 0.3px; white-space: nowrap; }
    td { padding: 5px; font-size: 8pt; border-bottom: 1px solid #e7e7e7; }
    tr:nth-child(even) td { background: #f8fdf9; }
    tr:hover td { background: #ecfdf5; }
    .status { padding: 2px 8px; border-radius: 10px; font-size: 7pt; font-weight: 600; }
    .s-active { background: #d1fae5; color: #065f46; }
    .s-warning { background: #fef3c7; color: #92400e; }
    .s-inactive { background: #fee2e2; color: #991b1b; }
    .toolbar { padding: 12px 20px; display: flex; gap: 8px; align-items: center; }
    .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 20px; border-radius: 10px; font-size: 12px; font-weight: 600; cursor: pointer; border: none; transition: .15s; text-decoration: none; }
    .btn-green { background: #16a34a; color: white; }
    .btn-green:hover { background: #15803d; }
    .btn-blue { background: #2563eb; color: white; }
    .btn-blue:hover { background: #1d4ed8; }
    .btn-gray { background: #f1f5f9; color: #475569; }
    .btn-gray:hover { background: #e2e8f0; }
    .footer { text-align: center; padding: 10px; font-size: 7pt; color: #94a3b8; border-top: 1px solid #e2e8f0; margin-top: 10px; }
</style>
</head>
<body>
    <div class="no-print toolbar">
        <button onclick="window.print()" class="btn btn-green"><i style="margin-right:4px">🖨️</i> Print / Save as PDF</button>
        <a href="<?= htmlspecialchars($_SERVER['REQUEST_URI'] . '&format=csv') ?>" class="btn btn-blue">📊 Download CSV Instead</a>
        <a href="/admin/reports.php" class="btn btn-gray">← Back to Reports</a>
        <span style="color:#64748b;font-size:11px;margin-left:8px">Tip: In the print dialog, select "Save as PDF" to download</span>
    </div>

    <div class="header">
        <h1><?= htmlspecialchars($title) ?></h1>
        <div class="meta"><?= SCHOOL_NAME_SHORT ?> <?= SCHOOL_TYPE ?> <?= SCHOOL_TAGLINE ?> | Generated: <?= $dateStr ?> | Total: <?= $total ?> members</div>
    </div>

    <div class="summary">
        <div class="pill"><b><?= $total ?></b> Total</div>
        <div class="pill"><b><?= $summary['male'] ?? 0 ?></b> Male</div>
        <div class="pill"><b><?= $summary['female'] ?? 0 ?></b> Female</div>
        <div class="pill"><b><?= $summary['active'] ?? 0 ?></b> Active</div>
        <div class="pill"><b><?= $summary['warning'] ?? 0 ?></b> Warning</div>
    </div>

    <table>
        <thead>
            <tr><th>#</th><th>Name</th><th>Code</th><th>Gender</th><th>Age Grp</th><th>Phone</th><th>City</th><th>Sub-City</th><th>Status</th><th>Reg Type</th><th>Education</th><th>Profession</th></tr>
        </thead>
        <tbody>
        <?php foreach ($members as $i => $m): 
            $sc = $m['status'] === 'active' ? 's-active' : ($m['status'] === 'warning' ? 's-warning' : 's-inactive');
        ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td><strong><?= htmlspecialchars($m['student_name'] . ' ' . $m['father_name']) ?></strong> <?= htmlspecialchars($m['grandfather_name']) ?></td>
                <td style="font-family:monospace;font-size:8pt"><?= htmlspecialchars($m['member_code']) ?></td>
                <td><?= $m['gender'] === 'male' ? 'M' : 'F' ?></td>
                <td><?= $ageLabels[$m['age_group']] ?? $m['age_group'] ?></td>
                <td><?= $m['phone_number'] ?></td>
                <td><?= htmlspecialchars($m['city']) ?></td>
                <td><?= htmlspecialchars($m['sub_city']) ?></td>
                <td><span class="status <?= $sc ?>"><?= $m['status'] ?></span></td>
                <td><?= $m['registration_type'] ?></td>
                <td><?= htmlspecialchars($m['education_level']) ?></td>
                <td><?= htmlspecialchars($m['work_profession']) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer"><?= ADMIN_FOOTER_TEXT ?> — Page report generated on <?= $dateStr ?></div>

    <script class="no-print">
        // Auto-trigger print dialog for PDF
        <?php if ($format === 'pdf'): ?>
        window.onload = function() { setTimeout(function(){ window.print(); }, 500); };
        <?php endif; ?>
    </script>
</body>
</html>
<?php
$conn->close();
