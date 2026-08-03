<?php
require_once 'include/require_permission.php';
requirePermission('CUSTOMERS', 'view');
require_once 'include/require_login.php';
include 'include/db.php';

/*
|--------------------------------------------------------------------------
| Customer Follow-up Report
|--------------------------------------------------------------------------
| Shows:
| - Customers who ordered in selected month
| - Delivered orders only
| - Excludes customers who placed Repeat order in current month
| - Shows last ordered products
| - Simple Excel export
|--------------------------------------------------------------------------
*/

function columnExists($mysqli, $table, $column) {
    $table = $mysqli->real_escape_string($table);
    $column = $mysqli->real_escape_string($column);
    $res = $mysqli->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
    return ($res && $res->num_rows > 0);
}

function tableExists($mysqli, $table) {
    $table = $mysqli->real_escape_string($table);
    $res = $mysqli->query("SHOW TABLES LIKE '$table'");
    return ($res && $res->num_rows > 0);
}

function getFirstAvailableColumn($mysqli, $table, $columns) {
    foreach ($columns as $col) {
        if (columnExists($mysqli, $table, $col)) {
            return $col;
        }
    }
    return null;
}

/*
|--------------------------------------------------------------------------
| Detect customer/order columns
|--------------------------------------------------------------------------
*/

$customerNameCol = getFirstAvailableColumn($mysqli, 'customers', [
    'customer_name',
    'name',
    'full_name',
    'billing_name'
]);

$customerPhoneCol = getFirstAvailableColumn($mysqli, 'customers', [
    'phone',
    'mobile',
    'contact',
    'customer_mobile',
    'mobile_number'
]);

$customerCityCol = getFirstAvailableColumn($mysqli, 'customers', [
    'city',
    'customer_city',
    'billing_city'
]);

$orderStatusCol = getFirstAvailableColumn($mysqli, 'orders', [
    'order_status',
    'status',
    'delivery_status'
]);

$deliveredDateCol = getFirstAvailableColumn($mysqli, 'orders', [
    'delivered_at',
    'delivered_date',
    'delivery_date'
]);

if (!$customerNameCol) {
    die("Customer name column not found in customers table.");
}

if (!$orderStatusCol) {
    die("Order status column not found in orders table.");
}

/*
|--------------------------------------------------------------------------
| Product/order item table detection
|--------------------------------------------------------------------------
| If your order item table name is different, change this section only.
|--------------------------------------------------------------------------
*/

$orderItemTable = null;

foreach (['order_items', 'order_products', 'order_details', 'order_product_items'] as $tbl) {
    if (tableExists($mysqli, $tbl)) {
        $orderItemTable = $tbl;
        break;
    }
}

$orderItemOrderIdCol = null;
$orderItemProductIdCol = null;
$orderItemProductNameCol = null;
$orderItemQtyCol = null;

if ($orderItemTable) {
    $orderItemOrderIdCol = getFirstAvailableColumn($mysqli, $orderItemTable, [
        'order_id',
        'orderid'
    ]);

    $orderItemProductIdCol = getFirstAvailableColumn($mysqli, $orderItemTable, [
        'product_id',
        'item_id'
    ]);

    $orderItemProductNameCol = getFirstAvailableColumn($mysqli, $orderItemTable, [
        'product_name',
        'item_name',
        'title',
        'name'
    ]);

    $orderItemQtyCol = getFirstAvailableColumn($mysqli, $orderItemTable, [
        'quantity',
        'qty',
        'product_qty'
    ]);
}

$productNameCol = null;

if (tableExists($mysqli, 'products')) {
    $productNameCol = getFirstAvailableColumn($mysqli, 'products', [
        'title',
        'product_name',
        'name'
    ]);
}

/*
|--------------------------------------------------------------------------
| Month filter
|--------------------------------------------------------------------------
*/

$selectedMonth = $_GET['month'] ?? date('Y-m', strtotime('-1 month'));

if (!preg_match('/^\d{4}-\d{2}$/', $selectedMonth)) {
    $selectedMonth = date('Y-m', strtotime('-1 month'));
}

