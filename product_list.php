<?php
require_once 'include/require_permission.php';
requirePermission('PRODUCTS', 'view');
include('include/require_login.php');
?>
<?php include('include/header.php'); ?>

<?php
// ===============================
// Product List with Owner Filter
// ===============================

$search = isset($_GET['search']) ? trim($_GET['search']) : '';
$owner  = isset($_GET['owner']) ? trim($_GET['owner']) : '';

$allowedOwners = ['SATHEE', 'CMD'];

$where = [];
$params = [];
$types = "";

// Search filter
if ($search !== '') {
    $where[] = "(product_id LIKE ? OR title LIKE ? OR tags LIKE ?)";
    $search_like = "%{$search}%";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= "sss";
}

// Product owner filter
if ($owner !== '' && in_array($owner, $allowedOwners, true)) {
    $where[] = "product_owner = ?";
    $params[] = $owner;
    $types .= "s";
}

$sql = "SELECT * FROM products";

if (!empty($where)) {
    $sql .= " WHERE " . implode(" AND ", $where);
}

$sql .= " ORDER BY product_id DESC";

$stmt = $mysqli->prepare($sql);

if (!empty($params)) {
    $stmt->bind_param($types, ...$params);
}

$stmt->execute();
$result = $stmt->get_result();
?>

<style>
    .product-img {
        width: 45px;
        height: 45px;
        object-fit: cover;
        border-radius: 8px;
        border: 1px solid #ddd;
        background: #f8f9fa;
    }

    .page-title-box {
        background: #fff;
        border-radius: 12px;
        padding: 18px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        margin-bottom: 18px;
    }

    .filter-box {
        background: #fff;
        border-radius: 12px;
        padding: 15px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
        margin-bottom: 18px;
    }

    .table-card {
        background: #fff;
        border-radius: 12px;
        padding: 15px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.05);
    }

    .table th {
        white-space: nowrap;
        font-size: 14px;
    }

    .table td {
        vertical-align: middle;
        font-size: 14px;
    }

    .action-btns {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }

    .owner-badge {
        font-size: 12px;
        padding: 6px 10px;
        border-radius: 20px;
    }

    .price-box {
        white-space: nowrap;
        font-weight: 600;
    }

    .sub-price {
        color: #198754;
        font-weight: 700;
    }

    @media (max-width: 768px) {
        .page-title-box {
            padding: 14px;
        }

        .page-title-box h4 {
            font-size: 18px;
            margin-bottom: 10px;
        }

        .filter-box .form-control,
        .filter-box .form-select,
        .filter-box .btn {
            margin-bottom: 8px;
        }

        .action-btns {
            flex-direction: column;
        }

        .action-btns .btn {
            width: 100%;
        }

        .table td,
        .table th {
            font-size: 13px;
        }
    }
</style>

