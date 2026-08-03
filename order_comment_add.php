<?php
require_once 'include/require_permission.php';
requirePermissionAjax('ORDERS', 'edit');
include('include/require_login.php');
require_once 'include/db.php';
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  echo json_encode(['ok' => false, 'error' => 'Method not allowed']); exit;
}

$order_id = intval($_POST['order_id'] ?? 0);
$comment  = trim($_POST['comment'] ?? '');
$user_id  = $_SESSION['user_id'] ?? null; // assumes you store user_id in session

if ($order_id <= 0 || $comment === '') {
  echo json_encode(['ok' => false, 'error' => 'Invalid data']); exit;
}

$stmt = $mysqli->prepare("INSERT INTO order_comments (order_id, user_id, comment) VALUES (?,?,?)");
$stmt->bind_param("iis", $order_id, $user_id, $comment);
$ok = $stmt->execute();
$stmt->close();

echo json_encode(['ok' => (bool)$ok]);
?>