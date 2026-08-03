<?php
require_once 'include/require_permission.php';
requirePermissionAjax('CUSTOMERS', 'view');
include('include/require_login.php');
include('include/db.php');

header('Content-Type: application/json');

$mobile = isset($_POST['mobile_number']) ? trim($_POST['mobile_number']) : '';

if ($mobile === '') {
    echo json_encode([
        'status' => false,
        'message' => 'Mobile number required'
    ]);
    exit;
}

$response = [
    'status' => false,
    'customer_id' => null,
    'full_name' => '',
    'email' => '',
    'address' => '',
    'landmark' => '',
    'city' => '',
    'state' => '',
    'pincode' => '',
    'last_order' => [
        'exists' => false,
        'order_id' => null,
        'invoice_number' => '',
        'distributor_id' => '',
        'items' => []
    ]
];

/*
|--------------------------------------------------------------------------
| 1. Get Customer
|--------------------------------------------------------------------------
*/
$customer_stmt = $mysqli->prepare("
    SELECT 
        customer_id,
        full_name,
        mobile_number,
        email,
        address,
        landmark,
        city,
        state,
        pincode
    FROM customers
    WHERE mobile_number = ?
    LIMIT 1
");

$customer_stmt->bind_param("s", $mobile);
$customer_stmt->execute();
$customer_result = $customer_stmt->get_result();

if ($customer_result->num_rows == 0) {
    echo json_encode($response);
    exit;
}

$customer = $customer_result->fetch_assoc();

$response['status'] = true;
$response['customer_id'] = $customer['customer_id'];
$response['full_name'] = $customer['full_name'];
$response['email'] = $customer['email'];
$response['address'] = $customer['address'];
$response['landmark'] = $customer['landmark'];
$response['city'] = $customer['city'];
$response['state'] = $customer['state'];
$response['pincode'] = $customer['pincode'];

$customer_id = (int)$customer['customer_id'];

/*
|--------------------------------------------------------------------------
| 2. Get Last Order Of This Customer
|--------------------------------------------------------------------------
*/
$order_stmt = $mysqli->prepare("
    SELECT 
        order_id,
        invoice_number,
        distributor_id,
        order_date
    FROM orders
    WHERE customer_id = ?
    ORDER BY 
        order_date DESC,
        order_id DESC
    LIMIT 1
");

$order_stmt->bind_param("i", $customer_id);
$order_stmt->execute();
$order_result = $order_stmt->get_result();

if ($order_result->num_rows == 0) {
    echo json_encode($response);
    exit;
}

$order = $order_result->fetch_assoc();

$response['last_order']['exists'] = true;
$response['last_order']['order_id'] = $order['order_id'];
$response['last_order']['invoice_number'] = $order['invoice_number'];
$response['last_order']['distributor_id'] = $order['distributor_id'];

$order_id = (int)$order['order_id'];

/*
|--------------------------------------------------------------------------
| 3. Get Last Order Items
|--------------------------------------------------------------------------
| This assumes your order_items columns are:
| order_id, product_id, quantity, unit_price
|--------------------------------------------------------------------------
*/
$item_stmt = $mysqli->prepare("
    SELECT 
        oi.product_id,
        oi.quantity,
        oi.unit_price,
        p.title,
        p.retail_price
    FROM order_items oi
    LEFT JOIN products p ON p.product_id = oi.product_id
    WHERE oi.order_id = ?
    ORDER BY oi.product_id ASC
");

$item_stmt->bind_param("i", $order_id);
$item_stmt->execute();
$item_result = $item_stmt->get_result();

while ($item = $item_result->fetch_assoc()) {
    $response['last_order']['items'][] = [
        'product_id' => $item['product_id'],
        'quantity' => $item['quantity'],
        'unit_price' => $item['unit_price'] > 0 ? $item['unit_price'] : $item['retail_price'],
        'title' => $item['title']
    ];
}

echo json_encode($response);
exit;
?>