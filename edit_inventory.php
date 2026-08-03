<?php
require_once 'include/require_permission.php';
requirePermission('INVENTORY', 'edit');
include('include/require_login.php');
?>
<?php include('include/header.php'); ?>
<?php
$toast = "";

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['inventory_id'])) {
    echo '<div class="container mt-5"><div class="alert alert-danger">❌ You must select an inventory entry to edit.</div></div>';
    include('include/footer.php');
    exit;
}

$inventory_id = $_POST['inventory_id'];
$stmt = $mysqli->prepare("SELECT * FROM inventory WHERE inventory_id = ?");
$stmt->bind_param("i", $inventory_id);
$stmt->execute();
$result = $stmt->get_result();
$inventory = $result->fetch_assoc();

if (!$inventory) {
    echo '<div class="container mt-5"><div class="alert alert-danger">❌ Inventory record not found.</div></div>';
    include('include/footer.php');
    exit;
}

if (isset($_POST['update'])) {
    $qty = $_POST['qty_change'];
    $unit = $_POST['unit'];
    $action = $_POST['action'];
    $source = $_POST['source'];
    $note = $_POST['note'];
    $cost = $_POST['cost_price'] ?? null;
    $wholesale = $_POST['wholesale_price'] ?? null;
    $retail = $_POST['retail_price'] ?? null;

    $stmt = $mysqli->prepare("UPDATE inventory SET qty_change=?, unit=?, action=?, source=?, note=?, cost_price=?, wholesale_price=?, retail_price=? WHERE inventory_id = ?");
    $stmt->bind_param("dsssssddi", $qty, $unit, $action, $source, $note, $cost, $wholesale, $retail, $inventory_id);

    if ($stmt->execute()) {
        $toast = '<div class="toast toast-sa-success show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">✅ Inventory updated successfully.</div></div>';
        echo "<script>setTimeout(() => window.location.href='inventory_list.php', 2500);</script>";
    } else {
        $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ Update failed.</div></div>';
    }
}
?>

<div class="container mt-4">
    <h4 class="mb-4">✏️ Edit Inventory</h4>
    <form method="POST">
        <input type="hidden" name="inventory_id" value="<?= $inventory_id ?>">
        <div class="row g-4">
            <div class="col-md-3">
                <label class="form-label">Quantity</label>
                <input type="number" step="0.01" name="qty_change" class="form-control" value="<?= $inventory['qty_change'] ?>" required />
            </div>
            <div class="col-md-3">
                <label class="form-label">Unit</label>
                <select name="unit" class="form-select" required>
                    <option value="Litre" <?= $inventory['unit'] === 'Litre' ? 'selected' : '' ?>>Litre</option>
                    <option value="Bottle" <?= $inventory['unit'] === 'Bottle' ? 'selected' : '' ?>>Bottle</option>
                    <option value="Kg" <?= $inventory['unit'] === 'Kg' ? 'selected' : '' ?>>Kg</option>
                    <option value="Piece" <?= $inventory['unit'] === 'Piece' ? 'selected' : '' ?>>Piece</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Action Type</label>
                <select name="action" class="form-select" required>
                    <option value="add" <?= $inventory['action'] === 'add' ? 'selected' : '' ?>>Add</option>
                    <option value="consume" <?= $inventory['action'] === 'consume' ? 'selected' : '' ?>>Consume</option>
                    <option value="adjust" <?= $inventory['action'] === 'adjust' ? 'selected' : '' ?>>Adjust</option>
                    <option value="dispatch" <?= $inventory['action'] === 'dispatch' ? 'selected' : '' ?>>Dispatch</option>
                    <option value="return" <?= $inventory['action'] === 'return' ? 'selected' : '' ?>>Return</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Source</label>
                <input type="text" name="source" class="form-control" value="<?= htmlspecialchars($inventory['source']) ?>" required />
            </div>
            <div class="col-md-6">
                <label class="form-label">Note</label>
                <input type="text" name="note" class="form-control" value="<?= htmlspecialchars($inventory['note']) ?>" />
            </div>
            <div class="col-md-2">
                <label class="form-label">Cost Price</label>
                <input type="number" step="0.01" name="cost_price" class="form-control" value="<?= $inventory['cost_price'] ?>" />
            </div>
            <div class="col-md-2">
                <label class="form-label">Wholesale Price</label>
                <input type="number" step="0.01" name="wholesale_price" class="form-control" value="<?= $inventory['wholesale_price'] ?>" />
            </div>
            <div class="col-md-2">
                <label class="form-label">Retail Price</label>
                <input type="number" step="0.01" name="retail_price" class="form-control" value="<?= $inventory['retail_price'] ?>" />
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" name="update" class="btn btn-primary px-4">💾 Update Inventory</button>
        </div>
    </form>
</div>

<?= $toast ?>
<?php include('include/footer.php'); ?>
