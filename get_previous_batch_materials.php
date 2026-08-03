<?php
include('include/require_login.php');
include('include/db.php');

header('Content-Type: application/json');

$batch_owner = strtoupper(trim($_POST['batch_owner'] ?? ''));
$product_id  = intval($_POST['product_id'] ?? 0);
$product_qty = floatval($_POST['product_qty'] ?? 0);

if (!in_array($batch_owner, ['SATHEE', 'CMD']) || $product_id <= 0 || $product_qty <= 0) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid input data'
    ]);
    exit;
}

/*
    Find latest matching batch:
    Same company + same product + same quantity
*/
$stmt = $mysqli->prepare("
    SELECT batch_id, batch_code
    FROM batches
    WHERE batch_owner = ?
      AND product_id = ?
      AND product_qty = ?
    ORDER BY batch_id DESC
    LIMIT 1
");

if (!$stmt) {
    echo json_encode([
        'status' => 'error',
        'message' => $mysqli->error
    ]);
    exit;
}

$stmt->bind_param("sid", $batch_owner, $product_id, $product_qty);
$stmt->execute();
$batch = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$batch) {
    echo json_encode([
        'status' => 'not_found',
        'message' => 'No previous batch formula found'
    ]);
    exit;
}

$batch_id = intval($batch['batch_id']);

$itemStmt = $mysqli->prepare("
    SELECT 
        brm.raw_material_id,
        brm.quantity_used,
        brm.unit,
        brm.material_owner_company,
        brm.rate,
        brm.amount,

        rm.material_name,
        rm.owner_type,
        rm.current_stock,
        rm.average_price

    FROM batch_raw_materials brm
    LEFT JOIN raw_materials rm 
        ON brm.raw_material_id = rm.raw_material_id
    WHERE brm.batch_id = ?
    ORDER BY brm.id ASC
");

if (!$itemStmt) {
    echo json_encode([
        'status' => 'error',
        'message' => $mysqli->error
    ]);
    exit;
}

$itemStmt->bind_param("i", $batch_id);
$itemStmt->execute();
$result = $itemStmt->get_result();

$items = [];

while ($row = $result->fetch_assoc()) {

    $quantity_used = floatval($row['quantity_used']);

    $rate = floatval($row['average_price']);
    if ($rate <= 0) {
        $rate = floatval($row['rate']);
    }

    $amount = $quantity_used * $rate;

    $owner = $row['owner_type'] ?: $row['material_owner_company'];

    $items[] = [
        'raw_material_id'         => intval($row['raw_material_id']),
        'material_name'           => $row['material_name'] ?? '',
        'quantity_used'           => number_format($quantity_used, 2, '.', ''),
        'unit'                    => $row['unit'] ?? '',
        'material_owner_company'  => strtoupper($owner),
        'owner_type'              => strtoupper($owner),
        'rate'                    => number_format($rate, 2, '.', ''),
        'amount'                  => number_format($amount, 2, '.', ''),
        'current_stock'           => number_format(floatval($row['current_stock']), 2, '.', '')
    ];
}

$itemStmt->close();

if (empty($items)) {
    echo json_encode([
        'status' => 'not_found',
        'message' => 'Previous batch found but no raw materials found'
    ]);
    exit;
}

echo json_encode([
    'status'     => 'found',
    'batch_id'   => $batch_id,
    'batch_code' => $batch['batch_code'],
    'items'      => $items
]);
exit;
?>