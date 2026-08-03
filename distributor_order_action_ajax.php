<?php
// FILE: distributor_order_action_ajax.php
session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/include/db.php';

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed']); exit;
  }

  $distId = (int)($_SESSION['dist_id'] ?? 0);
  if ($distId <= 0) { http_response_code(401); echo json_encode(['success'=>false,'message'=>'Unauthorized']); exit; }

  $order_id = (int)($_POST['order_id'] ?? 0);
  $action   = trim((string)($_POST['action'] ?? ''));
  if ($order_id <= 0) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Invalid order id']); exit; }

  // Ensure columns exist (portable)
  function ensure_col($mysqli,$table,$col,$ddl){
    $r=$mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    if(!$r || $r->num_rows===0){ $mysqli->query("ALTER TABLE `$table` ADD COLUMN $ddl"); }
  }
  ensure_col($mysqli,'orders','distributor_status',"VARCHAR(20) NOT NULL DEFAULT 'pending' AFTER distributor_id");
  ensure_col($mysqli,'orders','distributor_status_at',"DATETIME NULL AFTER distributor_status");
  ensure_col($mysqli,'orders','delivered_at',"DATETIME NULL AFTER distributor_status_at");

  // Check ownership + current status
  $stmt = $mysqli->prepare("SELECT distributor_id, distributor_status FROM orders WHERE order_id=? LIMIT 1");
  if(!$stmt){ throw new Exception('Prepare(check) failed: '.$mysqli->error); }
  $stmt->bind_param('i', $order_id);
  $stmt->execute();
  $stmt->bind_result($ownDist, $curStatus);
  if(!$stmt->fetch()){ http_response_code(404); echo json_encode(['success'=>false,'message'=>'Order not found']); exit; }
  $stmt->close();

  if ((int)$ownDist !== $distId) {
    http_response_code(403); echo json_encode(['success'=>false,'message'=>'This order is not assigned to you']); exit;
  }
  $curStatus = $curStatus ?: 'pending';

  if ($action === 'accept') {
    if ($curStatus === 'delivered') { http_response_code(409); echo json_encode(['success'=>false,'message'=>'Already delivered']); exit; }
    $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='accepted',order_status = 'Ready to Delivery',distributor_status_at=NOW() WHERE order_id=? LIMIT 1");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    echo json_encode(['success'=>true,'message'=>'Order accepted','new_status'=>'accepted']); exit;

  } elseif ($action === 'not_my_area') {
    // Mark and unassign so admin can reassign
    $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='not_my_area',order_status='Change distributor', distributor_status_at=NOW(), distributor_id=NULL WHERE order_id=? LIMIT 1");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    echo json_encode(['success'=>true,'message'=>'Flagged as not your area','new_status'=>'not_my_area']); exit;

  } elseif ($action === 'delivered') {
    if ($curStatus !== 'accepted') { http_response_code(409); echo json_encode(['success'=>false,'message'=>'Accept order before marking delivered']); exit; }
    $stmt = $mysqli->prepare("UPDATE orders SET distributor_status='delivered',order_status='delivered', distributor_status_at=NOW(), delivered_at=NOW() WHERE order_id=? LIMIT 1");
    $stmt->bind_param('i', $order_id);
    $stmt->execute();
    echo json_encode(['success'=>true,'message'=>'Marked delivered','new_status'=>'delivered']); exit;

  } else {
    http_response_code(400); echo json_encode(['success'=>false,'message'=>'Unknown action']); exit;
  }

} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Server error']);
  exit;
}
?>