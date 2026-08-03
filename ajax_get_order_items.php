<?php
require_once 'include/require_permission.php';
requirePermissionAjax('ORDERS', 'view');
include('include/require_login.php');
include('include/db.php'); // adjust path if needed

header('Content-Type: application/json');

$order_id = isset($_GET['order_id']) ? intval($_GET['order_id']) : 0;

if ($order_id <= 0) {
    echo json_encode([]);
    exit;
}

$sql = "
    SELECT 
        oi.product_id,
        oi.quantity,
        oi.unit_price,
        p.title
    FROM order_items oi
    JOIN products p ON oi.product_id = p.product_id
    WHERE oi.order_id = ?
";

$stmt = $mysqli->prepare($sql);
$stmt->bind_param("i", $order_id);
$stmt->execute();
$result = $stmt->get_result();

$order_items = [];
while ($row = $result->fetch_assoc()) {
    $order_items[] = $row;
}

echo json_encode($order_items);
?>
