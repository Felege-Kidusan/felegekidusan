<?php
/**
 * Finance Department API 
 * Full CRUD for transactions, categories, budgets, member fees
 */
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/backend/services/LedgerValidation.php';
use App\Services\LedgerValidation as Input;
use App\Services\LedgerInputException;

if (empty($_SESSION['admin_logged_in'])) {
    http_response_code(401);
    echo json_encode(['status'=>'error','message'=>'Unauthorized']); exit;
}

$action = is_string($_REQUEST['action'] ?? '') ? ($_REQUEST['action'] ?? '') : '';
requirePostActions($action, ['add_transaction', 'update_transaction', 'delete_transaction', 'save_category', 'save_fee']);
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// CSRF protection for all POST requests
requireCsrfForPost();

// Check tables exist
if (!($conn instanceof mysqli)) {
    jsonResponse(['status'=>'error','message'=>'Finance is temporarily unavailable.'], 503);
}
try { $conn->query("SELECT 1 FROM finance_transactions LIMIT 0"); }
catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['status'=>'error','message'=>'Finance is not available. Ask an administrator to check the database migrations.']);
    exit;
}

/** Validate optional relationships instead of silently recording orphan IDs. */
function financeCategory(?int $id, string $type): void {
    global $conn;
    if ($id === null) return;
    $stmt = $conn->prepare('SELECT type FROM finance_categories WHERE id=?');
    $stmt->bind_param('i', $id); $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$row || $row['type'] !== $type) throw new LedgerInputException('Choose a category of the correct transaction type.');
}
function financeMember(?int $id): void {
    global $conn;
    if ($id === null) return;
    $stmt = $conn->prepare('SELECT id FROM members WHERE id=?');
    $stmt->bind_param('i', $id); $stmt->execute();
    $exists = (bool)$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$exists) throw new LedgerInputException('Member not found.');
}
$financeTransactionOpen = false;
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
    $limit = max(1, min((int)($_GET['limit'] ?? 100), 500));
    $from = Input::date($from, 'From date');
    $to = Input::date($to, 'To date');
    if ($from && $to && $from > $to) throw new LedgerInputException('From date must not be after to date.');

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
$type = Input::choice($_POST['type'] ?? '', ['income','expense'], 'Transaction type');
    $catId = Input::optionalId($_POST['category_id'] ?? null, 'Category');
    $memberId = Input::optionalId($_POST['member_id'] ?? null, 'Member');
    $amount = Input::money($_POST['amount'] ?? '');
    $desc = Input::text($_POST['description'] ?? '', 'Description', 500);
    $receipt = Input::text($_POST['receipt_number'] ?? '', 'Receipt number', 50);
    $method = Input::choice($_POST['payment_method'] ?? 'cash', ['cash','bank_transfer','mobile_money','check','other'], 'Payment method');
    $date = Input::date($_POST['transaction_date'] ?? '', 'Transaction date', date('Y-m-d'));
    $ecMonth = Input::optionalId($_POST['ec_month'] ?? null, 'Ethiopian month');
    $ecYear = Input::optionalId($_POST['ec_year'] ?? null, 'Ethiopian year');
    if (($ecMonth !== null && $ecMonth > 13) || ($ecYear !== null && $ecYear > 9999)) throw new LedgerInputException('Invalid Ethiopian month or year.');
    financeCategory($catId, $type); financeMember($memberId);
    $stmt = $conn->prepare("INSERT INTO finance_transactions (type,category_id,member_id,amount,description,receipt_number,payment_method,transaction_date,ec_month,ec_year,recorded_by,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,'confirmed')");
    // transaction_date is a string. Binding it as i truncated YYYY-MM-DD.
    $stmt->bind_param('siidssssiii', $type, $catId, $memberId, $amount, $desc, $receipt, $method, $date, $ecMonth, $ecYear, $adminId);
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$conn->insert_id,'message'=>'Transaction recorded']);
    break;

case 'update_transaction':
$id = Input::integer($_POST['id'] ?? 0, 'Transaction ID', 1);
    $catId = Input::optionalId($_POST['category_id'] ?? null, 'Category');
    $amount = Input::money($_POST['amount'] ?? '');
    $desc = Input::text($_POST['description'] ?? '', 'Description', 500);
    $receipt = Input::text($_POST['receipt_number'] ?? '', 'Receipt number', 50);
    $method = Input::choice($_POST['payment_method'] ?? 'cash', ['cash','bank_transfer','mobile_money','check','other'], 'Payment method');
    $date = Input::date($_POST['transaction_date'] ?? '', 'Transaction date', date('Y-m-d'));
    $st = Input::choice($_POST['status'] ?? 'confirmed', ['confirmed','pending','cancelled'], 'Status');
    $find = $conn->prepare('SELECT type FROM finance_transactions WHERE id=?');
    $find->bind_param('i', $id); $find->execute();
    $existing = $find->get_result()->fetch_assoc(); $find->close();
    if (!$existing) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Transaction not found.']); break; }
    financeCategory($catId, $existing['type']);
    $stmt = $conn->prepare('UPDATE finance_transactions SET category_id=?,amount=?,description=?,receipt_number=?,payment_method=?,transaction_date=?,status=? WHERE id=?');
    $stmt->bind_param('idsssssi', $catId, $amount, $desc, $receipt, $method, $date, $st, $id);
    $stmt->execute();
    echo json_encode(['status'=>'success','message'=>'Updated']);
    break;

