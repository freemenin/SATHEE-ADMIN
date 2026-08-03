<?php
require_once 'include/require_permission.php';
requirePermissionAjax('ORDERS', 'view');
include('include/require_login.php');
require_once 'include/db.php';
header('Content-Type: application/json');

$order_id = intval($_GET['order_id'] ?? 0);
if ($order_id <= 0) {
  echo json_encode(['ok' => false, 'error' => 'Invalid order_id']); exit;
}

$sql = "SELECT oc.comment_id, oc.comment, oc.created_at,
               COALESCE(u.name, 'System') AS user_name
        FROM order_comments oc
        LEFT JOIN users u ON oc.user_id = u.user_id
        WHERE oc.order_id = ?
        ORDER BY oc.created_at DESC";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$res = $stmt->get_result();

$comments = [];
while ($row = $res->fetch_assoc()) {
  $row['created_at_fmt'] = date('d M Y, h:i A', strtotime($row['created_at']));
  $comments[] = $row;
}
$stmt->close();

echo json_encode(['ok' => true, 'comments' => $comments]);
?>