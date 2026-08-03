<?php
require_once 'include/require_permission.php';
requirePermission('PRODUCTS', 'add');
include('include/require_login.php');
?>
<?php include('include/header.php'); ?>

<?php
$toast = "";

// ===============================
// Ensure sub distributor price column exists
// ===============================
function ensureProductSubDistributorPriceColumn($mysqli) {
    $check = $mysqli->query("SHOW COLUMNS FROM products LIKE 'sub_distributor_price'");

    if (!$check || $check->num_rows === 0) {
        // Try to add after wholesale_price. If it fails, add normally.
        $alter = @$mysqli->query("ALTER TABLE products ADD COLUMN sub_distributor_price DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER wholesale_price");

        if (!$alter) {
            @$mysqli->query("ALTER TABLE products ADD COLUMN sub_distributor_price DECIMAL(10,2) NOT NULL DEFAULT 0.00");
        }
    }
}

ensureProductSubDistributorPriceColumn($mysqli);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $product_owner         = trim($_POST['product_owner'] ?? 'SATHEE');
    $title                 = trim($_POST['title'] ?? '');
    $description           = trim($_POST['description'] ?? '');
    $tags                  = trim($_POST['tags'] ?? '');
    $cost_price            = floatval($_POST['cost_price'] ?? 0);
    $wholesale             = floatval($_POST['wholesale_price'] ?? 0);
    $sub_distributor_price = floatval($_POST['sub_distributor_price'] ?? 0);
    $retail                = floatval($_POST['retail_price'] ?? 0);
    $unit                  = trim($_POST['unit'] ?? '');
    $status                = trim($_POST['status'] ?? 'active');

    // Allowed values
    $allowedOwners = ['SATHEE', 'CMD'];
    $allowedStatus = ['active', 'inactive'];

    if (!in_array($product_owner, $allowedOwners)) {
        $product_owner = 'SATHEE';
    }

    if (!in_array($status, $allowedStatus)) {
        $status = 'active';
    }

    if ($title === '' || $unit === '') {
        $toast = '
        <div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle" role="alert" style="z-index:9999;">
            <div class="toast-body fs-6">❌ Product title and unit are required.</div>
        </div>';
    } else {

        // Image upload
        $image_url = '';

        if (isset($_FILES['image']) && $_FILES['image']['error'] == 0) {

            $uploadDir = "uploads/products/";

            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }

            $fileName = $_FILES['image']['name'];
            $fileTmp  = $_FILES['image']['tmp_name'];
            $fileExt  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

            $allowedExt = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

            if (in_array($fileExt, $allowedExt)) {
                $imgName = time() . "_" . rand(1000, 9999) . "." . $fileExt;
                $target = $uploadDir . $imgName;

                if (move_uploaded_file($fileTmp, $target)) {
                    $image_url = $target;
                }
            }
        }

        // Insert into DB
        $stmt = $mysqli->prepare("
            INSERT INTO products 
            (
                product_owner,
                title,
                description,
                image_url,
                tags,
                cost_price,
                wholesale_price,
                sub_distributor_price,
                retail_price,
                unit,
                status
            ) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");

        $stmt->bind_param(
            "sssssddddss",
            $product_owner,
            $title,
            $description,
            $image_url,
            $tags,
            $cost_price,
            $wholesale,
            $sub_distributor_price,
            $retail,
            $unit,
            $status
        );

        if ($stmt->execute()) {
            $toast = '
            <div class="toast toast-sa-success show position-fixed top-50 start-50 translate-middle" role="alert" style="z-index:9999;">
                <div class="toast-body fs-6">✅ Product added successfully.</div>
            </div>';

            echo "<script>
                setTimeout(function() {
                    window.location.href = 'product_list.php';
                }, 2000);
            </script>";
        } else {
            $toast = '
            <div class="toast toast-sa-danger show position-fixed top-50 start-50 translate-middle" role="alert" style="z-index:9999;">
                <div class="toast-body fs-6">❌ Failed to add product. Please try again.</div>
            </div>';
        }
    }
}
?>

<style>
    .page-header-box {
        background: #fff;
        border-radius: 14px;
        padding: 18px 20px;
        margin-bottom: 18px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .product-form-card {
        background: #fff;
        border-radius: 14px;
        padding: 22px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.05);
    }

    .section-title {
        font-size: 15px;
        font-weight: 700;
        color: #333;
        margin-bottom: 14px;
        padding-bottom: 8px;
        border-bottom: 1px solid #eee;
    }

    .form-label {
        font-weight: 600;
        font-size: 14px;
    }

    .form-control,
    .form-select {
        border-radius: 8px;
        min-height: 42px;
    }

    .action-bar {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
        margin-top: 18px;
    }

    @media (max-width: 768px) {
        .page-header-box {
            padding: 15px;
        }

        .page-header-box h4 {
            font-size: 18px;
        }

        .product-form-card {
            padding: 16px;
        }

        .action-bar .btn {
            width: 100%;
        }
    }
</style>