$fromDate = $selectedMonth . '-01';
$toDate   = date('Y-m-t', strtotime($fromDate));

$currentMonthStart = date('Y-m-01');
$currentMonthEnd   = date('Y-m-t');

/*
|--------------------------------------------------------------------------
| Delivered statuses
|--------------------------------------------------------------------------
*/

$deliveredStatuses = [
    'Delivered',
    'delivered',
    'DELIVERED',
    'Completed',
    'completed'
];

$placeholders = implode(',', array_fill(0, count($deliveredStatuses), '?'));

$selectPhone = $customerPhoneCol ? "c.`$customerPhoneCol` AS customer_phone" : "'' AS customer_phone";
$selectCity  = $customerCityCol ? "c.`$customerCityCol` AS customer_city" : "'' AS customer_city";

$selectDeliveredDate = $deliveredDateCol
    ? "MAX(o.`$deliveredDateCol`) AS delivered_date"
    : "NULL AS delivered_date";

/*
|--------------------------------------------------------------------------
| Main customer query
|--------------------------------------------------------------------------
*/

$sql = "
    SELECT 
        c.customer_id,
        c.`$customerNameCol` AS customer_name,
        $selectPhone,
        $selectCity,

        COUNT(o.order_id) AS total_orders,
        GROUP_CONCAT(o.invoice_number ORDER BY o.order_date DESC SEPARATOR ', ') AS invoices,

        MIN(o.order_date) AS first_order_date,
        MAX(o.order_date) AS last_order_date,

        SUBSTRING_INDEX(
            GROUP_CONCAT(o.order_id ORDER BY o.order_date DESC, o.order_id DESC),
            ',',
            1
        ) AS last_order_id,

        SUBSTRING_INDEX(
            GROUP_CONCAT(o.invoice_number ORDER BY o.order_date DESC, o.order_id DESC),
            ',',
            1
        ) AS last_invoice_number,

        $selectDeliveredDate

    FROM orders o
    INNER JOIN customers c ON c.customer_id = o.customer_id

    WHERE 
        o.order_date BETWEEN ? AND ?
        AND o.`$orderStatusCol` IN ($placeholders)

        AND NOT EXISTS (
            SELECT 1
            FROM orders ro
            WHERE 
                ro.customer_id = o.customer_id
                AND ro.order_date BETWEEN ? AND ?
                AND ro.order_data = 'Repeat'
        )

    GROUP BY 
        c.customer_id,
        c.`$customerNameCol`
        " . ($customerPhoneCol ? ", c.`$customerPhoneCol`" : "") . "
        " . ($customerCityCol ? ", c.`$customerCityCol`" : "") . "

    ORDER BY last_order_date DESC
";

$stmt = $mysqli->prepare($sql);

if (!$stmt) {
    die("SQL Prepare Error: " . $mysqli->error);
}

$types = "ss" . str_repeat("s", count($deliveredStatuses)) . "ss";

$params = array_merge(
    [$fromDate, $toDate],
    $deliveredStatuses,
    [$currentMonthStart, $currentMonthEnd]
);

$stmt->bind_param($types, ...$params);
$stmt->execute();
$result = $stmt->get_result();

$rows = [];

while ($row = $result->fetch_assoc()) {
    $row['last_order_products'] = 'Product item table not found';
    $rows[] = $row;
}

/*
|--------------------------------------------------------------------------
| Fetch last order products
|--------------------------------------------------------------------------
*/