case 'delete_transaction':
$id = Input::integer($_POST['id'] ?? 0, 'Transaction ID', 1);
    $stmt = $conn->prepare('DELETE FROM finance_transactions WHERE id=?');
    $stmt->bind_param('i', $id); $stmt->execute();
    if ($stmt->affected_rows === 0) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Transaction not found.']); break; }
    echo json_encode(['status'=>'success','message'=>'Deleted']);
    break;

case 'save_category':
$id = Input::optionalId($_POST['id'] ?? null, 'Category ID');
    $name = Input::text($_POST['name'] ?? '', 'Name', 100, true);
    $type = Input::choice($_POST['type'] ?? 'income', ['income','expense'], 'Category type');
    $desc = Input::text($_POST['description'] ?? '', 'Description', 255);
    if ($id !== null) {
        $find = $conn->prepare('SELECT type FROM finance_categories WHERE id=?');
        $find->bind_param('i', $id); $find->execute();
        $existing = $find->get_result()->fetch_assoc(); $find->close();
        if (!$existing) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Category not found.']); break; }
        if ($existing['type'] !== $type) {
            $used = $conn->prepare('SELECT id FROM finance_transactions WHERE category_id=? LIMIT 1');
            $used->bind_param('i', $id); $used->execute();
            if ($used->get_result()->fetch_assoc()) throw new LedgerInputException('Cannot change the type of a category with recorded transactions.', 409);
            $used->close();
        }
        $stmt = $conn->prepare('UPDATE finance_categories SET name=?,type=?,description=? WHERE id=?');
        $stmt->bind_param('sssi', $name, $type, $desc, $id);
    } else {
        $stmt = $conn->prepare('INSERT INTO finance_categories (name,type,description) VALUES (?,?,?)');
        $stmt->bind_param('sss', $name, $type, $desc);
    }
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$id ?: $conn->insert_id]);
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
$memberId = Input::integer($_POST['member_id'] ?? 0, 'Member', 1);
    $feeType = Input::text($_POST['fee_type'] ?? 'monthly', 'Fee type', 100, true);
    $amount = Input::money($_POST['amount'] ?? '');
    $ecMonth = Input::optionalId($_POST['ec_month'] ?? null, 'Ethiopian month');
    $ecYear = Input::optionalId($_POST['ec_year'] ?? null, 'Ethiopian year');
    if (($ecMonth !== null && $ecMonth > 13) || ($ecYear !== null && $ecYear > 9999)) throw new LedgerInputException('Invalid Ethiopian month or year.');
    $status = Input::choice($_POST['status'] ?? 'paid', ['paid','unpaid','partial'], 'Fee status');
    $paidDate = Input::date($_POST['paid_date'] ?? null, 'Paid date', $status === 'paid' ? date('Y-m-d') : null);
    financeMember($memberId);

    // A paid fee and its ledger income are ONE operation, never half a save.
    $conn->begin_transaction(); $financeTransactionOpen = true;
    $stmt = $conn->prepare('INSERT INTO finance_member_fees (member_id,fee_type,amount,ec_month,ec_year,paid_date,status,recorded_by) VALUES (?,?,?,?,?,?,?,?)');
    $stmt->bind_param('isdiissi', $memberId, $feeType, $amount, $ecMonth, $ecYear, $paidDate, $status, $adminId);
    $stmt->execute();
    $feeId = $conn->insert_id;
    if ($status === 'paid') {
        $feeDesc = $feeType === 'monthly' ? 'Monthly fee payment' : $feeType . ' fee payment';
        $feeMethod = 'cash';
        $feeDate = $paidDate;
        $feeStatus = 'confirmed';
        // IDs differ by deployment. Never assume category 1 is fee income.
        $category = $conn->query("SELECT id FROM finance_categories WHERE type='income' AND name='Monthly Contribution' ORDER BY id LIMIT 1")->fetch_assoc();
        $feeCatId = $category ? (int)$category['id'] : null;
        $stmt2 = $conn->prepare("INSERT INTO finance_transactions (type,category_id,member_id,amount,description,payment_method,transaction_date,ec_month,ec_year,recorded_by,status) VALUES ('income',?,?,?,?,?,?,?,?,?,?)");
        $stmt2->bind_param('iidsssiiis', $feeCatId, $memberId, $amount, $feeDesc, $feeMethod, $feeDate, $ecMonth, $ecYear, $adminId, $feeStatus);
        $stmt2->execute();
    }
    $conn->commit(); $financeTransactionOpen = false;
    echo json_encode(['status'=>'success','id'=>$feeId,'message'=>'Fee recorded']);
    break;

case 'report':
    $from = $_GET['from'] ?? date('Y-01-01');
    $to = $_GET['to'] ?? date('Y-12-31');
    $from = Input::date($from, 'From date', date('Y-01-01'));
    $to = Input::date($to, 'To date', date('Y-12-31'));
    if ($from > $to) throw new LedgerInputException('From date must not be after to date.');
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
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Unknown action.']);
}
} catch (LedgerInputException $e) {
    if ($financeTransactionOpen) $conn->rollback();
    http_response_code($e->httpStatus);
    echo json_encode(['status'=>'error','message'=>$e->publicMessage]);
} catch (Throwable $e) {
    if ($financeTransactionOpen) $conn->rollback();
    http_response_code(500);
    reportInternalError('Finance API request failed', $e);
    echo json_encode(['status'=>'error','message'=>'Unable to complete the finance request.']);
}