<div class="container-fluid mt-4">

    <div class="page-title-box">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h4 class="mb-1">Product List</h4>
                <small class="text-muted">Manage all products company-wise</small>
            </div>

            <div class="mt-3 mt-md-0">
                <a href="add_product.php" class="btn btn-primary">
                    + Add Product
                </a>
            </div>
        </div>
    </div>

    <div class="filter-box">
        <form method="GET" class="row g-2 align-items-end">

            <div class="col-md-5">
                <label class="form-label">Search Product</label>
                <input 
                    type="text" 
                    name="search" 
                    class="form-control" 
                    placeholder="Search by ID, title or tags"
                    value="<?= htmlspecialchars($search) ?>"
                >
            </div>

            <div class="col-md-3">
                <label class="form-label">Product Owner</label>
                <select name="owner" class="form-select">
                    <option value="">All Owners</option>
                    <option value="SATHEE" <?= ($owner === 'SATHEE') ? 'selected' : '' ?>>SATHEE</option>
                    <option value="CMD" <?= ($owner === 'CMD') ? 'selected' : '' ?>>CMD</option>
                </select>
            </div>

            <div class="col-md-4">
                <button type="submit" class="btn btn-primary">
                    Search
                </button>

                <a href="product_list.php" class="btn btn-secondary">
                    Reset
                </a>
            </div>

        </form>
    </div>

    <div class="table-card">
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Owner</th>
                        <th>Image</th>
                        <th>Title</th>
                        <th>Retail</th>
                        <th>Wholesale</th>
                        <th>Sub Dist. Price</th>
                        <th>Unit</th>
                        <th>Status</th>
                        <th width="190">Actions</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if ($result && $result->num_rows > 0): ?>
                        <?php while ($row = $result->fetch_assoc()): ?>

                            <?php
                                $image = !empty($row['image_url']) ? $row['image_url'] : 'assets/img/no-image.png';

                                $productOwner = $row['product_owner'] ?? 'SATHEE';

                                if ($productOwner === 'CMD') {
                                    $ownerBadgeClass = 'bg-dark';
                                } else {
                                    $ownerBadgeClass = 'bg-primary';
                                }

                                $statusClass = (($row['status'] ?? '') === 'active') ? 'success' : 'secondary';

                                $retailPrice = (float)($row['retail_price'] ?? 0);
                                $wholesalePrice = (float)($row['wholesale_price'] ?? 0);
                                $subDistributorPrice = (float)($row['sub_distributor_price'] ?? 0);
                            ?>

                            <tr>
                                <td>
                                    <?= (int)$row['product_id'] ?>
                                </td>

                                <td>
                                    <span class="badge <?= $ownerBadgeClass ?> owner-badge">
                                        <?= htmlspecialchars($productOwner) ?>
                                    </span>
                                </td>

                                <td>
                                    <img 
                                        src="<?= htmlspecialchars($image) ?>" 
                                        class="product-img"
                                        alt="Product"
                                        onerror="this.src='images/no-image.png';"
                                    >
                                </td>

                                <td>
                                    <strong><?= htmlspecialchars($row['title'] ?? '') ?></strong>
                                    <?php if (!empty($row['tags'])): ?>
                                        <br>
                                        <small class="text-muted">
                                            <?= htmlspecialchars(substr($row['tags'], 0, 60)) ?>
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class="price-box">
                                        ₹<?= number_format($retailPrice, 2) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="price-box">
                                        ₹<?= number_format($wholesalePrice, 2) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="price-box sub-price">
                                        ₹<?= number_format($subDistributorPrice, 2) ?>
                                    </span>

                                    <?php if ($subDistributorPrice <= 0): ?>
                                        <br>
                                        <small class="text-danger">
                                            Not Set
                                        </small>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['unit'] ?? '') ?>
                                </td>

                                <td>
                                    <span class="badge bg-<?= $statusClass ?>">
                                        <?= ucfirst(htmlspecialchars($row['status'] ?? 'inactive')) ?>
                                    </span>
                                </td>

                                <td>
                                    <div class="action-btns">

                                        <a 
                                            href="view_product.php?product_id=<?= (int)$row['product_id'] ?>" 
                                            class="btn btn-sm btn-info text-white"
                                        >
                                            View
                                        </a>

                                        <form action="edit_product.php" method="POST" class="m-0">
                                            <input 
                                                type="hidden" 
                                                name="product_id" 
                                                value="<?= (int)$row['product_id'] ?>"
                                            >
                                            <button type="submit" class="btn btn-sm btn-warning">
                                                Edit
                                            </button>
                                        </form>

                                        <form 
                                            action="delete_product.php" 
                                            method="POST" 
                                            class="m-0"
                                            onsubmit="return confirm('Are you sure you want to delete this product?');"
                                        >
                                            <input 
                                                type="hidden" 
                                                name="product_id" 
                                                value="<?= (int)$row['product_id'] ?>"
                                            >
                                            <button type="submit" class="btn btn-sm btn-danger">
                                                Delete
                                            </button>
                                        </form>

                                    </div>
                                </td>
                            </tr>

                        <?php endwhile; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="10" class="text-center text-muted py-4">
                                No products found.
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>

            </table>
        </div>
    </div>

</div>

<?php include('include/footer.php'); ?>