<div class="container-fluid mt-4">

    <div class="page-header-box">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h4 class="mb-1">Add New Product</h4>
                <small class="text-muted">Create product with company owner, pricing and image</small>
            </div>

            <div class="mt-3 mt-md-0">
                <a href="product_list.php" class="btn btn-secondary">
                    ← Back to Product List
                </a>
            </div>
        </div>
    </div>

    <div class="product-form-card">

        <form method="POST" enctype="multipart/form-data">

            <div class="section-title">Basic Product Details</div>

            <div class="row">

                <div class="col-md-4 mb-3">
                    <label class="form-label">Product Owner <span class="text-danger">*</span></label>
                    <select name="product_owner" class="form-select" required>
                        <option value="SATHEE" <?= (isset($_POST['product_owner']) && $_POST['product_owner'] === 'SATHEE') ? 'selected' : '' ?>>
                            SATHEE
                        </option>
                        <option value="CMD" <?= (isset($_POST['product_owner']) && $_POST['product_owner'] === 'CMD') ? 'selected' : '' ?>>
                            CMD
                        </option>
                    </select>
                </div>

                <div class="col-md-8 mb-3">
                    <label class="form-label">Product Title <span class="text-danger">*</span></label>
                    <input 
                        type="text" 
                        name="title" 
                        class="form-control" 
                        placeholder="Enter product title"
                        value="<?= htmlspecialchars($_POST['title'] ?? '') ?>"
                        required
                    >
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Tags</label>
                    <input 
                        type="text" 
                        name="tags" 
                        class="form-control" 
                        placeholder="Example: cleaner, bottle, liquid"
                        value="<?= htmlspecialchars($_POST['tags'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-12 mb-3">
                    <label class="form-label">Description</label>
                    <textarea 
                        name="description" 
                        class="form-control" 
                        rows="4"
                        placeholder="Enter product description"
                    ><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                </div>

            </div>

            <div class="section-title mt-3">Pricing Details</div>

            <div class="row">

                <div class="col-md-3 mb-3">
                    <label class="form-label">Cost Price</label>
                    <input 
                        type="number" 
                        step="0.01" 
                        name="cost_price" 
                        class="form-control"
                        placeholder="0.00"
                        value="<?= htmlspecialchars($_POST['cost_price'] ?? '') ?>"
                    >
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Wholesale Price</label>
                    <input 
                        type="number" 
                        step="0.01" 
                        name="wholesale_price" 
                        class="form-control"
                        placeholder="0.00"
                        value="<?= htmlspecialchars($_POST['wholesale_price'] ?? '') ?>"
                    >
                    <small class="text-muted">Main distributor cost</small>
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Sub Distributor Price</label>
                    <input 
                        type="number" 
                        step="0.01" 
                        name="sub_distributor_price" 
                        class="form-control"
                        placeholder="0.00"
                        value="<?= htmlspecialchars($_POST['sub_distributor_price'] ?? '') ?>"
                    >
                    <small class="text-muted">Price charged to sub-distributor</small>
                </div>

                <div class="col-md-3 mb-3">
                    <label class="form-label">Retail Price</label>
                    <input 
                        type="number" 
                        step="0.01" 
                        name="retail_price" 
                        class="form-control"
                        placeholder="0.00"
                        value="<?= htmlspecialchars($_POST['retail_price'] ?? '') ?>"
                    >
                </div>

            </div>

            <div class="section-title mt-3">Inventory & Status</div>

            <div class="row">

                <div class="col-md-4 mb-3">
                    <label class="form-label">Unit <span class="text-danger">*</span></label>
                    <select name="unit" class="form-select" required>
                        <option value="">-- Select Unit --</option>
                        <option value="Pcs" <?= (($_POST['unit'] ?? '') === 'Pcs') ? 'selected' : '' ?>>Pcs</option>
                        <option value="Litre" <?= (($_POST['unit'] ?? '') === 'Litre') ? 'selected' : '' ?>>Litre</option>
                        <option value="Bottle" <?= (($_POST['unit'] ?? '') === 'Bottle') ? 'selected' : '' ?>>Bottle</option>
                        <option value="Can" <?= (($_POST['unit'] ?? '') === 'Can') ? 'selected' : '' ?>>Can</option>
                        <option value="Kg" <?= (($_POST['unit'] ?? '') === 'Kg') ? 'selected' : '' ?>>Kg</option>
                        <option value="Gram" <?= (($_POST['unit'] ?? '') === 'Gram') ? 'selected' : '' ?>>Gram</option>
                        <option value="Box" <?= (($_POST['unit'] ?? '') === 'Box') ? 'selected' : '' ?>>Box</option>
                    </select>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?= (($_POST['status'] ?? 'active') === 'active') ? 'selected' : '' ?>>
                            Active
                        </option>
                        <option value="inactive" <?= (($_POST['status'] ?? '') === 'inactive') ? 'selected' : '' ?>>
                            Inactive
                        </option>
                    </select>
                </div>

                <div class="col-md-4 mb-3">
                    <label class="form-label">Upload Image</label>
                    <input 
                        type="file" 
                        name="image" 
                        accept="image/*" 
                        class="form-control"
                    >
                    <small class="text-muted">Allowed: JPG, PNG, WEBP, GIF</small>
                </div>

            </div>

            <div class="action-bar">
                <button type="submit" class="btn btn-primary">
                    ➕ Add Product
                </button>

                <a href="product_list.php" class="btn btn-outline-secondary">
                    Cancel
                </a>
            </div>

        </form>

    </div>

</div>

<?= $toast ?>

<?php include('include/footer.php'); ?>
