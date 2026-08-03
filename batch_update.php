<?php
require_once 'include/require_permission.php';
requirePermission('BATCHES', 'edit');
include('include/require_login.php');
include('include/db.php');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    die("Invalid request");
}

$batch_id        = intval($_POST['batch_id'] ?? 0);
$batch_owner     = strtoupper(trim($_POST['batch_owner'] ?? ''));
$product_id      = intval($_POST['product_id'] ?? 0);
$batch_code      = trim($_POST['batch_code'] ?? '');
$product_qty     = intval($_POST['product_qty'] ?? 0);
$production_date = $_POST['production_date'] ?? date('Y-m-d');
$notes           = trim($_POST['notes'] ?? '');

$material_ids = $_POST['material_id'] ?? [];
$owners       = $_POST['material_owner_company'] ?? [];
$units        = $_POST['unit'] ?? [];
$quantities   = $_POST['quantity'] ?? [];
$rates        = $_POST['rate'] ?? [];

$allowed_owners = ['SATHEE', 'CMD'];

if ($batch_id <= 0) {
    die("Invalid Batch ID");
}

if (!in_array($batch_owner, $allowed_owners)) {
    die("Invalid batch owner company");
}

if ($product_id <= 0) {
    die("Please select product");
}

if ($batch_code === '') {
    die("Batch code is required");
}

if ($product_qty <= 0) {
    die("Quantity produced must be greater than 0");
}

if (empty($material_ids)) {
    die("Please add at least one raw material");
}

$mysqli->begin_transaction();

try {

    /*
        Check if current_stock column exists.
        If yes:
        1. Add old consumed stock back
        2. Delete old rows
        3. Insert new rows
        4. Deduct new consumed stock
    */
    $has_stock_column = false;

    $checkStock = $mysqli->query("
        SHOW COLUMNS FROM raw_materials LIKE 'current_stock'
    ");

    if ($checkStock && $checkStock->num_rows > 0) {
        $has_stock_column = true;
    }

    /*
        Reverse old stock
    */
    if ($has_stock_column) {
        $oldStmt = $mysqli->prepare("
            SELECT raw_material_id, quantity_used
            FROM batch_raw_materials
            WHERE batch_id = ?
        ");

        if (!$oldStmt) {
            throw new Exception($mysqli->error);
        }

        $oldStmt->bind_param("i", $batch_id);
        $oldStmt->execute();
        $oldResult = $oldStmt->get_result();

        while ($old = $oldResult->fetch_assoc()) {
            $old_material_id = intval($old['raw_material_id']);
            $old_quantity    = floatval($old['quantity_used']);

            if ($old_material_id > 0 && $old_quantity > 0) {
                $reverseStock = $mysqli->prepare("
                    UPDATE raw_materials
                    SET current_stock = current_stock + ?
                    WHERE raw_material_id = ?
                ");

                if (!$reverseStock) {
                    throw new Exception($mysqli->error);
                }

                $reverseStock->bind_param("di", $old_quantity, $old_material_id);
                $reverseStock->execute();
                $reverseStock->close();
            }
        }

        $oldStmt->close();
    }

    /*
        Update batch master.
        Corrected according to your actual batches table.
    */
    $updateBatch = $mysqli->prepare("
        UPDATE batches
        SET
            batch_owner = ?,
            product_id = ?,
            batch_code = ?,
            product_qty = ?,
            production_date = ?,
            notes = ?
        WHERE batch_id = ?
    ");

    if (!$updateBatch) {
        throw new Exception($mysqli->error);
    }

    $updateBatch->bind_param(
        "sisissi",
        $batch_owner,
        $product_id,
        $batch_code,
        $product_qty,
        $production_date,
        $notes,
        $batch_id
    );

    $updateBatch->execute();
    $updateBatch->close();

    /*
        Delete old raw material rows
    */
    $deleteRows = $mysqli->prepare("
        DELETE FROM batch_raw_materials
        WHERE batch_id = ?
    ");

    if (!$deleteRows) {
        throw new Exception($mysqli->error);
    }

    $deleteRows->bind_param("i", $batch_id);
    $deleteRows->execute();
    $deleteRows->close();

    /*
        Insert updated raw material rows.
        Your table has settlement fields row-wise:
        settlement_required, payable_from, payable_to, settlement_status
    */
    $insertRow = $mysqli->prepare("
        INSERT INTO batch_raw_materials
        (
            batch_id,
            raw_material_id,
            material_owner_company,
            quantity_used,
            rate,
            amount,
            settlement_required,
            payable_from,
            payable_to,
            settlement_status,
            unit
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$insertRow) {
        throw new Exception($mysqli->error);
    }

    for ($i = 0; $i < count($material_ids); $i++) {

        $material_id    = intval($material_ids[$i] ?? 0);
        $material_owner = strtoupper(trim($owners[$i] ?? ''));
        $unit           = trim($units[$i] ?? '');
        $quantity       = floatval($quantities[$i] ?? 0);
        $rate           = floatval($rates[$i] ?? 0);
        $amount         = $quantity * $rate;

        if ($material_id <= 0 || $quantity <= 0) {
            continue;
        }

        if (!in_array($material_owner, $allowed_owners)) {
            $material_owner = $batch_owner;
        }

        /*
            Your DB enum values for batch_raw_materials are lowercase:
            enum('sathee','cmd')
        */
        $db_material_owner = strtolower($material_owner);

        $settlement_required = 0;
        $payable_from = null;
        $payable_to = null;
        $settlement_status = 'none';

        /*
            Settlement logic:
            If batch owner and material owner are different,
            batch owner has to pay material owner.
        */
        if ($batch_owner !== $material_owner) {
            $settlement_required = 1;
            $payable_from = strtolower($batch_owner);
            $payable_to = strtolower($material_owner);
            $settlement_status = 'pending';
        }

        $insertRow->bind_param(
            "iisdddissss",
            $batch_id,
            $material_id,
            $db_material_owner,
            $quantity,
            $rate,
            $amount,
            $settlement_required,
            $payable_from,
            $payable_to,
            $settlement_status,
            $unit
        );

        $insertRow->execute();

        /*
            Deduct new stock after inserting row.
        */
        if ($has_stock_column) {
            $deductStock = $mysqli->prepare("
                UPDATE raw_materials
                SET current_stock = current_stock - ?
                WHERE raw_material_id = ?
            ");

            if (!$deductStock) {
                throw new Exception($mysqli->error);
            }

            $deductStock->bind_param("di", $quantity, $material_id);
            $deductStock->execute();
            $deductStock->close();
        }
    }

    $insertRow->close();

    $mysqli->commit();

    header("Location: batch_list.php?updated=1");
    exit;

} catch (Exception $e) {

    $mysqli->rollback();

    echo "<h3>Batch update failed.</h3>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    exit;
}