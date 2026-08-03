<?php
require_once 'include/require_permission.php';
requirePermission('INVENTORY', 'add');
include('include/require_login.php');
?>
<?php include('include/header.php'); ?>
<?php
$toast = "";

// Fetch products for dropdown
$product_stmt = $mysqli->prepare("SELECT product_id, title FROM products WHERE status='active' ORDER BY title ASC");
$product_stmt->execute();
$product_result = $product_stmt->get_result();

$type = $_POST['type'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit'])) {
    $type = $_POST['type'];
    $item_id = $_POST['title'];
    $quantity = $_POST['quantity'];
    $unit = $_POST['unit'];
    $source = $_POST['source'];
    $note = $_POST['note'];
    $wholesale_price = !empty($_POST['wholesale_price']) ? $_POST['wholesale_price'] : null;
    $retail_price = !empty($_POST['retail_price']) ? $_POST['retail_price'] : null;
    $cost_price = !empty($_POST['cost_price']) ? $_POST['cost_price'] : null;
    $action = 'add'; // default action

$stmt = $mysqli->prepare("INSERT INTO inventory (item_type, unit, item_id, qty_change, action, note, created_at, wholesale_price, retail_price, cost_price) VALUES (?, ?, ?, ?, ?, ?, NOW(), ?, ?, ?)");
$stmt->bind_param("ssisssddd", $type, $unit, $item_id, $quantity, $action, $note, $wholesale_price, $retail_price, $cost_price);

    if ($stmt->execute()) {
        $toast = '<div class="toast toast-sa-success show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">✅ Inventory added successfully.</div></div>';
        echo "<script>setTimeout(() => window.location.href='inventory_list.php', 3000);</script>";
    } else {
        $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle z-3" role="alert"><div class="toast-body fs-6">❌ Failed to add inventory.</div></div>';
    }
}
?>

<div class="container mt-4 ">
    <h4 class="mb-4">➕ Add Inventory Entry</h4>
    <form method="POST">
        <div class="row mb-3">
            <div class="col-md-4">
                <label class="form-label">Type</label>
                <select name="type" class="form-select" onchange="this.form.submit()" required>
                    <option value="">-- Select Type --</option>
                    <option value="product" <?= $type === 'product' ? 'selected' : '' ?>>Product</option>
                    <option value="raw_material" <?= $type === 'raw_material' ? 'selected' : '' ?>>Raw Material</option>
                </select>
            </div>
        </div>

        <?php if ($type): ?>
        <div class="row  g-3 p-5 bg-white">
            <div class="col-md-6">
                <label class="form-label">Select <?= $type === 'product' ? 'Product' : 'Material' ?></label>
                <select name="title" class="bg-secondary form-select-sm selectpicker col-12" data-live-search="true" required>
                    <option value="">-- Select <?= $type === 'product' ? 'Product' : 'Material' ?> --</option>
                    <?php while ($row = $product_result->fetch_assoc()): ?>
                        <option value="<?= htmlspecialchars($row['product_id']) ?>"><?= htmlspecialchars($row['title']) ?></option>
                    <?php endwhile; ?>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Quantity</label>
                <input type="number" step="1" name="quantity" class="form-control" required />
            </div>
            <div class="col-md-3">
                <label class="form-label">Unit</label>
                <select name="unit" class="form-select" required>
                    <option value="Litre">Litre</option>
                    <option value="Bottle">Bottle</option>
                    <option value="Kg">Kg</option>
                    <option value="Piece">Piece</option>
                </select>
            </div>

            <?php if ($type === 'product'): ?>
            <div class="col-md-4">
                <label class="form-label">Wholesale Price (Optional)</label>
                <input type="number" step="0.01" name="wholesale_price" class="form-control" />
            </div>
            <div class="col-md-4">
                <label class="form-label">Retail Price (Optional)</label>
                <input type="number" step="0.01" name="retail_price" class="form-control" />
            </div>
            <div class="col-md-4">
                <label class="form-label">Cost Price (Optional)</label>
                <input type="number" step="0.01" name="cost_price" class="form-control" />
            </div>
            <?php endif; ?>

            <div class="col-md-6">
                <label class="form-label">Source</label>
                <input type="text" name="source" class="form-control"/>
            </div>
            <div class="col-md-6">
                <label class="form-label">Note (Optional)</label>
                <input type="text" name="note" class="form-control" />
            </div>
        </div>
        <div class="mt-4">
            <button type="submit" name="submit" class="btn btn-success px-4 py-2">➕ Add Inventory</button>
        </div>
        <?php endif; ?>
    </form>
</div>

<?= $toast ?>

<!-- Include Bootstrap-select for live search -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta2/dist/css/bootstrap-select.min.css">
<script src="https://cdn.jsdelivr.net/npm/bootstrap-select@1.14.0-beta2/dist/js/bootstrap-select.min.js"></script>
<script>
    document.addEventListener("DOMContentLoaded", function() {
        $('.selectpicker').selectpicker();
    });
</script>

<?php include('include/footer.php'); ?>