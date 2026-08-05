<?php
require_once 'include/require_permission.php';
requirePermissionAjaxAny([
    ['PRODUCTS', 'view'],
    ['ORDERS', 'add'],
    ['ORDERS', 'edit'],
]);
include('include/require_login.php');
include('include/db.php'); // Database connection

header('Content-Type: application/json');

// Fetch all active products
$sql = "SELECT product_id, title, retail_price, wholesale_price, cost_price FROM products WHERE status = 'active' ORDER BY title ASC";
$result = $mysqli->query($sql);

$products = [];

while ($row = $result->fetch_assoc()) {
    $products[] = [
        'product_id'      => $row['product_id'],
        'title'           => $row['title'],
        'retail_price'    => $row['retail_price'],
        'wholesale_price' => $row['wholesale_price'],
        'cost_price'      => $row['cost_price']
    ];
}

// Return as JSON
echo json_encode($products);
?>
