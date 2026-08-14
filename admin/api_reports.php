<?php
/**
 * ============================================================
 * School Advanced Reports & Analytics API
 * ============================================================
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

// CRITICAL FIX: correct session variable
if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_REQUEST['action'] ?? '';

try {
    switch ($action) {

        case 'get_analytics':
            $analytics = [];

            $r = $conn->query("SELECT 
                COUNT(*) as total_all,
                COALESCE(SUM(status='active'),0) as active,
                COALESCE(SUM(status='warning'),0) as warning,
                COALESCE(SUM(status='inactive'),0) as inactive,
                COALESCE(SUM(status='archived'),0) as archived,
                COALESCE(SUM(gender='male' AND status!='archived'),0) as male,
                COALESCE(SUM(gender='female' AND status!='archived'),0) as female,
                COALESCE(SUM(member_type='regular' AND status!='archived'),0) as regular,
                COALESCE(SUM(member_type='honorary' AND status!='archived'),0) as honorary,
                COALESCE(SUM(registration_type='waiting' AND status!='archived'),0) as waiting,
                COALESCE(SUM(registration_type='direct' AND status!='archived'),0) as direct,
                COALESCE(SUM(registration_type='transfer' AND status!='archived'),0) as transfer_reg,
                COALESCE(SUM(age_group='under6' AND status!='archived'),0) as under6,
                COALESCE(SUM(age_group='7_13' AND status!='archived'),0) as ag_7_13,
                COALESCE(SUM(age_group='14_17' AND status!='archived'),0) as ag_14_17,
                COALESCE(SUM(age_group='18_plus' AND status!='archived'),0) as ag_18_plus,
                COALESCE(SUM(id_card_status='generated' AND status!='archived'),0) as has_id,
                COALESCE(SUM((id_card_status IS NULL OR id_card_status!='generated') AND status!='archived'),0) as no_id,
                COALESCE(SUM(is_teacher=1 AND status!='archived'),0) as is_teacher
            FROM members");
            $analytics['totals'] = $r ? $r->fetch_assoc() : [];

            $r = $conn->query("SELECT DATE_FORMAT(created_at, '%Y-%m') as month, COUNT(*) as count,
                SUM(gender='male') as male_count, SUM(gender='female') as female_count
                FROM members WHERE status != 'archived' AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
                GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY month ASC");
            $analytics['registration_trend'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $analytics['registration_trend'][] = $row;

            $gcYear = (int)date('Y'); $gcMonth = (int)date('n'); $gcDay = (int)date('j');
            $ethYear = ($gcMonth > 9 || ($gcMonth == 9 && $gcDay >= 11)) ? $gcYear - 7 : $gcYear - 8;
            $r = $conn->query("SELECT CASE 
                WHEN ($ethYear - dob_ec_year) < 6 THEN '0-5'
                WHEN ($ethYear - dob_ec_year) BETWEEN 6 AND 10 THEN '6-10'
                WHEN ($ethYear - dob_ec_year) BETWEEN 11 AND 15 THEN '11-15'
                WHEN ($ethYear - dob_ec_year) BETWEEN 16 AND 20 THEN '16-20'
                WHEN ($ethYear - dob_ec_year) BETWEEN 21 AND 30 THEN '21-30'
                WHEN ($ethYear - dob_ec_year) > 30 THEN '30+'
                ELSE 'Unknown' END as age_range,
                COUNT(*) as count, SUM(gender='male') as male_count, SUM(gender='female') as female_count
                FROM members WHERE status != 'archived' AND dob_ec_year IS NOT NULL AND dob_ec_year > 0
                GROUP BY age_range ORDER BY FIELD(age_range, '0-5','6-10','11-15','16-20','21-30','30+','Unknown')");
            $analytics['age_distribution'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $analytics['age_distribution'][] = $row;

            $r = $conn->query("SELECT COALESCE(NULLIF(city,''), 'Unknown') as location, COUNT(*) as count
                FROM members WHERE status != 'archived' GROUP BY location ORDER BY count DESC LIMIT 10");
            $analytics['location_distribution'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $analytics['location_distribution'][] = $row;

            $r = $conn->query("SELECT COALESCE(NULLIF(education_level,''), 'Not Specified') as level, COUNT(*) as count
                FROM members WHERE status != 'archived' GROUP BY level ORDER BY count DESC");
            $analytics['education_distribution'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $analytics['education_distribution'][] = $row;

            $r = $conn->query("SELECT COALESCE(NULLIF(work_profession,''), 'Not Specified') as profession, COUNT(*) as count
                FROM members WHERE status != 'archived' GROUP BY profession ORDER BY count DESC LIMIT 10");
            $analytics['profession_distribution'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $analytics['profession_distribution'][] = $row;

            $r = $conn->query("SELECT COALESCE(NULLIF(sub_city,''), 'Unknown') as sub_city, COUNT(*) as count
                FROM members WHERE status != 'archived' AND sub_city IS NOT NULL AND sub_city != ''
                GROUP BY sub_city ORDER BY count DESC LIMIT 10");
            $analytics['subcity_distribution'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $analytics['subcity_distribution'][] = $row;

            $r = $conn->query("SELECT DATE(created_at) as day, COUNT(*) as count 
                FROM members WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY) AND status != 'archived'
                GROUP BY DATE(created_at) ORDER BY day ASC");
            $analytics['recent_activity'] = [];
            if ($r) while ($row = $r->fetch_assoc()) $analytics['recent_activity'][] = $row;

            echo json_encode(['status' => 'success', 'analytics' => $analytics]);
            break;

        case 'get_filtered_members':
            $where = ["status != 'archived'"]; $params = []; $types = '';
            if (!empty($_GET['gender'])) { $where[] = "gender = ?"; $params[] = $_GET['gender']; $types .= 's'; }
            if (!empty($_GET['age_group'])) { $where[] = "age_group = ?"; $params[] = $_GET['age_group']; $types .= 's'; }
            if (!empty($_GET['status'])) {
                $where = array_values(array_filter($where, fn($w) => $w !== "status != 'archived'"));
                $where[] = "status = ?"; $params[] = $_GET['status']; $types .= 's';
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
                $s = '%' . $_GET['search'] . '%';
                $where[] = "(student_name LIKE ? OR father_name LIKE ? OR grandfather_name LIKE ? OR member_code LIKE ? OR phone_number LIKE ? OR guardian_name LIKE ?)";
                $params = array_merge($params, [$s,$s,$s,$s,$s,$s]); $types .= 'ssssss';
            }
            if (!empty($_GET['date_from'])) { $where[] = "created_at >= ?"; $params[] = $_GET['date_from'].' 00:00:00'; $types .= 's'; }
            if (!empty($_GET['date_to'])) { $where[] = "created_at <= ?"; $params[] = $_GET['date_to'].' 23:59:59'; $types .= 's'; }

            $whereStr = implode(' AND ', $where);
            $sql = "SELECT id,member_code,student_name,father_name,grandfather_name,baptismal_name,
                gender,age_group,current_section,phone_number,alt_phone_number,
                guardian_name,guardian_phone1,guardian_phone2,city,sub_city,woreda,
                work_profession,education_level,registration_type,member_type,status,
                id_card_status,created_at FROM members WHERE $whereStr ORDER BY student_name ASC LIMIT 2000";
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

            $cSql = "SELECT COUNT(*) as total,
                COALESCE(SUM(gender='male'),0) as male, COALESCE(SUM(gender='female'),0) as female,
                COALESCE(SUM(status='active'),0) as active, COALESCE(SUM(status='warning'),0) as warning,
                COALESCE(SUM(age_group='under6'),0) as under6, COALESCE(SUM(age_group='7_13'),0) as ag7_13,
                COALESCE(SUM(age_group='14_17'),0) as ag14_17, COALESCE(SUM(age_group='18_plus'),0) as ag18_plus
                FROM members WHERE $whereStr";
            $stmt2 = $conn->prepare($cSql);
            if (!empty($params)) $stmt2->bind_param($types, ...$params);
            $stmt2->execute();
            $summary = $stmt2->get_result()->fetch_assoc();
            $stmt2->close();

            echo json_encode(['status'=>'success','count'=>count($members),'summary'=>$summary,'members'=>$members]);
            break;

        case 'get_filter_options':
            $options = [];
            foreach (['city','sub_city','education_level','work_profession','woreda'] as $f) {
                $r = $conn->query("SELECT DISTINCT `$f` as val FROM members WHERE status!='archived' AND `$f` IS NOT NULL AND `$f`!='' ORDER BY `$f` ASC");
                $options[$f] = []; if ($r) while ($row = $r->fetch_assoc()) $options[$f][] = $row['val'];
            }
            echo json_encode(['status'=>'success','options'=>$options]);
            break;

        default:
            echo json_encode(['status'=>'error','message'=>'Unknown action']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['status'=>'error','message'=>'Server error']);
}
if (isset($conn) && $conn instanceof mysqli) $conn->close();
