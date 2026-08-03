<?php
require_once 'include/require_permission.php';
requirePermission('PRODUCTS', 'view');
include('include/require_login.php');
?>
<?php include('include/header.php'); ?>
<?php
if (!isset($_GET['product_id'])) {
    echo "<div class='alert alert-danger m-4'>Invalid Request. Please select a product to view.</div>";
    include('include/footer.php');
    exit;
}

$product_id = intval($_GET['product_id']);
$stmt = $mysqli->prepare("SELECT * FROM products WHERE product_id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$result = $stmt->get_result();
$product = $result->fetch_assoc();

if (!$product) {
    echo "<div class='alert alert-warning m-4'>Product not found.</div>";
    include('include/footer.php');
    exit;
}
?>

<div class="container mt-5">
    <div class="card shadow d-flex flex-row" style="border-radius: 12px; overflow: hidden;">
        <!-- Left Section -->
        <div class="bg-light text-center p-4" style="width: 30%; min-width: 220px;">
            <img src="<?= $product['image_url'] ? htmlspecialchars($product['image_url']) : 'images/default-product.png' ?>" class="img-fluid rounded mb-3" alt="Product Image">
            <h5 class="fw-bold mb-2"><?= htmlspecialchars($product['title']) ?></h5>
            <div class="text-muted mb-3 small">#<?= htmlspecialchars($product['product_id']) ?></div>
            <a href="edit_product.php?product_id=<?= $product['product_id'] ?>" class="btn btn-outline-primary btn-sm">✏️ Edit Product</a>
        </div>

        <!-- Right Section -->
        <div class="flex-grow-1 p-4 bg-white">
            <h5 class="fw-semibold">Product Information</h5>
            <hr>
            <p><strong>Description:</strong><br><?= nl2br(htmlspecialchars($product['description'])) ?: '—' ?></p>
            <p><strong>Tags:</strong><br><?= htmlspecialchars($product['tags']) ?: '—' ?></p>

            <div class="row">
                <div class="col-md-4">
                    <p><strong>Cost Price:</strong><br>₹<?= htmlspecialchars($product['cost_price']) ?: '0.00' ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Wholesale Price:</strong><br>₹<?= htmlspecialchars($product['wholesale_price']) ?: '0.00' ?></p>
                </div>
                <div class="col-md-4">
                    <p><strong>Retail Price:</strong><br>₹<?= htmlspecialchars($product['retail_price']) ?: '0.00' ?></p>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <p><strong>Unit:</strong><br><?= htmlspecialchars($product['unit']) ?: '—' ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Status:</strong><br><span class="badge bg-<?= $product['status'] === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars(ucfirst($product['status'])) ?></span></p>
                </div>
            </div>

            <p class="mt-3"><strong>Created At:</strong><br><?= date('d M Y, h:i A', strtotime($product['created_at'])) ?></p>
        </div>
    </div>
</div>

<?php include('include/footer.php'); ?>
