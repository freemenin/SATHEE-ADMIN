<?php
require_once 'include/require_permission.php';
requirePermission('BATCHES', 'view');
include('include/require_login.php');
require_once __DIR__ . '/include/db.php';

/*
|--------------------------------------------------------------------------
| Helper Functions
|--------------------------------------------------------------------------
*/
function companyName($code) {
    $code = strtoupper((string)$code);

    if ($code == 'SATHEE') return 'Sathee Enterprise';
    if ($code == 'CMD') return 'CMD Enterprise';

    return '-';
}

function selected($a, $b) {
    return ((string)$a === (string)$b) ? 'selected' : '';
}

function inputValue($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function buildQueryString($extra = []) {
    $query = $_GET;

    foreach ($extra as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    return http_build_query($query);
}

function settlementStatusText($settlement_amount, $pending_settlement, $settled_settlement) {
    if ($settlement_amount <= 0) {
        return 'No Settlement';
    } elseif ($pending_settlement > 0 && $settled_settlement > 0) {
        return 'Part Pending';
    } elseif ($pending_settlement > 0) {
        return 'Pending';
    } else {
        return 'Settled';
    }
}

/*
|--------------------------------------------------------------------------
| Filter Values
|--------------------------------------------------------------------------
*/
$product_id  = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$batch_owner = isset($_GET['batch_owner']) ? trim($_GET['batch_owner']) : '';
$date_filter = isset($_GET['date_filter']) ? trim($_GET['date_filter']) : '';
$from_date   = isset($_GET['from_date']) ? trim($_GET['from_date']) : '';
$to_date     = isset($_GET['to_date']) ? trim($_GET['to_date']) : '';
$export      = isset($_GET['export']) ? trim($_GET['export']) : '';

/*
|--------------------------------------------------------------------------
| Pagination
|--------------------------------------------------------------------------
*/
$limit = 20;
$page  = isset($_GET['page']) ? (int)$_GET['page'] : 1;

if ($page <= 0) {
    $page = 1;
}

$offset = ($page - 1) * $limit;

/*
|--------------------------------------------------------------------------
| Filter SQL Conditions
|--------------------------------------------------------------------------
*/
$where = [];
$params = [];
$types = '';

if ($product_id > 0) {
    $where[] = "b.product_id = ?";
    $params[] = $product_id;
    $types .= "i";
}

if ($batch_owner !== '') {
    $where[] = "b.batch_owner = ?";
    $params[] = $batch_owner;
    $types .= "s";
}

$today = date('Y-m-d');

if ($date_filter === 'today') {

    $where[] = "b.production_date = ?";
    $params[] = $today;
    $types .= "s";

} elseif ($date_filter === 'yesterday') {

    $yesterday = date('Y-m-d', strtotime('-1 day'));
    $where[] = "b.production_date = ?";
    $params[] = $yesterday;
    $types .= "s";

} elseif ($date_filter === 'last_7_days') {

    $last7 = date('Y-m-d', strtotime('-6 days'));
    $where[] = "b.production_date BETWEEN ? AND ?";
    $params[] = $last7;
    $params[] = $today;
    $types .= "ss";

} elseif ($date_filter === 'current_month') {

    $monthStart = date('Y-m-01');
    $monthEnd   = date('Y-m-t');
    $where[] = "b.production_date BETWEEN ? AND ?";
    $params[] = $monthStart;
    $params[] = $monthEnd;
    $types .= "ss";

} elseif ($date_filter === 'last_month') {

    $lastMonthStart = date('Y-m-01', strtotime('first day of last month'));
    $lastMonthEnd   = date('Y-m-t', strtotime('last day of last month'));
    $where[] = "b.production_date BETWEEN ? AND ?";
    $params[] = $lastMonthStart;
    $params[] = $lastMonthEnd;
    $types .= "ss";

} elseif ($date_filter === 'custom') {

    if ($from_date !== '' && $to_date !== '') {
        $where[] = "b.production_date BETWEEN ? AND ?";
        $params[] = $from_date;
        $params[] = $to_date;
        $types .= "ss";
    } elseif ($from_date !== '') {
        $where[] = "b.production_date >= ?";
        $params[] = $from_date;
        $types .= "s";
    } elseif ($to_date !== '') {
        $where[] = "b.production_date <= ?";
        $params[] = $to_date;
        $types .= "s";
    }
}

$whereSql = '';

if (!empty($where)) {
    $whereSql = " WHERE " . implode(" AND ", $where);
}

/*
|--------------------------------------------------------------------------
| Count Query For Pagination
|--------------------------------------------------------------------------
*/
$countSql = "
    SELECT COUNT(DISTINCT b.batch_id) AS total_records
    FROM batches b
    LEFT JOIN products p ON b.product_id = p.product_id
    LEFT JOIN users u ON b.created_by = u.user_id
    LEFT JOIN batch_raw_materials brm ON b.batch_id = brm.batch_id
    $whereSql
";

$countStmt = $mysqli->prepare($countSql);

if (!$countStmt) {
    die("Count query prepare failed: " . $mysqli->error);
}

if (!empty($params)) {
    $countStmt->bind_param($types, ...$params);
}

$countStmt->execute();
$countResult = $countStmt->get_result()->fetch_assoc();
$totalRecords = (int)($countResult['total_records'] ?? 0);
$countStmt->close();

$totalPages = $totalRecords > 0 ? (int)ceil($totalRecords / $limit) : 1;

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $limit;
}

/*
|--------------------------------------------------------------------------
| Main Query Base
|--------------------------------------------------------------------------
*/
$baseSql = "
    SELECT 
        b.batch_id,
        b.batch_code,
        b.product_id,
        b.product_qty,
        b.production_date,
        b.batch_owner,
        b.created_at,

        p.title AS product_name,
        u.name AS created_by_name,

        COALESCE(SUM(brm.amount), 0) AS total_material_cost,

        COALESCE(SUM(
            CASE 
                WHEN brm.settlement_required = 1 
                THEN brm.amount 
                ELSE 0 
            END
        ), 0) AS settlement_amount,

        COALESCE(SUM(
            CASE 
                WHEN brm.settlement_required = 1 
                AND brm.settlement_status = 'pending'
                THEN brm.amount 
                ELSE 0 
            END
        ), 0) AS pending_settlement,

        COALESCE(SUM(
            CASE 
                WHEN brm.settlement_required = 1 
                AND brm.settlement_status = 'settled'
                THEN brm.amount 
                ELSE 0 
            END
        ), 0) AS settled_settlement

    FROM batches b
    LEFT JOIN products p ON b.product_id = p.product_id
    LEFT JOIN users u ON b.created_by = u.user_id
    LEFT JOIN batch_raw_materials brm ON b.batch_id = brm.batch_id
    $whereSql
    GROUP BY 
        b.batch_id,
        b.batch_code,
        b.product_id,
        b.product_qty,
        b.production_date,
        b.batch_owner,
        b.created_at,
        p.title,
        u.name
";

/*
|--------------------------------------------------------------------------
| Excel Export
|--------------------------------------------------------------------------
| This exports same filtered data. Pagination is removed for export.
|--------------------------------------------------------------------------
*/
if ($export === 'excel') {

    $exportSql = $baseSql . " ORDER BY b.batch_id DESC";

    $exportStmt = $mysqli->prepare($exportSql);

    if (!$exportStmt) {
        die("Export query prepare failed: " . $mysqli->error);
    }

    if (!empty($params)) {
        $exportStmt->bind_param($types, ...$params);
    }

    $exportStmt->execute();
    $exportResult = $exportStmt->get_result();

    $filename = "batch_manufacturing_report_" . date('Y-m-d_H-i-s') . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "\xEF\xBB\xBF";

    echo "<table border='1'>";
    echo "<tr>
            <th>#</th>
            <th>Batch Code</th>
            <th>Product</th>
            <th>Batch Owner</th>
            <th>Produced Qty</th>
            <th>Production Date</th>
            <th>Total Material Cost</th>
            <th>Settlement Amount</th>
            <th>Settlement Status</th>
            <th>Created By</th>
            <th>Created At</th>
          </tr>";

    $i = 1;

    while ($row = $exportResult->fetch_assoc()) {

        $settlement_amount = (float)$row['settlement_amount'];
        $pending_settlement = (float)$row['pending_settlement'];
        $settled_settlement = (float)$row['settled_settlement'];

        $settlement_status = settlementStatusText(
            $settlement_amount,
            $pending_settlement,
            $settled_settlement
        );

        echo "<tr>";
        echo "<td>" . $i++ . "</td>";
        echo "<td>" . htmlspecialchars($row['batch_code']) . "</td>";
        echo "<td>" . htmlspecialchars($row['product_name'] ?? '-') . "</td>";
        echo "<td>" . companyName($row['batch_owner']) . "</td>";
        echo "<td>" . number_format((float)$row['product_qty'], 2, '.', '') . "</td>";
        echo "<td>" . (!empty($row['production_date']) ? date('d-m-Y', strtotime($row['production_date'])) : '-') . "</td>";
        echo "<td>" . number_format((float)$row['total_material_cost'], 2, '.', '') . "</td>";
        echo "<td>" . number_format($settlement_amount, 2, '.', '') . "</td>";
        echo "<td>" . $settlement_status . "</td>";
        echo "<td>" . htmlspecialchars($row['created_by_name'] ?? '-') . "</td>";
        echo "<td>" . (!empty($row['created_at']) ? date('d-m-Y h:i A', strtotime($row['created_at'])) : '-') . "</td>";
        echo "</tr>";
    }

    echo "</table>";

    $exportStmt->close();
    exit;
}

/*
|--------------------------------------------------------------------------
| Main Paginated Query
|--------------------------------------------------------------------------
*/
$mainSql = $baseSql . " ORDER BY b.batch_id DESC LIMIT ? OFFSET ?";

$mainParams = $params;
$mainTypes = $types;

$mainParams[] = $limit;
$mainParams[] = $offset;
$mainTypes .= "ii";

$stmt = $mysqli->prepare($mainSql);

if (!$stmt) {
    die("Main query prepare failed: " . $mysqli->error);
}

$stmt->bind_param($mainTypes, ...$mainParams);
$stmt->execute();
$result = $stmt->get_result();

/*
|--------------------------------------------------------------------------
| Product Dropdown
|--------------------------------------------------------------------------
*/
$productList = $mysqli->query("
    SELECT product_id, title 
    FROM products 
    ORDER BY title ASC
");

include('include/header.php');
?>

<style>
    .filter-card {
        border: 1px solid #e5e7eb;
        border-radius: 14px;
    }

    .filter-card label {
        font-size: 13px;
        font-weight: 600;
        color: #374151;
        margin-bottom: 5px;
    }

    .filter-card .form-control,
    .filter-card .form-select {
        font-size: 14px;
        border-radius: 9px;
    }

    .filter-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }

    .table td,
    .table th {
        vertical-align: middle;
        font-size: 14px;
    }

    .action-btns {
        display: flex;
        gap: 5px;
        flex-wrap: wrap;
    }

    .summary-box {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        padding: 12px 16px;
        height: 100%;
    }

    .summary-label {
        font-size: 12px;
        color: #6b7280;
        margin-bottom: 2px;
    }

    .summary-value {
        font-size: 18px;
        font-weight: 700;
        color: #111827;
    }

    .pagination-wrap {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 12px;
        margin-top: 16px;
    }

    .pagination .page-link {
        border-radius: 8px;
        margin: 0 2px;
        color: #374151;
        font-size: 14px;
    }

    .pagination .page-item.active .page-link {
        background: #0d6efd;
        border-color: #0d6efd;
        color: #fff;
    }

    .pagination-info {
        font-size: 13px;
        color: #6b7280;
    }

    @media (max-width: 768px) {
        .top-actions {
            margin-top: 12px;
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            width: 100%;
        }

        .top-actions .btn {
            width: 100%;
        }

        .filter-actions .btn {
            width: 100%;
        }

        .action-btns .btn {
            width: 100%;
            margin-bottom: 4px;
        }

        .pagination-wrap {
            justify-content: center;
            text-align: center;
        }

        .pagination {
            flex-wrap: wrap;
            justify-content: center;
        }
    }
</style>

<div class="container-fluid mt-4">

    <div class="d-flex justify-content-between align-items-start mb-3 flex-wrap">
        <div>
            <h4 class="mb-1">Batch Manufacturing List</h4>
            <small class="text-muted">View manufacturing batches, material cost and settlement status</small>
        </div>

        <div class="top-actions">
            <a href="batch_settlement_report.php" class="btn btn-info btn-sm">
                Settlement Report
            </a>
            <a href="batch_settlement_pay.php" class="btn btn-success btn-sm">
                Mark Settlement Paid
            </a>
            <a href="batch_add.php" class="btn btn-primary btn-sm">
                + Add New Batch
            </a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success">
            Batch saved successfully.
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success">
            Batch updated successfully.
        </div>
    <?php endif; ?>
    <!-- Filter Section -->
    <div class="card shadow-sm mb-3 filter-card">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
            <strong>Filter Batches</strong>

            <a 
                href="?<?= buildQueryString(['export' => 'excel', 'page' => null]) ?>" 
                class="btn btn-success btn-sm"
            >
                Export Filtered Excel
            </a>
        </div>

        <div class="card-body">
            <form method="GET" action="">
                <input type="hidden" name="page" value="1">

                <div class="row g-3">

                    <div class="col-md-3">
                        <label>Product</label>
                        <select name="product_id" class="form-select">
                            <option value="0">All Products</option>

                            <?php if ($productList && $productList->num_rows > 0): ?>
                                <?php while ($product = $productList->fetch_assoc()): ?>
                                    <option value="<?= (int)$product['product_id'] ?>" <?= selected($product_id, $product['product_id']) ?>>
                                        <?= htmlspecialchars($product['title']) ?>
                                    </option>
                                <?php endwhile; ?>
                            <?php endif; ?>

                        </select>
                    </div>

                    <div class="col-md-2">
                        <label>Batch Owner</label>
                        <select name="batch_owner" class="form-select">
                            <option value="">All Owners</option>

                            <option value="SATHEE" <?= selected($batch_owner, 'SATHEE') ?>>
                                Sathee Enterprise
                            </option>

                            <option value="CMD" <?= selected($batch_owner, 'CMD') ?>>
                                CMD Enterprise
                            </option>
                        </select>
                    </div>

                    <div class="col-md-2">
                        <label>Production Date</label>
                        <select name="date_filter" id="date_filter" class="form-select">
                            <option value="">All Dates</option>
                            <option value="today" <?= selected($date_filter, 'today') ?>>Today</option>
                            <option value="yesterday" <?= selected($date_filter, 'yesterday') ?>>Yesterday</option>
                            <option value="last_7_days" <?= selected($date_filter, 'last_7_days') ?>>Last 7 Days</option>
                            <option value="current_month" <?= selected($date_filter, 'current_month') ?>>Current Month</option>
                            <option value="last_month" <?= selected($date_filter, 'last_month') ?>>Last Month</option>
                            <option value="custom" <?= selected($date_filter, 'custom') ?>>Custom Date</option>
                        </select>
                    </div>

                    <div class="col-md-2 custom-date-box">
                        <label>From Date</label>
                        <input 
                            type="date" 
                            name="from_date" 
                            class="form-control" 
                            value="<?= inputValue($from_date) ?>"
                        >
                    </div>

                    <div class="col-md-2 custom-date-box">
                        <label>To Date</label>
                        <input 
                            type="date" 
                            name="to_date" 
                            class="form-control" 
                            value="<?= inputValue($to_date) ?>"
                        >
                    </div>

                    <div class="col-md-1 d-flex align-items-end">
                        <div class="filter-actions">
                            <button type="submit" class="btn btn-primary btn-sm">
                                Filter
                            </button>

                            <a href="batch_list.php" class="btn btn-light border btn-sm">
                                Reset
                            </a>
                        </div>
                    </div>

                </div>
            </form>
        </div>
    </div>

    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center flex-wrap">
            <strong>Batch List</strong>

            <small class="text-muted">
                Showing <?= $totalRecords > 0 ? ($offset + 1) : 0 ?> 
                to <?= min($offset + $limit, $totalRecords) ?> 
                of <?= number_format($totalRecords) ?> records
            </small>
        </div>

        <div class="card-body">

            <div class="table-responsive">
                <table class="table table-bordered table-hover align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Batch Code</th>
                            <th>Product</th>
                            <th>Batch Owner</th>
                            <th>Produced Qty</th>
                            <th>Production Date</th>
                            <th>Total Material Cost</th>
                            <th>Settlement Amount</th>
                            <th>Settlement Status</th>
                            <th>Created By</th>
                            <th style="width: 220px;">Action</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if ($result && $result->num_rows > 0): ?>
                            <?php $i = $offset + 1; while ($row = $result->fetch_assoc()): ?>

                                <?php
                                $settlement_amount = (float)$row['settlement_amount'];
                                $pending_settlement = (float)$row['pending_settlement'];
                                $settled_settlement = (float)$row['settled_settlement'];

                                $settlement_status = settlementStatusText(
                                    $settlement_amount,
                                    $pending_settlement,
                                    $settled_settlement
                                );
                                ?>

                                <tr>
                                    <td><?= $i++ ?></td>

                                    <td>
                                        <strong><?= htmlspecialchars($row['batch_code']) ?></strong>
                                    </td>

                                    <td><?= htmlspecialchars($row['product_name'] ?? '-') ?></td>

                                    <td><?= companyName($row['batch_owner']) ?></td>

                                    <td><?= number_format((float)$row['product_qty'], 2) ?></td>

                                    <td>
                                        <?= !empty($row['production_date']) ? date('d-m-Y', strtotime($row['production_date'])) : '-' ?>
                                    </td>

                                    <td>
                                        ₹<?= number_format((float)$row['total_material_cost'], 2) ?>
                                    </td>

                                    <td>
                                        <?php if ($settlement_amount > 0): ?>
                                            <strong>₹<?= number_format($settlement_amount, 2) ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">₹0.00</span>
                                        <?php endif; ?>
                                    </td>

                                    <td>
                                        <?php if ($settlement_status === 'No Settlement'): ?>
                                            <span class="badge bg-success">No Settlement</span>
                                        <?php elseif ($settlement_status === 'Part Pending'): ?>
                                            <span class="badge bg-warning text-dark">Part Pending</span>
                                        <?php elseif ($settlement_status === 'Pending'): ?>
                                            <span class="badge bg-danger">Pending</span>
                                        <?php else: ?>
                                            <span class="badge bg-success">Settled</span>
                                        <?php endif; ?>
                                    </td>

                                    <td><?= htmlspecialchars($row['created_by_name'] ?? '-') ?></td>

                                    <td>
                                        <div class="action-btns">
                                            <a href="batch_view.php?id=<?= (int)$row['batch_id'] ?>" class="btn btn-sm btn-primary">
                                                View
                                            </a>

                                            <a href="batch_edit.php?batch_id=<?= (int)$row['batch_id'] ?>" class="btn btn-sm btn-warning">
                                                Edit
                                            </a>

                                            <a href="batch_settlement_report.php?batch_id=<?= (int)$row['batch_id'] ?>" class="btn btn-sm btn-outline-info">
                                                Report
                                            </a>
                                        </div>
                                    </td>
                                </tr>

                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="11" class="text-center text-muted py-4">
                                    No batch found.
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>

                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="pagination-wrap">

                    <div class="pagination-info">
                        Page <?= (int)$page ?> of <?= (int)$totalPages ?>
                    </div>

                    <nav>
                        <ul class="pagination mb-0">

                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= buildQueryString(['page' => 1, 'export' => null]) ?>">
                                    First
                                </a>
                            </li>

                            <li class="page-item <?= ($page <= 1) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= buildQueryString(['page' => max(1, $page - 1), 'export' => null]) ?>">
                                    Previous
                                </a>
                            </li>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);

                            if ($startPage > 1) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }

                            for ($p = $startPage; $p <= $endPage; $p++):
                            ?>
                                <li class="page-item <?= ($p == $page) ? 'active' : '' ?>">
                                    <a class="page-link" href="?<?= buildQueryString(['page' => $p, 'export' => null]) ?>">
                                        <?= $p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php
                            if ($endPage < $totalPages) {
                                echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                            }
                            ?>

                            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= buildQueryString(['page' => min($totalPages, $page + 1), 'export' => null]) ?>">
                                    Next
                                </a>
                            </li>

                            <li class="page-item <?= ($page >= $totalPages) ? 'disabled' : '' ?>">
                                <a class="page-link" href="?<?= buildQueryString(['page' => $totalPages, 'export' => null]) ?>">
                                    Last
                                </a>
                            </li>

                        </ul>
                    </nav>
                </div>
            <?php endif; ?>

        </div>
    </div>

</div>

<script>
document.addEventListener("DOMContentLoaded", function () {
    const dateFilter = document.getElementById("date_filter");
    const customDateBoxes = document.querySelectorAll(".custom-date-box");

    function toggleCustomDate() {
        if (dateFilter.value === "custom") {
            customDateBoxes.forEach(function (box) {
                box.style.display = "block";
            });
        } else {
            customDateBoxes.forEach(function (box) {
                box.style.display = "none";
            });
        }
    }

    if (dateFilter) {
        dateFilter.addEventListener("change", toggleCustomDate);
        toggleCustomDate();
    }
});
</script>

<?php include('include/footer.php'); ?>