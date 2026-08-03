<?php
require_once 'include/require_permission.php';
requirePermission('PRODUCTS', 'delete');
include('include/require_login.php');
?>
<?php include('include/header.php');

$toast = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['product_id'])) {
    $product_id = intval($_POST['product_id']);

    // First, check if the product exists
    $stmt = $mysqli->prepare("SELECT * FROM products WHERE product_id = ?");
    $stmt->bind_param("i", $product_id);
    $stmt->execute();
    $result = $stmt->get_result();

    if ($result->num_rows === 0) {
        $toast = '<div class="toast toast-sa-warning show position-fixed top-50 start-50 translate-middle" role="alert"><div class="toast-body fs-6">⚠️ Product not found or already deleted.</div></div>';
    } else {
        // Proceed to delete the product
        $stmt = $mysqli->prepare("DELETE FROM products WHERE product_id = ?");
        $stmt->bind_param("i", $product_id);

        if ($stmt->execute()) {
            $toast = '<div class="toast toast-sa-success show position-fixed top-50 start-50 translate-middle" role="alert"><div class="toast-body fs-6">✅ Product deleted successfully.</div></div>';
            echo "<script>setTimeout(() => window.location.href='product_list.php', 3000);</script>";
        } else {
            $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle" role="alert"><div class="toast-body fs-6">❌ Failed to delete product. Try again.</div></div>';
        }
    }
} else {
    $toast = '<div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle" role="alert"><div class="toast-body fs-6">❌ Invalid request. No product selected.</div></div>';
}

?>

<div class="container mt-5 text-center">
    <h5>Delete Product</h5>
    <p>If you're not redirected, <a href="product_list.php">click here</a>.</p>
</div>

<?= $toast ?>

<?php include('include/footer.php'); ?>
