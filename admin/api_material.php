<?php
/**
 * Material Department API 
 * Full CRUD for inventory items, transactions, requests
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

try { $conn->query("SELECT 1 FROM material_items LIMIT 0"); }
catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>'Material tables not found. Run migration 004.']);
    exit;
}

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
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $catId = (int)($_POST['category_id'] ?? 0);
    $desc = trim($_POST['description'] ?? '');
    $qty = (int)($_POST['quantity'] ?? 0);
    $minQty = (int)($_POST['min_quantity'] ?? 0);
    $unit = trim($_POST['unit'] ?? 'piece');
    $loc = trim($_POST['location'] ?? '');
    $cond = $_POST['condition_status'] ?? 'good';
    $price = !empty($_POST['purchase_price']) ? (float)$_POST['purchase_price'] : null;
    $pDate = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    $status = $qty <= 0 ? 'out_of_stock' : ($qty <= $minQty ? 'low_stock' : 'in_stock');

    if (!$name) { echo json_encode(['status'=>'error','message'=>'Name required']); break; }

    if ($id > 0) {
        $stmt = $conn->prepare("UPDATE material_items SET name=?,category_id=?,description=?,quantity=?,min_quantity=?,unit=?,location=?,condition_status=?,purchase_price=?,purchase_date=?,status=? WHERE id=?");
        $stmt->bind_param('sisssissdssi', $name, $catId, $desc, $qty, $minQty, $unit, $loc, $cond, $price, $pDate, $status, $id);
    } else {
        $stmt = $conn->prepare("INSERT INTO material_items (name,category_id,description,quantity,min_quantity,unit,location,condition_status,purchase_price,purchase_date,status,added_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->bind_param('sisssissdssi', $name, $catId, $desc, $qty, $minQty, $unit, $loc, $cond, $price, $pDate, $status, $adminId);
    }
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$id?:$conn->insert_id]);
    break;

case 'delete_item':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['status'=>'error','message'=>'Missing ID']); break; }
    $stmt = $conn->prepare("DELETE FROM material_items WHERE id=?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    break;

case 'add_transaction':
    $itemId = (int)($_POST['item_id'] ?? 0);
    $type = $_POST['type'] ?? '';
    $qty = (int)($_POST['quantity'] ?? 0);
    $reason = trim($_POST['reason'] ?? '');
    $handler = trim($_POST['handled_by'] ?? '');
    $date = $_POST['transaction_date'] ?? date('Y-m-d');

    if (!$itemId || !in_array($type,['incoming','outgoing','adjustment','disposal']) || $qty <= 0) {
        echo json_encode(['status'=>'error','message'=>'Invalid data']); break;
    }
    $stmt = $conn->prepare("INSERT INTO material_transactions (item_id,type,quantity,reason,handled_by,recorded_by,transaction_date) VALUES (?,?,?,?,?,?,?)");
    $stmt->bind_param('isissis', $itemId, $type, $qty, $reason, $handler, $adminId, $date);
    $stmt->execute();

    // Update item quantity using prepared statements
    if ($type === 'incoming') {
        $stmt2 = $conn->prepare("UPDATE material_items SET quantity=quantity+? WHERE id=?");
        $stmt2->bind_param('ii', $qty, $itemId);
        $stmt2->execute();
    } elseif ($type === 'outgoing' || $type === 'disposal') {
        $stmt2 = $conn->prepare("UPDATE material_items SET quantity=GREATEST(quantity-?,0) WHERE id=?");
        $stmt2->bind_param('ii', $qty, $itemId);
        $stmt2->execute();
    }

    // Update status
    $stmt3 = $conn->prepare("SELECT quantity, min_quantity FROM material_items WHERE id=?");
    $stmt3->bind_param('i', $itemId);
    $stmt3->execute();
    $r = $stmt3->get_result();
    if ($r && $row = $r->fetch_assoc()) {
        $q=(int)$row['quantity']; $mq=(int)$row['min_quantity'];
        $st = $q<=0?'out_of_stock':($q<=$mq?'low_stock':'in_stock');
        $stmt4 = $conn->prepare("UPDATE material_items SET status=? WHERE id=?");
        $stmt4->bind_param('si', $st, $itemId);
        $stmt4->execute();
    }
    echo json_encode(['status'=>'success','message'=>'Transaction recorded']);
    break;

case 'categories':
    $r = $conn->query("SELECT * FROM material_categories ORDER BY name");
    $cats = [];
    while ($row = $r->fetch_assoc()) $cats[] = $row;
    echo json_encode(['status'=>'success','categories'=>$cats]);
    break;

case 'save_category':
    $id = (int)($_POST['id'] ?? 0);
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    if (!$name) { echo json_encode(['status'=>'error','message'=>'Name required']); break; }
    if ($id > 0) { $stmt=$conn->prepare("UPDATE material_categories SET name=?,description=? WHERE id=?");$stmt->bind_param('ssi',$name,$desc,$id); }
    else { $stmt=$conn->prepare("INSERT INTO material_categories (name,description) VALUES (?,?)");$stmt->bind_param('ss',$name,$desc); }
    $stmt->execute();
    echo json_encode(['status'=>'success','id'=>$id?:$conn->insert_id]);
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
    $itemId = !empty($_POST['item_id']) ? (int)$_POST['item_id'] : null;
    $itemName = trim($_POST['item_name'] ?? '');
    $qty = (int)($_POST['quantity'] ?? 1);
    $by = trim($_POST['requested_by'] ?? '');
    $dept = trim($_POST['department'] ?? '');
    $reason = trim($_POST['reason'] ?? '');
    if (!$by) { echo json_encode(['status'=>'error','message'=>'Requested by required']); break; }
    $stmt = $conn->prepare("INSERT INTO material_requests (item_id,item_name,quantity,requested_by,department,reason) VALUES (?,?,?,?,?,?)");
    $stmt->bind_param('isisss', $itemId, $itemName, $qty, $by, $dept, $reason);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    break;

case 'update_request':
    $id = (int)($_POST['id'] ?? 0);
    $status = $_POST['status'] ?? '';
    if (!$id || !in_array($status,['approved','denied','fulfilled'])) { echo json_encode(['status'=>'error','message'=>'Invalid']); break; }
    $stmt = $conn->prepare("UPDATE material_requests SET status=?, approved_by=? WHERE id=?");
    $stmt->bind_param('sii', $status, $adminId, $id);
    $stmt->execute();
    echo json_encode(['status'=>'success']);
    break;

default:
    echo json_encode(['status'=>'error','message'=>'Unknown action']);
}
} catch (Exception $e) {
    echo json_encode(['status'=>'error','message'=>$e->getMessage()]);
}