if (!empty($rows) && $orderItemTable && $orderItemOrderIdCol) {

    foreach ($rows as $key => $row) {

        $lastOrderId = (int)$row['last_order_id'];

        if ($lastOrderId <= 0) {
            $rows[$key]['last_order_products'] = '-';
            continue;
        }

        /*
        |--------------------------------------------------------------------------
        | Product name source priority:
        | 1. products table title/product_name/name
        | 2. order item table product_name/item_name
        |--------------------------------------------------------------------------
        */

        if ($orderItemProductIdCol && $productNameCol) {

            $qtySelect = $orderItemQtyCol ? "oi.`$orderItemQtyCol`" : "1";

            $productSql = "
                SELECT 
                    GROUP_CONCAT(
                        CONCAT(
                            p.`$productNameCol`,
                            ' x ',
                            $qtySelect
                        )
                        SEPARATOR ', '
                    ) AS products
                FROM `$orderItemTable` oi
                LEFT JOIN products p ON p.product_id = oi.`$orderItemProductIdCol`
                WHERE oi.`$orderItemOrderIdCol` = ?
            ";

        } elseif ($orderItemProductNameCol) {

            $qtySelect = $orderItemQtyCol ? "oi.`$orderItemQtyCol`" : "1";

            $productSql = "
                SELECT 
                    GROUP_CONCAT(
                        CONCAT(
                            oi.`$orderItemProductNameCol`,
                            ' x ',
                            $qtySelect
                        )
                        SEPARATOR ', '
                    ) AS products
                FROM `$orderItemTable` oi
                WHERE oi.`$orderItemOrderIdCol` = ?
            ";

        } else {

            $rows[$key]['last_order_products'] = 'Product columns not found';
            continue;
        }

        $productStmt = $mysqli->prepare($productSql);

        if ($productStmt) {
            $productStmt->bind_param("i", $lastOrderId);
            $productStmt->execute();
            $productResult = $productStmt->get_result();
            $productRow = $productResult->fetch_assoc();

            $rows[$key]['last_order_products'] = $productRow['products'] ?: '-';
        } else {
            $rows[$key]['last_order_products'] = '-';
        }
    }
}

/*
|--------------------------------------------------------------------------
| Simple Excel Export
|--------------------------------------------------------------------------
*/

if (isset($_GET['export']) && $_GET['export'] === 'excel') {

    $filename = "customer_followup_report_" . $selectedMonth . ".xls";

    header("Content-Type: application/vnd.ms-excel; charset=UTF-8");
    header("Content-Disposition: attachment; filename=\"$filename\"");
    header("Pragma: no-cache");
    header("Expires: 0");

    echo "\xEF\xBB\xBF";
    ?>

    <table border="1">
        <thead>
            <tr>
                <th colspan="11" style="font-size:18px;">
                    Customer Follow-up Report - <?= htmlspecialchars(date('F Y', strtotime($fromDate))) ?>
                </th>
            </tr>

            <tr>
                <th>Sr No</th>
                <th>Customer ID</th>
                <th>Customer Name</th>
                <th>Phone</th>
                <th>City</th>
                <th>Total Orders</th>
                <th>All Invoice Numbers</th>
                <th>Last Invoice Number</th>
                <th>Last Ordered Products</th>
                <th>Last Order Date</th>
                <th>Delivered Date</th>
            </tr>
        </thead>

        <tbody>
            <?php if (!empty($rows)): ?>
                <?php $i = 1; foreach ($rows as $r): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><?= htmlspecialchars($r['customer_id']) ?></td>
                        <td><?= htmlspecialchars($r['customer_name']) ?></td>
                        <td><?= htmlspecialchars($r['customer_phone']) ?></td>
                        <td><?= htmlspecialchars($r['customer_city']) ?></td>
                        <td><?= htmlspecialchars($r['total_orders']) ?></td>
                        <td><?= htmlspecialchars($r['invoices']) ?></td>
                        <td><?= htmlspecialchars($r['last_invoice_number']) ?></td>
                        <td><?= htmlspecialchars($r['last_order_products']) ?></td>
                        <td><?= htmlspecialchars($r['last_order_date']) ?></td>
                        <td><?= htmlspecialchars($r['delivered_date'] ?? '') ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="11">No data found</td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <?php
    exit;
}

include 'include/header.php';
?>

