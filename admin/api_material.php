<?php
/**
 * Material Department API 
 * Full CRUD for inventory items, transactions, requests
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
requirePostActions($action, ['save_item', 'delete_item', 'add_transaction', 'save_category', 'save_request', 'update_request']);
$adminId = (int)($_SESSION['admin_id'] ?? 0);

// CSRF protection for all POST requests
requireCsrfForPost();

if (!($conn instanceof mysqli)) {
    jsonResponse(['status'=>'error','message'=>'Materials is temporarily unavailable.'], 503);
}
try { $conn->query("SELECT 1 FROM material_items LIMIT 0"); }
catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['status'=>'error','message'=>'Materials is not available. Ask an administrator to check the database migrations.']);
    exit;
}

$materialTransactionOpen = false;
try {
switch ($action) {

case 'dashboard':
    $data = [];
    $r = $conn->query("SELECT COUNT(*) t, SUM(status='in_stock') s, SUM(status='low_stock') l, SUM(status='out_of_stock') o, SUM(status='maintenance') m FROM material_items");
    if($r&&$row=$r->fetch_assoc()){$data['total']=(int)$row['t'];$data['in_stock']=(int)$row['s'];$data['low_stock']=(int)$row['l'];$data['out_of_stock']=(int)$row['o'];$data['maintenance']=(int)$row['m'];}
    $r = $conn->query("SELECT c.name, COUNT(i.id) cnt FROM material_categories c LEFT JOIN material_items i ON i.category_id=c.id GROUP BY c.id ORDER BY cnt DESC");
    $data['by_category'] = [];
    while ($row = $r->fetch_assoc()) $data['by_category'][] = $row;
    $r = $conn->query("SELECT i.*, c.name as category_name FROM material_items i LEFT JOIN material_categories c ON i.category_id=c.id WHERE i.status='low_stock' OR i.quantity <= i.min_quantity ORDER BY i.quantity ASC LIMIT 10");
    $data['low_stock_items'] = [];
    while ($row = $r->fetch_assoc()) $data['low_stock_items'][] = $row;
    $r = $conn->query("SELECT t.*, i.name as item_name FROM material_transactions t LEFT JOIN material_items i ON t.item_id=i.id ORDER BY t.created_at DESC LIMIT 15");
    $data['recent'] = [];
    while ($row = $r->fetch_assoc()) $data['recent'][] = $row;
    $r = $conn->query("SELECT COUNT(*) c FROM material_requests WHERE status='pending'");
    if($r&&$row=$r->fetch_assoc())$data['pending_requests']=(int)$row['c'];
    echo json_encode(['status'=>'success','data'=>$data]);
    break;

case 'items':
    $cat = (int)($_GET['category_id'] ?? 0);
    $status = $_GET['status'] ?? '';
    $search = $_GET['search'] ?? '';
    $where = ['1=1'];
    $params = []; $types = '';
    if ($cat) { $where[] = "i.category_id=?"; $params[] = $cat; $types .= 'i'; }
    if ($status && in_array($status, ['in_stock','low_stock','out_of_stock','maintenance'])) { $where[] = "i.status=?"; $params[] = $status; $types .= 's'; }
    if ($search) { $where[] = "(i.name LIKE ? OR i.description LIKE ?)"; $s="%$search%"; $params[]=$s; $params[]=$s; $types .= 'ss'; }
    $sql = "SELECT i.*, c.name as category_name FROM material_items i LEFT JOIN material_categories c ON i.category_id=c.id WHERE ".implode(' AND ',$where)." ORDER BY i.name";
    $stmt = $conn->prepare($sql);
    if ($params) { $stmt->bind_param($types, ...$params); }
    $stmt->execute();
    $r = $stmt->get_result();
    $items = [];
    while ($row = $r->fetch_assoc()) $items[] = $row;
    echo json_encode(['status'=>'success','items'=>$items]);
    break;

case 'save_item':
$id = Input::optionalId($_POST['id'] ?? null, 'Item ID');
    $name = Input::text($_POST['name'] ?? '', 'Name', 150, true);
    $catId = Input::optionalId($_POST['category_id'] ?? null, 'Category');
    $desc = Input::text($_POST['description'] ?? '', 'Description', 500);
    $qty = Input::integer($_POST['quantity'] ?? 0, 'Quantity');
    $minQty = Input::integer($_POST['min_quantity'] ?? 0, 'Minimum quantity');
    $unit = Input::text($_POST['unit'] ?? 'piece', 'Unit', 30, true);
    $loc = Input::text($_POST['location'] ?? '', 'Location', 100);
    $cond = Input::choice($_POST['condition_status'] ?? 'good', ['good','fair','poor','damaged','disposed'], 'Condition');
    $price = ($_POST['purchase_price'] ?? '') !== '' ? Input::money($_POST['purchase_price'], 'Purchase price', true) : null;
    $pDate = Input::date($_POST['purchase_date'] ?? null, 'Purchase date');
    $status = $qty <= 0 ? 'out_of_stock' : ($qty <= $minQty ? 'low_stock' : 'in_stock');
    if ($catId !== null) {
        $find = $conn->prepare('SELECT id FROM material_categories WHERE id=?');
        $find->bind_param('i', $catId); $find->execute();
        if (!$find->get_result()->fetch_assoc()) throw new LedgerInputException('Category not found.');
        $find->close();
    }
    if ($id !== null) {
        $find = $conn->prepare('SELECT id FROM material_items WHERE id=?');
        $find->bind_param('i', $id); $find->execute();
        if (!$find->get_result()->fetch_assoc()) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Item not found.']); break; }
        $find->close();
        $stmt = $conn->prepare('UPDATE material_items SET name=?,category_id=?,description=?,quantity=?,min_quantity=?,unit=?,location=?,condition_status=?,purchase_price=?,purchase_date=?,status=? WHERE id=?');
        $stmt->bind_param('sisiisssdssi', $name, $catId, $desc, $qty, $minQty, $unit, $loc, $cond, $price, $pDate, $status, $id);
    } else {
        $stmt = $conn->prepare('INSERT INTO material_items (name,category_id,description,quantity,min_quantity,unit,location,condition_status,purchase_price,purchase_date,status,added_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
        $stmt->bind_param('sisiisssdssi', $name, $catId, $desc, $qty, $minQty, $unit, $loc, $cond, $price, $pDate, $status, $adminId);
    }
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$id ?: $conn->insert_id]);
    break;

case 'delete_item':
$id = Input::integer($_POST['id'] ?? 0, 'Item ID', 1);
    $conn->begin_transaction(); $materialTransactionOpen = true;
    $find = $conn->prepare('SELECT id FROM material_items WHERE id=? FOR UPDATE');
    $find->bind_param('i', $id); $find->execute();
    if (!$find->get_result()->fetch_assoc()) {
        $conn->rollback(); $materialTransactionOpen = false;
        http_response_code(404); echo json_encode(['status'=>'error','message'=>'Item not found.']); break;
    }
    // Keep inventory history intelligible; do not leave orphan movements.
    $used = $conn->prepare('SELECT id FROM material_transactions WHERE item_id=? LIMIT 1');
    $used->bind_param('i', $id); $used->execute();
    if ($used->get_result()->fetch_assoc()) throw new LedgerInputException('An item with movement history cannot be deleted.', 409);
    $used->close();
    $used = $conn->prepare('SELECT id FROM material_requests WHERE item_id=? LIMIT 1');
    $used->bind_param('i', $id); $used->execute();
    if ($used->get_result()->fetch_assoc()) throw new LedgerInputException('An item linked to a request cannot be deleted.', 409);
    $used->close();
    $stmt = $conn->prepare('DELETE FROM material_items WHERE id=?');
    $stmt->bind_param('i', $id); $stmt->execute();
    $conn->commit(); $materialTransactionOpen = false;
    echo json_encode(['status'=>'success']);
    break;

case 'add_transaction':
$itemId = Input::integer($_POST['item_id'] ?? 0, 'Item', 1);
    $type = Input::choice($_POST['type'] ?? '', ['incoming','outgoing','adjustment','disposal'], 'Movement type');
    // Adjustment records the counted balance (including zero), not a delta.
    $qty = Input::integer($_POST['quantity'] ?? 0, 'Quantity', $type === 'adjustment' ? 0 : 1);
    $reason = Input::text($_POST['reason'] ?? '', 'Reason', 255);
    $handler = Input::text($_POST['handled_by'] ?? '', 'Handled by', 100);
    $date = Input::date($_POST['transaction_date'] ?? '', 'Transaction date', date('Y-m-d'));
    $conn->begin_transaction(); $materialTransactionOpen = true;
    // Lock before checking availability, so two staff cannot spend the same stock.
    $find = $conn->prepare('SELECT quantity, min_quantity, status FROM material_items WHERE id=? FOR UPDATE');
    $find->bind_param('i', $itemId); $find->execute();
    $item = $find->get_result()->fetch_assoc(); $find->close();
    if (!$item) {
        $conn->rollback(); $materialTransactionOpen = false;
        http_response_code(404); echo json_encode(['status'=>'error','message'=>'Item not found.']); break;
    }
    $balance = (int)$item['quantity'];
    if (($type === 'outgoing' || $type === 'disposal') && $qty > $balance) {
        throw new LedgerInputException('Insufficient stock. Available quantity: ' . $balance . '.', 409);
    }
    $newQty = $type === 'adjustment' ? $qty : ($type === 'incoming' ? $balance + $qty : $balance - $qty);
    if ($newQty > 2147483647 || $newQty < 0) throw new LedgerInputException('Resulting stock quantity is outside the allowed range.');
    $st = $item['status'] === 'maintenance' ? 'maintenance'
        : ($newQty === 0 ? 'out_of_stock' : ($newQty <= (int)$item['min_quantity'] ? 'low_stock' : 'in_stock'));
    $stmt = $conn->prepare('INSERT INTO material_transactions (item_id,type,quantity,reason,handled_by,recorded_by,transaction_date) VALUES (?,?,?,?,?,?,?)');
    $stmt->bind_param('isissis', $itemId, $type, $qty, $reason, $handler, $adminId, $date);
    $stmt->execute();
    $movementId = $conn->insert_id;
    $stmt2 = $conn->prepare('UPDATE material_items SET quantity=?, status=? WHERE id=?');
    $stmt2->bind_param('isi', $newQty, $st, $itemId); $stmt2->execute();
    $conn->commit(); $materialTransactionOpen = false;
    echo json_encode(['status'=>'success','id'=>$movementId,'quantity'=>$newQty,'message'=>'Transaction recorded']);
    break;

case 'categories':
    $r = $conn->query("SELECT * FROM material_categories ORDER BY name");
    $cats = [];
    while ($row = $r->fetch_assoc()) $cats[] = $row;
    echo json_encode(['status'=>'success','categories'=>$cats]);
    break;

case 'save_category':
$id = Input::optionalId($_POST['id'] ?? null, 'Category ID');
    $name = Input::text($_POST['name'] ?? '', 'Name', 100, true);
    $desc = Input::text($_POST['description'] ?? '', 'Description', 255);
    if ($id !== null) {
        $find = $conn->prepare('SELECT id FROM material_categories WHERE id=?');
        $find->bind_param('i', $id); $find->execute();
        if (!$find->get_result()->fetch_assoc()) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Category not found.']); break; }
        $find->close();
        $stmt = $conn->prepare('UPDATE material_categories SET name=?,description=? WHERE id=?');
        $stmt->bind_param('ssi', $name, $desc, $id);
    } else {
        $stmt = $conn->prepare('INSERT INTO material_categories (name,description) VALUES (?,?)');
        $stmt->bind_param('ss', $name, $desc);
    }
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$id ?: $conn->insert_id]);
    break;

case 'requests':
    $status = $_GET['status'] ?? '';
    $validStatuses = ['pending','approved','denied','fulfilled'];
    if ($status && in_array($status, $validStatuses)) {
        $stmt = $conn->prepare("SELECT r.*, i.name as item_name_ref FROM material_requests r LEFT JOIN material_items i ON r.item_id=i.id WHERE r.status=? ORDER BY r.created_at DESC LIMIT 100");
        $stmt->bind_param('s', $status);
        $stmt->execute();
        $r = $stmt->get_result();
    } else {
        $r = $conn->query("SELECT r.*, i.name as item_name_ref FROM material_requests r LEFT JOIN material_items i ON r.item_id=i.id ORDER BY r.created_at DESC LIMIT 100");
    }
    $reqs = [];
    while ($row = $r->fetch_assoc()) $reqs[] = $row;
    echo json_encode(['status'=>'success','requests'=>$reqs]);
    break;

case 'save_request':
$itemId = Input::optionalId($_POST['item_id'] ?? null, 'Item');
    $itemName = Input::text($_POST['item_name'] ?? '', 'Item name', 150, $itemId === null);
    $qty = Input::integer($_POST['quantity'] ?? 1, 'Quantity', 1);
    $by = Input::text($_POST['requested_by'] ?? '', 'Requested by', 100, true);
    $dept = Input::text($_POST['department'] ?? '', 'Department', 100);
    $reason = Input::text($_POST['reason'] ?? '', 'Reason', 500);
    if ($itemId !== null) {
        $find = $conn->prepare('SELECT id FROM material_items WHERE id=?');
        $find->bind_param('i', $itemId); $find->execute();
        if (!$find->get_result()->fetch_assoc()) throw new LedgerInputException('Item not found.');
        $find->close();
    }
    $stmt = $conn->prepare('INSERT INTO material_requests (item_id,item_name,quantity,requested_by,department,reason) VALUES (?,?,?,?,?,?)');
    $stmt->bind_param('isisss', $itemId, $itemName, $qty, $by, $dept, $reason);
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$conn->insert_id]);
    break;

case 'update_request':
$id = Input::integer($_POST['id'] ?? 0, 'Request ID', 1);
    $status = Input::choice($_POST['status'] ?? '', ['approved','denied','fulfilled'], 'Request status');
    $find = $conn->prepare('SELECT id FROM material_requests WHERE id=?');
    $find->bind_param('i', $id); $find->execute();
    if (!$find->get_result()->fetch_assoc()) { http_response_code(404); echo json_encode(['status'=>'error','message'=>'Request not found.']); break; }
    $find->close();
    $stmt = $conn->prepare('UPDATE material_requests SET status=?, approved_by=? WHERE id=?');
    $stmt->bind_param('sii', $status, $adminId, $id); $stmt->execute();
    echo json_encode(['status'=>'success']);
    break;

default:
    http_response_code(400);
    echo json_encode(['status'=>'error','message'=>'Unknown action']);
}
} catch (LedgerInputException $e) {
    if ($materialTransactionOpen) $conn->rollback();
    http_response_code($e->httpStatus);
    echo json_encode(['status'=>'error','message'=>$e->publicMessage]);
} catch (Throwable $e) {
    if ($materialTransactionOpen) $conn->rollback();
    http_response_code(500);
    reportInternalError('Material API request failed', $e);
    echo json_encode(['status'=>'error','message'=>'Unable to complete the material request.']);
}
