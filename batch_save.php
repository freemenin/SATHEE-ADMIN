<?php
require_once 'include/require_permission.php';
requirePermission('BATCHES', 'add');
include('include/require_login.php');
include('include/db.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: batch_add.php");
    exit;
}

$batch_owner     = strtoupper($_POST['batch_owner'] ?? 'SATHEE');
$product_id      = intval($_POST['product_id'] ?? 0);
$batch_code      = trim($_POST['batch_code'] ?? '');
$product_qty     = floatval($_POST['product_qty'] ?? 0);
$production_date = $_POST['production_date'] ?? date('Y-m-d');

$material_ids    = $_POST['material_id'] ?? [];
$units           = $_POST['unit'] ?? [];
$quantities      = $_POST['quantity'] ?? [];
$rates           = $_POST['rate'] ?? [];
$amounts         = $_POST['amount'] ?? [];
$material_owners = $_POST['material_owner_company'] ?? [];

$created_by = $_SESSION['user_id'] ?? 0;

if (!in_array($batch_owner, ['SATHEE', 'CMD'])) {
    $batch_owner = 'SATHEE';
}

if ($product_id <= 0 || $batch_code == '' || $product_qty <= 0 || empty($material_ids)) {
    die("Invalid batch data.");
}

$mysqli->begin_transaction();

try {

    // Insert batch master
    $stmt = $mysqli->prepare("
        INSERT INTO batches 
        (
            product_id,
            batch_code,
            production_date,
            product_qty,
            batch_owner,
            created_by
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        throw new Exception("Batch prepare failed: " . $mysqli->error);
    }

    $stmt->bind_param(
        "issdsi",
        $product_id,
        $batch_code,
        $production_date,
        $product_qty,
        $batch_owner,
        $created_by
    );

    if (!$stmt->execute()) {
        throw new Exception("Batch insert failed: " . $stmt->error);
    }

    $batch_id = $stmt->insert_id;
    $stmt->close();


    // Insert batch raw materials
    $itemStmt = $mysqli->prepare("
        INSERT INTO batch_raw_materials
        (
            batch_id,
            raw_material_id,
            unit,
            quantity_used,
            material_owner_company,
            rate,
            amount,
            settlement_required,
            payable_from,
            payable_to,
            settlement_status
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$itemStmt) {
        throw new Exception("Raw material prepare failed: " . $mysqli->error);
    }


    // Update raw material stock
    $stockStmt = $mysqli->prepare("
        UPDATE raw_materials
        SET current_stock = current_stock - ?
        WHERE raw_material_id = ?
    ");

    if (!$stockStmt) {
        throw new Exception("Stock update prepare failed: " . $mysqli->error);
    }


    foreach ($material_ids as $index => $material_id) {

        $material_id = intval($material_id);
        $unit = trim($units[$index] ?? '');
        $quantity_used = floatval($quantities[$index] ?? 0);
        $rate = floatval($rates[$index] ?? 0);
        $amount = floatval($amounts[$index] ?? 0);

        $material_owner_company = strtoupper($material_owners[$index] ?? 'SATHEE');

        if (!in_array($material_owner_company, ['SATHEE', 'CMD'])) {
            $material_owner_company = 'SATHEE';
        }

        if ($material_id <= 0 || $quantity_used <= 0) {
            continue;
        }

        if ($amount <= 0) {
            $amount = $quantity_used * $rate;
        }

        // Your batch_raw_materials enum is lowercase, so save lowercase
        $material_owner_db = strtolower($material_owner_company);

        if ($batch_owner !== $material_owner_company) {
            $settlement_required = 1;
            $payable_from = strtolower($batch_owner);
            $payable_to = strtolower($material_owner_company);
            $settlement_status = 'pending';
        } else {
            $settlement_required = 0;
            $payable_from = null;
            $payable_to = null;
            $settlement_status = 'none';
        }

        $itemStmt->bind_param(
            "iisdsddisss",
            $batch_id,
            $material_id,
            $unit,
            $quantity_used,
            $material_owner_db,
            $rate,
            $amount,
            $settlement_required,
            $payable_from,
            $payable_to,
            $settlement_status
        );

        if (!$itemStmt->execute()) {
            throw new Exception("Raw material insert failed: " . $itemStmt->error);
        }

        $stockStmt->bind_param("di", $quantity_used, $material_id);

        if (!$stockStmt->execute()) {
            throw new Exception("Stock update failed: " . $stockStmt->error);
        }
    }

    $itemStmt->close();
    $stockStmt->close();

    $mysqli->commit();

    header("Location: batch_list.php?success=1");
    exit;

} catch (Exception $e) {

    $mysqli->rollback();

    echo "Error: " . $e->getMessage();
    exit;
}
?>