<style>
    .report-wrap {
        padding: 20px;
    }

    .report-card {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 6px 22px rgba(0,0,0,0.06);
        overflow: hidden;
    }

    .report-header {
        background: #ffffff;
        border-bottom: 1px solid #eef0f4;
        padding: 18px 20px;
    }

    .report-title {
        font-size: 20px;
        font-weight: 700;
        margin: 0;
        color: #222;
    }

    .report-subtitle {
        margin-top: 4px;
        font-size: 13px;
        color: #6c757d;
    }

    .filter-box {
        background: #f8f9fb;
        border-radius: 14px;
        padding: 15px;
        margin-bottom: 18px;
    }

    .summary-box {
        border-radius: 14px;
        background: #fff;
        border: 1px solid #eef0f4;
        padding: 14px 16px;
        height: 100%;
    }

    .summary-label {
        font-size: 13px;
        color: #6c757d;
        margin-bottom: 5px;
    }

    .summary-value {
        font-size: 24px;
        font-weight: 700;
        color: #212529;
    }

    .table thead th {
        font-size: 13px;
        white-space: nowrap;
        background: #f8f9fb;
        color: #495057;
        border-bottom: 1px solid #e9ecef;
    }

    .table tbody td {
        font-size: 14px;
        vertical-align: middle;
    }

    .customer-name {
        font-weight: 600;
        color: #212529;
    }

    .small-muted {
        font-size: 12px;
        color: #6c757d;
    }

    .product-text {
        max-width: 360px;
        white-space: normal;
        line-height: 1.5;
    }

    .invoice-badge {
        display: inline-block;
        background: #f1f3f5;
        border-radius: 20px;
        padding: 3px 9px;
        font-size: 12px;
        margin: 2px;
    }

    .empty-box {
        text-align: center;
        padding: 40px 15px;
        color: #6c757d;
    }

    @media (max-width: 767px) {
        .report-wrap {
            padding: 12px;
        }

        .desktop-table {
            display: none;
        }

        .mobile-card {
            border: 1px solid #eef0f4;
            border-radius: 14px;
            padding: 14px;
            margin-bottom: 12px;
            background: #fff;
            box-shadow: 0 4px 12px rgba(0,0,0,0.04);
        }

        .mobile-card-title {
            font-weight: 700;
            font-size: 15px;
            margin-bottom: 5px;
        }

        .mobile-info {
            font-size: 13px;
            color: #555;
            margin-bottom: 5px;
        }

        .btn-full-mobile {
            width: 100%;
            margin-top: 8px;
        }
    }

    @media (min-width: 768px) {
        .mobile-list {
            display: none;
        }
    }
</style>

