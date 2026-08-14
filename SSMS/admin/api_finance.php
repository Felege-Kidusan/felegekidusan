<?php
/**
 * Finance Department API 
 * Full CRUD for transactions, categories, budgets, member fees
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit;
}

$action = $_REQUEST['action'] ?? '';
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// CSRF protection for all POST requests
requireCsrfForPost();

// Check tables exist
try { $conn->query("SELECT 1 FROM finance_transactions LIMIT 0"); }
catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Finance tables not found. Run migration 004 first.']);
    exit;
}

try {
switch ($action) {

case 'dashboard':
    $data = [];
    // Totals
    $r = $conn->query("SELECT COALESCE(SUM(CASE WHEN type='income' AND status='confirmed' THEN amount END),0) income, COALESCE(SUM(CASE WHEN type='expense' AND status='confirmed' THEN amount END),0) expense FROM finance_transactions");
    $row = $r->fetch_assoc();
    $data['total_income'] = (float)$row['income'];
    $data['total_expense'] = (float)$row['expense'];
    $data['balance'] = $data['total_income'] - $data['total_expense'];

    // This month
    $m1 = date('Y-m-01'); $m2 = date('Y-m-t');
    $r = $conn->query("SELECT COALESCE(SUM(CASE WHEN type='income' AND status='confirmed' THEN amount END),0) income, COALESCE(SUM(CASE WHEN type='expense' AND status='confirmed' THEN amount END),0) expense FROM finance_transactions WHERE transaction_date BETWEEN '$m1' AND '$m2'");
    $row = $r->fetch_assoc();
    $data['month_income'] = (float)$row['income'];
    $data['month_expense'] = (float)$row['expense'];

    // Pending
    $r = $conn->query("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a FROM finance_transactions WHERE status='pending'");
    $row = $r->fetch_assoc();
    $data['pending_count'] = (int)$row['c'];
    $data['pending_amount'] = (float)$row['a'];

    // Recent transactions
    $r = $conn->query("SELECT t.*, c.name as category_name, m.student_name as member_name FROM finance_transactions t LEFT JOIN finance_categories c ON t.category_id=c.id LEFT JOIN members m ON t.member_id=m.id ORDER BY t.transaction_date DESC, t.id DESC LIMIT 20");
    $data['recent'] = [];
    while ($row = $r->fetch_assoc()) $data['recent'][] = $row;

    // By category
    $r = $conn->query("SELECT c.name, t.type, SUM(t.amount) total FROM finance_transactions t JOIN finance_categories c ON t.category_id=c.id WHERE t.status='confirmed' GROUP BY c.name, t.type ORDER BY total DESC");
    $data['by_category'] = [];
    while ($row = $r->fetch_assoc()) $data['by_category'][] = $row;

    echo json_encode(['status'=>'success','data'=>$data]);
    break;

case 'categories':
    $r = $conn->query("SELECT * FROM finance_categories ORDER BY type, name");
    $cats = [];
    while ($row = $r->fetch_assoc()) $cats[] = $row;
    echo json_encode(['status'=>'success','categories'=>$cats]);
    break;

case 'transactions':
    $type = $_GET['type'] ?? '';
    $cat = $_GET['category_id'] ?? '';
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $limit = min((int)($_GET['limit'] ?? 100), 500);

    $where = ['1=1'];
    $params = [];
    if ($type) { $where[] = "t.type=?"; $params[] = $type; }
    if ($cat) { $where[] = "t.category_id=?"; $params[] = $cat; }
    if ($from) { $where[] = "t.transaction_date>=?"; $params[] = $from; }
    if ($to) { $where[] = "t.transaction_date<=?"; $params[] = $to; }
    if ($status) { $where[] = "t.status=?"; $params[] = $status; }
    if ($search) { $where[] = "(t.description LIKE ? OR t.receipt_number LIKE ? OR m.student_name LIKE ?)"; $s="%$search%"; $params[]=$s; $params[]=$s; $params[]=$s; }

    $sql = "SELECT t.*, c.name as category_name, m.student_name as member_name FROM finance_transactions t LEFT JOIN finance_categories c ON t.category_id=c.id LEFT JOIN members m ON t.member_id=m.id WHERE ".implode(' AND ',$where)." ORDER BY t.transaction_date DESC, t.id DESC LIMIT $limit";
    $stmt = $conn->prepare($sql);
    if ($params) { $types = str_repeat('s', count($params)); $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $result = $stmt->get_result();
    $txns = [];
    while ($row = $result->fetch_assoc()) $txns[] = $row;

    echo json_encode(['status'=>'success','transactions'=>$txns]);
    break;

case 'add_transaction':
    $type = $_POST['type'] ?? '';
    $catId = (int)($_POST['category_id'] ?? 0);
    $memberId = !empty($_POST['member_id']) ? (int)$_POST['member_id'] : null;
    $amount = validateAmount($_POST['amount'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $receipt = trim($_POST['receipt_number'] ?? '');
    $method = validateEnum($_POST['payment_method'] ?? '', ['cash','bank_transfer','mobile_money','check','other'], 'cash');
    $date = validateDate($_POST['transaction_date'] ?? '', date('Y-m-d'));
    $ecMonth = !empty($_POST['ec_month']) ? (int)$_POST['ec_month'] : null;
    $ecYear = !empty($_POST['ec_year']) ? (int)$_POST['ec_year'] : null;

    if (!in_array($type,['income','expense']) || $amount === null || $amount <= 0) {
        echo json_encode(['status'=>'error','message'=>'Valid type and positive amount required']); break;
    }

    $stmt = $conn->prepare("INSERT INTO finance_transactions (type,category_id,member_id,amount,description,receipt_number,payment_method,transaction_date,ec_month,ec_year,recorded_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'confirmed')");
    $stmt->bind_param('siidsssiiii', $type, $catId, $memberId, $amount, $desc, $receipt, $method, $date, $ecMonth, $ecYear, $adminId);
    $stmt->execute();

    echo json_encode(['status'=>'success','id'=>$conn->insert_id,'message'=>'Transaction recorded']);
    break;

case 'update_transaction':
    $id = (int)($_POST['id'] ?? 0);
    $catId = (int)($_POST['category_id'] ?? 0);
    $amount = validateAmount($_POST['amount'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $receipt = trim($_POST['receipt_number'] ?? '');
    $method = validateEnum($_POST['payment_method'] ?? '', ['cash','bank_transfer','mobile_money','check','other'], 'cash');
    $date = validateDate($_POST['transaction_date'] ?? '', date('Y-m-d'));
    $st = validateEnum($_POST['status'] ?? '', ['confirmed','pending','cancelled'], 'confirmed');

    if (!$id) { echo json_encode(['status'=>'error','message'=>'Missing ID']); break; }
    if ($amount === null || $amount <= 0) { echo json_encode(['status'=>'error','message'=>'Valid positive amount required']); break; }
    $stmt = $conn->prepare("UPDATE finance_transactions SET category_id=?,amount=?,description=?,receipt_number=?,payment_method=?,transaction_date=?,status=? WHERE id=?");
    $stmt->bind_param('idsssssi', $catId, $amount, $desc, $receipt, $method, $date, $st, $id);
    $stmt->execute();
    echo json_encode(['status'=>'success','message'=>'Updated']);
    break;

case 'delete_transaction':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['status'=>'error','message'=>'Missing ID']); break; }
    $stmt = $conn->prepare("DELETE FROM finance_transactions WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['status'=>'success','message'=>'Deleted']);
    break;

case 'save_category':
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $type = $_POST['type'] ?? 'income';
    $desc = trim($_POST['description'] ?? '');
    if (!$name) { echo json_encode(['status'=>'error','message'=>'Name required']); break; }
    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE finance_categories SET name=?,type=?,description=? WHERE id=?");
        $stmt->bind_param('sssi', $name, $type, $desc, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO finance_categories (name,type,description) VALUES (?,?,?)");
        $stmt->bind_param('sss', $name, $type, $desc);
    }
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$id?:$conn->insert_id]);
    break;

case 'member_fees':
    $memberId = (int)($_GET['member_id'] ?? 0);
    $year = (int)($_GET['ec_year'] ?? 0);
    $status = $_GET['status'] ?? '';
    $where = ['1=1'];
    $params = []; $types = '';
    if ($memberId) { $where[] = "f.member_id=?"; $params[] = $memberId; $types .= 'i'; }
    if ($year) { $where[] = "f.ec_year=?"; $params[] = $year; $types .= 'i'; }
    if ($status && in_array($status, ['paid','unpaid','partial'])) { $where[] = "f.status=?"; $params[] = $status; $types .= 's'; }

    $sql = "SELECT f.*, m.student_name, m.member_code FROM finance_member_fees f LEFT JOIN members m ON f.member_id=m.id WHERE ".implode(' AND ',$where)." ORDER BY f.ec_year DESC, f.ec_month DESC LIMIT 500";
    $stmt = $conn->prepare($sql);
    if ($params) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $r = $stmt->get_result();
    $fees = [];
    while ($row = $r->fetch_assoc()) $fees[] = $row;
    echo json_encode(['status'=>'success','fees'=>$fees]);
    break;

case 'save_fee':
    $memberId = (int)($_POST['member_id'] ?? 0);
    $feeType = $_POST['fee_type'] ?? 'monthly';
    $amount = (float)($_POST['amount'] ?? 0);
    $ecMonth = (int)($_POST['ec_month'] ?? 0);
    $ecYear = (int)($_POST['ec_year'] ?? 0);
    $paidDate = $_POST['paid_date'] ?? null;
    $status = $_POST['status'] ?? 'paid';

    if (!$memberId || $amount <= 0) { echo json_encode(['status'=>'error','message'=>'Invalid data']); break; }
    $stmt = $conn->prepare("INSERT INTO finance_member_fees (member_id,fee_type,amount,ec_month,ec_year,paid_date,status,recorded_by) VALUES (?,?,?,?,?,?,?,?)");
    $stmt->bind_param('isdisssi', $memberId, $feeType, $amount, $ecMonth, $ecYear, $paidDate, $status, $adminId);
    $stmt->execute();
    // Also record as income transaction
    if ($status === 'paid') {
        $feeDesc = 'Monthly fee payment';
        $feeMethod = 'cash';
        $feeDate = date('Y-m-d');
        $feeStatus = 'confirmed';
        $feeCatId = 1;
        $stmt2 = $conn->prepare("INSERT INTO finance_transactions (type,category_id,member_id,amount,description,payment_method,transaction_date,ec_month,ec_year,recorded_by,status) VALUES ('income',?,?,?,?,?,?,?,?,?,?)");
        $stmt2->bind_param('iidsssiiss', $feeCatId, $memberId, $amount, $feeDesc, $feeMethod, $feeDate, $ecMonth, $ecYear, $adminId, $feeStatus);
        $stmt2->execute();
    }
    echo json_encode(['status'=>'success','message'=>'Fee recorded']);
    break;

case 'report':
    $from = $_GET['from'] ?? date('Y-01-01');
    $to = $_GET['to'] ?? date('Y-12-31');
    // Validate date formats
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = date('Y-01-01');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = date('Y-12-31');
    $data = [];

    // Summary by category
    $stmt = $conn->prepare("SELECT c.name, t.type, SUM(t.amount) total, COUNT(*) cnt FROM finance_transactions t LEFT JOIN finance_categories c ON t.category_id=c.id WHERE t.status='confirmed' AND t.transaction_date BETWEEN ? AND ? GROUP BY c.name, t.type ORDER BY t.type, total DESC");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $r = $stmt->get_result();
    $data['by_category'] = [];
    while ($row = $r->fetch_assoc()) $data['by_category'][] = $row;

    // Monthly trend
    $stmt = $conn->prepare("SELECT DATE_FORMAT(transaction_date,'%Y-%m') month, type, SUM(amount) total FROM finance_transactions WHERE status='confirmed' AND transaction_date BETWEEN ? AND ? GROUP BY month, type ORDER BY month");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $r = $stmt->get_result();
    $data['monthly'] = [];
    while ($row = $r->fetch_assoc()) $data['monthly'][] = $row;

    // Totals
    $stmt = $conn->prepare("SELECT COALESCE(SUM(CASE WHEN type='income' THEN amount END),0) income, COALESCE(SUM(CASE WHEN type='expense' THEN amount END),0) expense FROM finance_transactions WHERE status='confirmed' AND transaction_date BETWEEN ? AND ?");
    $stmt->bind_param('ss', $from, $to);
    $stmt->execute();
    $data['totals'] = $stmt->get_result()->fetch_assoc();

    echo json_encode(['status'=>'success','data'=>$data]);
    break;

default:
    echo json_encode(['status'=>'error','message'=>'Unknown action: '.$action]);
}
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