<div class="container-fluid report-wrap">

    <div class="card report-card">

        <div class="report-header">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <h4 class="report-title">Customer Follow-up Report</h4>
                    <div class="report-subtitle">
                        Delivered customers from selected month, excluding customers who repeated in current month.
                    </div>
                </div>

                <a href="?month=<?= urlencode($selectedMonth) ?>&export=excel" class="btn btn-success btn-sm">
                    Export Simple Excel
                </a>
            </div>
        </div>

        <div class="card-body">

            <form method="GET" class="filter-box">
                <div class="row g-3 align-items-end">

                    <div class="col-md-4">
                        <label class="form-label">Select Order Month</label>
                        <input type="month" name="month" class="form-control" value="<?= htmlspecialchars($selectedMonth) ?>">
                    </div>

                    <div class="col-md-3">
                        <button type="submit" class="btn btn-primary w-100">
                            Filter Report
                        </button>
                    </div>

                    <div class="col-md-3">
                        <a href="customer_followup_report.php" class="btn btn-outline-secondary w-100">
                            Reset
                        </a>
                    </div>

                </div>
            </form>

            <div class="row g-3 mb-4">

                <div class="col-md-4">
                    <div class="summary-box">
                        <div class="summary-label">Selected Month</div>
                        <div class="summary-value" style="font-size:20px;">
                            <?= htmlspecialchars(date('F Y', strtotime($fromDate))) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="summary-box">
                        <div class="summary-label">Eligible Customers</div>
                        <div class="summary-value">
                            <?= count($rows) ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-4">
                    <div class="summary-box">
                        <div class="summary-label">Current Month Repeat Excluded</div>
                        <div class="summary-value" style="font-size:20px;">
                            <?= htmlspecialchars(date('F Y')) ?>
                        </div>
                    </div>
                </div>

            </div>

            <?php if (!empty($rows)): ?>

                <div class="table-responsive desktop-table">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th width="60">#</th>
                                <th>Customer</th>
                                <th>Phone</th>
                                <th>City</th>
                                <th>Total Orders</th>
                                <th>Last Invoice</th>
                                <th>Last Ordered Products</th>
                                <th>Last Order Date</th>
                                <th>Delivered Date</th>
                            </tr>
                        </thead>

                        <tbody>
                            <?php $i = 1; foreach ($rows as $r): ?>
                                <tr>
                                    <td><?= $i++ ?></td>

                                    <td>
                                        <div class="customer-name">
                                            <?= htmlspecialchars($r['customer_name']) ?>
                                        </div>
                                        <div class="small-muted">
                                            Customer ID: <?= htmlspecialchars($r['customer_id']) ?>
                                        </div>
                                    </td>

                                    <td><?= htmlspecialchars($r['customer_phone']) ?></td>

                                    <td><?= htmlspecialchars($r['customer_city']) ?></td>

                                    <td>
                                        <span class="badge bg-primary">
                                            <?= htmlspecialchars($r['total_orders']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <span class="invoice-badge">
                                            <?= htmlspecialchars($r['last_invoice_number']) ?>
                                        </span>
                                    </td>

                                    <td>
                                        <div class="product-text">
                                            <?= htmlspecialchars($r['last_order_products']) ?>
                                        </div>
                                    </td>

                                    <td>
                                        <?= htmlspecialchars(date('d M Y', strtotime($r['last_order_date']))) ?>
                                    </td>

                                    <td>
                                        <?php if (!empty($r['delivered_date'])): ?>
                                            <?= htmlspecialchars(date('d M Y', strtotime($r['delivered_date']))) ?>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="mobile-list">
                    <?php $i = 1; foreach ($rows as $r): ?>
                        <div class="mobile-card">
                            <div class="mobile-card-title">
                                <?= $i++ ?>. <?= htmlspecialchars($r['customer_name']) ?>
                            </div>

                            <div class="mobile-info">
                                <strong>Customer ID:</strong> <?= htmlspecialchars($r['customer_id']) ?>
                            </div>

                            <div class="mobile-info">
                                <strong>Phone:</strong> <?= htmlspecialchars($r['customer_phone'] ?: '-') ?>
                            </div>

                            <div class="mobile-info">
                                <strong>City:</strong> <?= htmlspecialchars($r['customer_city'] ?: '-') ?>
                            </div>

                            <div class="mobile-info">
                                <strong>Total Orders:</strong> <?= htmlspecialchars($r['total_orders']) ?>
                            </div>

                            <div class="mobile-info">
                                <strong>Last Invoice:</strong> <?= htmlspecialchars($r['last_invoice_number']) ?>
                            </div>

                            <div class="mobile-info">
                                <strong>Last Ordered Products:</strong><br>
                                <?= htmlspecialchars($r['last_order_products']) ?>
                            </div>

                            <div class="mobile-info">
                                <strong>Last Order Date:</strong>
                                <?= htmlspecialchars(date('d M Y', strtotime($r['last_order_date']))) ?>
                            </div>

                            <div class="mobile-info">
                                <strong>Delivered Date:</strong>
                                <?php if (!empty($r['delivered_date'])): ?>
                                    <?= htmlspecialchars(date('d M Y', strtotime($r['delivered_date']))) ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>

            <?php else: ?>

                <div class="empty-box">
                    <h5>No customers found</h5>
                    <p class="mb-0">
                        No delivered customers found for <?= htmlspecialchars(date('F Y', strtotime($fromDate))) ?>,
                        or all customers have already repeated in current month.
                    </p>
                </div>

            <?php endif; ?>

        </div>
    </div>

</div>

<?php include 'include/footer.php'; ?>