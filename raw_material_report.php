<?php
require_once 'include/require_permission.php';
requirePermission('RAW_MATERIAL_REPORT', 'view');
include('include/db.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/* =========================
   Helpers
========================= */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyFormat($value) {
    return '₹' . number_format((float)$value, 2);
}

function qtyFormat($value) {
    $value = (float)$value;
    return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
}

function dateFormatSafe($date) {
    if (empty($date) || $date === '0000-00-00') {
        return '-';
    }

    return date('d M Y', strtotime($date));
}

function buildQuery($extra = []) {
    $query = array_merge($_GET, $extra);

    foreach ($query as $key => $value) {
        if ($value === '' || $value === null) {
            unset($query[$key]);
        }
    }

    return http_build_query($query);
}

function runPrepared($mysqli, $sql, $types = "", $params = []) {
    $stmt = $mysqli->prepare($sql);

    if (!$stmt) {
        die("SQL Prepare Error: " . $mysqli->error . "<br><br>Query:<br><pre>" . h($sql) . "</pre>");
    }

    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    $rows = [];

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $rows[] = $row;
        }
    }

    $stmt->close();

    return $rows;
}

/* =========================
   Filters
========================= */
$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date = $_GET['to_date'] ?? date('Y-m-d');

$product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
$batch_id = isset($_GET['batch_id']) ? (int)$_GET['batch_id'] : 0;
$raw_material_id = isset($_GET['raw_material_id']) ? (int)$_GET['raw_material_id'] : 0;

$batch_owner = trim($_GET['batch_owner'] ?? '');
$material_owner_company = trim($_GET['material_owner_company'] ?? '');
$settlement_status = trim($_GET['settlement_status'] ?? '');

$view_type = $_GET['view_type'] ?? 'summary';
$export = $_GET['export'] ?? '';

$allowedViewTypes = ['summary', 'month_wise', 'product_wise', 'batch_wise', 'detail', 'settlement'];

if (!in_array($view_type, $allowedViewTypes, true)) {
    $view_type = 'summary';
}

/* =========================
   Dropdown Data
========================= */
$products = [];
$productSql = "
    SELECT 
        product_id, 
        title, 
        product_owner 
    FROM products 
    ORDER BY title ASC
";
$productResult = $mysqli->query($productSql);

if ($productResult) {
    while ($row = $productResult->fetch_assoc()) {
        $products[] = $row;
    }
}

$batches = [];
$batchSql = "
    SELECT 
        batch_id, 
        batch_code, 
        production_date,
        batch_owner
    FROM batches 
    ORDER BY batch_id DESC
";
$batchResult = $mysqli->query($batchSql);

if ($batchResult) {
    while ($row = $batchResult->fetch_assoc()) {
        $batches[] = $row;
    }
}

$rawMaterials = [];
$rmSql = "
    SELECT 
        raw_material_id, 
        material_name, 
        unit,
        owner_type,
        current_stock,
        average_price,
        last_purchase_price
    FROM raw_materials
    ORDER BY material_name ASC
";
$rmResult = $mysqli->query($rmSql);

if ($rmResult) {
    while ($row = $rmResult->fetch_assoc()) {
        $rawMaterials[] = $row;
    }
}

/* =========================
   Dynamic WHERE
========================= */
$where = [];
$params = [];
$types = "";

$where[] = "b.production_date BETWEEN ? AND ?";
$params[] = $from_date;
$params[] = $to_date;
$types .= "ss";

if ($product_id > 0) {
    $where[] = "b.product_id = ?";
    $params[] = $product_id;
    $types .= "i";
}

if ($batch_id > 0) {
    $where[] = "b.batch_id = ?";
    $params[] = $batch_id;
    $types .= "i";
}

if ($raw_material_id > 0) {
    $where[] = "brm.raw_material_id = ?";
    $params[] = $raw_material_id;
    $types .= "i";
}

if ($batch_owner !== '') {
    $where[] = "b.batch_owner = ?";
    $params[] = $batch_owner;
    $types .= "s";
}

if ($material_owner_company !== '') {
    $where[] = "brm.material_owner_company = ?";
    $params[] = strtolower($material_owner_company);
    $types .= "s";
}

if ($settlement_status !== '') {
    $where[] = "brm.settlement_status = ?";
    $params[] = $settlement_status;
    $types .= "s";
}

$whereSql = "WHERE " . implode(" AND ", $where);

/* =========================
   Summary Cards
========================= */
$summarySql = "
    SELECT 
        COUNT(DISTINCT b.batch_id) AS total_batches,
        COUNT(DISTINCT b.product_id) AS total_products,
        COUNT(DISTINCT brm.raw_material_id) AS total_raw_materials,
        COUNT(brm.id) AS total_usage_entries,
        COALESCE(SUM(brm.quantity_used), 0) AS total_qty_used,
        COALESCE(SUM(brm.amount), 0) AS total_amount,
        COALESCE(SUM(CASE WHEN brm.settlement_required = 1 THEN brm.amount ELSE 0 END), 0) AS settlement_amount
    FROM batch_raw_materials brm
    INNER JOIN batches b 
        ON b.batch_id = brm.batch_id
    LEFT JOIN products p 
        ON p.product_id = b.product_id
    LEFT JOIN raw_materials rm 
        ON rm.raw_material_id = brm.raw_material_id
    $whereSql
";
$summaryRows = runPrepared($mysqli, $summarySql, $types, $params);

$summary = $summaryRows[0] ?? [
    'total_batches' => 0,
    'total_products' => 0,
    'total_raw_materials' => 0,
    'total_usage_entries' => 0,
    'total_qty_used' => 0,
    'total_amount' => 0,
    'settlement_amount' => 0
];

/* =========================
   Summary Raw Material
========================= */
$summaryRawSql = "
    SELECT 
        rm.raw_material_id,
        rm.material_name,
        rm.owner_type,
        COALESCE(brm.unit, rm.unit) AS unit,
        rm.current_stock,
        rm.average_price,
        rm.last_purchase_price,
        SUM(brm.quantity_used) AS total_qty_used,
        AVG(brm.rate) AS avg_rate,
        SUM(brm.amount) AS total_amount,
        COUNT(DISTINCT b.batch_id) AS batch_count,
        COUNT(DISTINCT b.product_id) AS product_count,
        SUM(CASE WHEN brm.settlement_required = 1 THEN brm.amount ELSE 0 END) AS settlement_amount
    FROM batch_raw_materials brm
    INNER JOIN batches b 
        ON b.batch_id = brm.batch_id
    LEFT JOIN products p 
        ON p.product_id = b.product_id
    LEFT JOIN raw_materials rm 
        ON rm.raw_material_id = brm.raw_material_id
    $whereSql
    GROUP BY 
        rm.raw_material_id,
        rm.material_name,
        rm.owner_type,
        COALESCE(brm.unit, rm.unit),
        rm.current_stock,
        rm.average_price,
        rm.last_purchase_price
    ORDER BY total_amount DESC
";
$summaryRawRows = runPrepared($mysqli, $summaryRawSql, $types, $params);

/* =========================
   Month-wise Report
========================= */
$monthWiseSql = "
    SELECT 
        DATE_FORMAT(b.production_date, '%Y-%m') AS report_month,
        DATE_FORMAT(b.production_date, '%M %Y') AS month_name,
        rm.raw_material_id,
        rm.material_name,
        rm.owner_type,
        brm.material_owner_company,
        COALESCE(brm.unit, rm.unit) AS unit,
        SUM(brm.quantity_used) AS total_qty_used,
        AVG(brm.rate) AS avg_rate,
        SUM(brm.amount) AS total_amount,
        COUNT(DISTINCT b.batch_id) AS batch_count,
        COUNT(DISTINCT b.product_id) AS product_count
    FROM batch_raw_materials brm
    INNER JOIN batches b 
        ON b.batch_id = brm.batch_id
    LEFT JOIN products p 
        ON p.product_id = b.product_id
    LEFT JOIN raw_materials rm 
        ON rm.raw_material_id = brm.raw_material_id
    $whereSql
    GROUP BY 
        DATE_FORMAT(b.production_date, '%Y-%m'),
        rm.raw_material_id,
        rm.material_name,
        rm.owner_type,
        brm.material_owner_company,
        COALESCE(brm.unit, rm.unit)
    ORDER BY 
        report_month DESC,
        rm.material_name ASC
";
$monthWiseRows = runPrepared($mysqli, $monthWiseSql, $types, $params);

/* =========================
   Product-wise Report
========================= */
$productWiseSql = "
    SELECT 
        p.product_id,
        p.title AS product_name,
        p.product_owner,
        rm.raw_material_id,
        rm.material_name,
        rm.owner_type,
        brm.material_owner_company,
        COALESCE(brm.unit, rm.unit) AS unit,
        SUM(brm.quantity_used) AS total_qty_used,
        AVG(brm.rate) AS avg_rate,
        SUM(brm.amount) AS total_amount,
        COUNT(DISTINCT b.batch_id) AS batch_count
    FROM batch_raw_materials brm
    INNER JOIN batches b 
        ON b.batch_id = brm.batch_id
    LEFT JOIN products p 
        ON p.product_id = b.product_id
    LEFT JOIN raw_materials rm 
        ON rm.raw_material_id = brm.raw_material_id
    $whereSql
    GROUP BY 
        p.product_id,
        p.title,
        p.product_owner,
        rm.raw_material_id,
        rm.material_name,
        rm.owner_type,
        brm.material_owner_company,
        COALESCE(brm.unit, rm.unit)
    ORDER BY 
        p.title ASC,
        rm.material_name ASC
";
$productWiseRows = runPrepared($mysqli, $productWiseSql, $types, $params);

/* =========================
   Batch-wise Report
========================= */
$batchWiseSql = "
    SELECT 
        b.batch_id,
        b.batch_code,
        b.production_date,
        b.product_qty,
        b.batch_owner,
        p.product_id,
        p.title AS product_name,
        p.product_owner,
        rm.raw_material_id,
        rm.material_name,
        rm.owner_type,
        brm.material_owner_company,
        COALESCE(brm.unit, rm.unit) AS unit,
        SUM(brm.quantity_used) AS total_qty_used,
        AVG(brm.rate) AS avg_rate,
        SUM(brm.amount) AS total_amount
    FROM batch_raw_materials brm
    INNER JOIN batches b 
        ON b.batch_id = brm.batch_id
    LEFT JOIN products p 
        ON p.product_id = b.product_id
    LEFT JOIN raw_materials rm 
        ON rm.raw_material_id = brm.raw_material_id
    $whereSql
    GROUP BY 
        b.batch_id,
        b.batch_code,
        b.production_date,
        b.product_qty,
        b.batch_owner,
        p.product_id,
        p.title,
        p.product_owner,
        rm.raw_material_id,
        rm.material_name,
        rm.owner_type,
        brm.material_owner_company,
        COALESCE(brm.unit, rm.unit)
    ORDER BY 
        b.production_date DESC,
        b.batch_id DESC,
        rm.material_name ASC
";
$batchWiseRows = runPrepared($mysqli, $batchWiseSql, $types, $params);

/* =========================
   Settlement Report
========================= */
$settlementSql = "
    SELECT 
        brm.payable_from,
        brm.payable_to,
        brm.settlement_status,
        COUNT(brm.id) AS entry_count,
        COUNT(DISTINCT b.batch_id) AS batch_count,
        SUM(brm.quantity_used) AS total_qty_used,
        SUM(brm.amount) AS total_amount
    FROM batch_raw_materials brm
    INNER JOIN batches b 
        ON b.batch_id = brm.batch_id
    LEFT JOIN products p 
        ON p.product_id = b.product_id
    LEFT JOIN raw_materials rm 
        ON rm.raw_material_id = brm.raw_material_id
    $whereSql
    AND brm.settlement_required = 1
    GROUP BY 
        brm.payable_from,
        brm.payable_to,
        brm.settlement_status
    ORDER BY total_amount DESC
";
$settlementRows = runPrepared($mysqli, $settlementSql, $types, $params);

/* =========================
   Detail Report
========================= */
$detailSql = "
    SELECT 
        brm.id,
        b.batch_id,
        b.batch_code,
        b.production_date,
        b.product_qty,
        b.batch_owner,
        p.title AS product_name,
        p.product_owner,
        rm.material_name,
        rm.owner_type,
        brm.material_owner_company,
        brm.quantity_used,
        COALESCE(brm.unit, rm.unit) AS unit,
        brm.rate,
        brm.amount,
        brm.settlement_required,
        brm.payable_from,
        brm.payable_to,
        brm.settlement_status
    FROM batch_raw_materials brm
    INNER JOIN batches b 
        ON b.batch_id = brm.batch_id
    LEFT JOIN products p 
        ON p.product_id = b.product_id
    LEFT JOIN raw_materials rm 
        ON rm.raw_material_id = brm.raw_material_id
    $whereSql
    ORDER BY 
        b.production_date DESC,
        b.batch_id DESC,
        brm.id DESC
";
$detailRows = runPrepared($mysqli, $detailSql, $types, $params);

/* =========================
   Export CSV
========================= */
if ($export === 'csv') {
    requirePermission('RAW_MATERIAL_REPORT', 'export');

    $filename = "raw_material_utilization_report_" . date('Ymd_His') . ".csv";

    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"$filename\"");

    $output = fopen('php://output', 'w');

    fputcsv($output, [
        'Production Date',
        'Batch Code',
        'Batch Owner',
        'Product',
        'Product Owner',
        'Product Qty',
        'Raw Material',
        'Raw Material Owner',
        'Material Owner Company',
        'Quantity Used',
        'Unit',
        'Rate',
        'Amount',
        'Settlement Required',
        'Payable From',
        'Payable To',
        'Settlement Status'
    ]);

    foreach ($detailRows as $row) {
        fputcsv($output, [
            $row['production_date'],
            $row['batch_code'],
            $row['batch_owner'],
            $row['product_name'],
            $row['product_owner'],
            $row['product_qty'],
            $row['material_name'],
            $row['owner_type'],
            strtoupper((string)$row['material_owner_company']),
            $row['quantity_used'],
            $row['unit'],
            $row['rate'],
            $row['amount'],
            ((int)$row['settlement_required'] === 1 ? 'Yes' : 'No'),
            strtoupper((string)$row['payable_from']),
            strtoupper((string)$row['payable_to']),
            $row['settlement_status']
        ]);
    }

    fclose($output);
    exit;
}

include('include/header.php');
?>

<style>
    .rm-report-wrapper {
        padding: 18px;
    }

    .page-title-box {
        background: #ffffff;
        border-radius: 16px;
        padding: 18px 20px;
        margin-bottom: 18px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
    }

    .page-title-box h3 {
        margin: 0;
        font-weight: 800;
        color: #111827;
        font-size: 24px;
    }

    .page-title-box p {
        margin: 6px 0 0;
        color: #6b7280;
        font-size: 14px;
    }

    .filter-card,
    .report-card {
        background: #ffffff;
        border-radius: 16px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
        margin-bottom: 18px;
    }

    .filter-card {
        padding: 16px;
    }

    .filter-grid {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
    }

    .filter-grid label {
        font-size: 13px;
        color: #374151;
        font-weight: 700;
        margin-bottom: 5px;
        display: block;
    }

    .form-control-custom {
        width: 100%;
        padding: 9px 10px;
        border-radius: 10px;
        border: 1px solid #d1d5db;
        font-size: 14px;
        background: #fff;
    }

    .btn-dark-custom {
        background: #111827;
        color: #ffffff;
        border: none;
        border-radius: 10px;
        padding: 9px 14px;
        text-decoration: none;
        display: inline-block;
        font-weight: 700;
        cursor: pointer;
    }

    .btn-light-custom {
        background: #f3f4f6;
        color: #111827;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        padding: 9px 14px;
        text-decoration: none;
        display: inline-block;
        font-weight: 700;
    }

    .summary-grid {
        display: grid;
        grid-template-columns: repeat(5, 1fr);
        gap: 14px;
        margin-bottom: 18px;
    }

    .summary-box {
        background: #ffffff;
        border-radius: 16px;
        padding: 16px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 4px 14px rgba(15, 23, 42, 0.04);
    }

    .summary-box .label {
        font-size: 13px;
        color: #6b7280;
        margin-bottom: 6px;
        font-weight: 600;
    }

    .summary-box .value {
        font-size: 21px;
        font-weight: 900;
        color: #111827;
    }

    .nav-tabs-custom {
        display: flex;
        flex-wrap: wrap;
        gap: 8px;
        margin-bottom: 18px;
    }

    .nav-tabs-custom a {
        text-decoration: none;
        padding: 10px 14px;
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        background: #ffffff;
        color: #374151;
        font-weight: 700;
        font-size: 14px;
    }

    .nav-tabs-custom a.active {
        background: #111827;
        color: #ffffff;
        border-color: #111827;
    }

    .report-card .card-header-custom {
        padding: 15px 18px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .report-card .card-header-custom h5 {
        margin: 0;
        font-weight: 800;
        color: #111827;
        font-size: 17px;
    }

    .report-card .card-body-custom {
        padding: 0;
    }

    .table-responsive {
        width: 100%;
        overflow-x: auto;
    }

    .report-table {
        width: 100%;
        border-collapse: collapse;
        margin: 0;
    }

    .report-table th {
        background: #f9fafb;
        color: #374151;
        font-size: 13px;
        font-weight: 800;
        padding: 12px;
        border-bottom: 1px solid #e5e7eb;
        white-space: nowrap;
    }

    .report-table td {
        padding: 12px;
        border-bottom: 1px solid #f1f5f9;
        color: #111827;
        font-size: 14px;
        vertical-align: middle;
        white-space: nowrap;
    }

    .text-right {
        text-align: right;
    }

    .badge-owner {
        display: inline-block;
        padding: 4px 9px;
        border-radius: 999px;
        background: #eef2ff;
        color: #3730a3;
        font-size: 12px;
        font-weight: 800;
    }

    .badge-cmd {
        background: #fff7ed;
        color: #c2410c;
    }

    .badge-unit {
        display: inline-block;
        padding: 4px 9px;
        border-radius: 999px;
        background: #ecfdf5;
        color: #047857;
        font-size: 12px;
        font-weight: 800;
    }

    .badge-status {
        display: inline-block;
        padding: 4px 9px;
        border-radius: 999px;
        background: #f3f4f6;
        color: #374151;
        font-size: 12px;
        font-weight: 800;
    }

    .badge-pending {
        background: #fef3c7;
        color: #92400e;
    }

    .badge-settled {
        background: #dcfce7;
        color: #166534;
    }

    .empty-box {
        padding: 32px;
        text-align: center;
        color: #6b7280;
    }

    .small-muted {
        font-size: 12px;
        color: #6b7280;
        margin-top: 3px;
    }

    @media (max-width: 1200px) {
        .summary-grid {
            grid-template-columns: repeat(3, 1fr);
        }

        .filter-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media (max-width: 768px) {
        .rm-report-wrapper {
            padding: 12px;
        }

        .summary-grid {
            grid-template-columns: repeat(1, 1fr);
        }

        .filter-grid {
            grid-template-columns: repeat(1, 1fr);
        }

        .btn-dark-custom,
        .btn-light-custom {
            width: 100%;
            text-align: center;
        }

        .page-title-box h3 {
            font-size: 20px;
        }
    }
</style>

<div class="content-wrapper">
    <section class="content">
        <div class="rm-report-wrapper">

            <div class="page-title-box">
                <h3>Raw Material Utilization Report</h3>
                <p>Report from existing batch raw material data. Month-wise, product-wise, batch-wise, settlement and detailed usage.</p>
            </div>

            <form method="GET" class="filter-card">
                <input type="hidden" name="view_type" value="<?php echo h($view_type); ?>">

                <div class="filter-grid">
                    <div>
                        <label>From Date</label>
                        <input type="date" name="from_date" class="form-control-custom" value="<?php echo h($from_date); ?>">
                    </div>

                    <div>
                        <label>To Date</label>
                        <input type="date" name="to_date" class="form-control-custom" value="<?php echo h($to_date); ?>">
                    </div>

                    <div>
                        <label>Batch Owner</label>
                        <select name="batch_owner" class="form-control-custom">
                            <option value="">All</option>
                            <option value="SATHEE" <?php echo ($batch_owner === 'SATHEE') ? 'selected' : ''; ?>>SATHEE</option>
                            <option value="CMD" <?php echo ($batch_owner === 'CMD') ? 'selected' : ''; ?>>CMD</option>
                        </select>
                    </div>

                    <div>
                        <label>Material Owner Company</label>
                        <select name="material_owner_company" class="form-control-custom">
                            <option value="">All</option>
                            <option value="sathee" <?php echo ($material_owner_company === 'sathee') ? 'selected' : ''; ?>>SATHEE</option>
                            <option value="cmd" <?php echo ($material_owner_company === 'cmd') ? 'selected' : ''; ?>>CMD</option>
                        </select>
                    </div>

                    <div>
                        <label>Product</label>
                        <select name="product_id" class="form-control-custom">
                            <option value="0">All Products</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?php echo (int)$product['product_id']; ?>" <?php echo ($product_id === (int)$product['product_id']) ? 'selected' : ''; ?>>
                                    <?php echo h($product['title']); ?> - <?php echo h($product['product_owner']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Batch</label>
                        <select name="batch_id" class="form-control-custom">
                            <option value="0">All Batches</option>
                            <?php foreach ($batches as $batch): ?>
                                <option value="<?php echo (int)$batch['batch_id']; ?>" <?php echo ($batch_id === (int)$batch['batch_id']) ? 'selected' : ''; ?>>
                                    <?php echo h($batch['batch_code']); ?> - <?php echo h($batch['batch_owner']); ?> - <?php echo h(dateFormatSafe($batch['production_date'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Raw Material</label>
                        <select name="raw_material_id" class="form-control-custom">
                            <option value="0">All Raw Materials</option>
                            <?php foreach ($rawMaterials as $rm): ?>
                                <option value="<?php echo (int)$rm['raw_material_id']; ?>" <?php echo ($raw_material_id === (int)$rm['raw_material_id']) ? 'selected' : ''; ?>>
                                    <?php echo h($rm['material_name']); ?> - <?php echo h($rm['owner_type']); ?> <?php echo !empty($rm['unit']) ? '(' . h($rm['unit']) . ')' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label>Settlement Status</label>
                        <select name="settlement_status" class="form-control-custom">
                            <option value="">All</option>
                            <option value="none" <?php echo ($settlement_status === 'none') ? 'selected' : ''; ?>>None</option>
                            <option value="pending" <?php echo ($settlement_status === 'pending') ? 'selected' : ''; ?>>Pending</option>
                            <option value="settled" <?php echo ($settlement_status === 'settled') ? 'selected' : ''; ?>>Settled</option>
                        </select>
                    </div>
                </div>

                <div style="margin-top:14px; display:flex; gap:8px; flex-wrap:wrap;">
                    <button type="submit" class="btn-dark-custom">Apply Filter</button>
                    <a href="raw_material_report.php" class="btn-light-custom">Reset</a>
                    <a href="?<?php echo h(buildQuery(['export' => 'csv'])); ?>" class="btn-light-custom">Export CSV</a>
                </div>
            </form>

            <div class="summary-grid">
                <div class="summary-box">
                    <div class="label">Total Batches</div>
                    <div class="value"><?php echo number_format((int)$summary['total_batches']); ?></div>
                </div>

                <div class="summary-box">
                    <div class="label">Total Products</div>
                    <div class="value"><?php echo number_format((int)$summary['total_products']); ?></div>
                </div>

                <div class="summary-box">
                    <div class="label">Raw Materials Used</div>
                    <div class="value"><?php echo number_format((int)$summary['total_raw_materials']); ?></div>
                </div>

                <div class="summary-box">
                    <div class="label">Usage Entries</div>
                    <div class="value"><?php echo number_format((int)$summary['total_usage_entries']); ?></div>
                </div>

                <div class="summary-box">
                    <div class="label">Total Used Amount</div>
                    <div class="value"><?php echo moneyFormat($summary['total_amount']); ?></div>
                </div>
            </div>

            <div class="summary-grid" style="grid-template-columns: repeat(2, 1fr);">
                <div class="summary-box">
                    <div class="label">Total Quantity Used</div>
                    <div class="value"><?php echo qtyFormat($summary['total_qty_used']); ?></div>
                </div>

                <div class="summary-box">
                    <div class="label">Settlement Amount</div>
                    <div class="value"><?php echo moneyFormat($summary['settlement_amount']); ?></div>
                </div>
            </div>

            <div class="nav-tabs-custom">
                <a class="<?php echo ($view_type === 'summary') ? 'active' : ''; ?>" href="?<?php echo h(buildQuery(['view_type' => 'summary'])); ?>">Summary</a>
                <a class="<?php echo ($view_type === 'month_wise') ? 'active' : ''; ?>" href="?<?php echo h(buildQuery(['view_type' => 'month_wise'])); ?>">Month-wise</a>
                <a class="<?php echo ($view_type === 'product_wise') ? 'active' : ''; ?>" href="?<?php echo h(buildQuery(['view_type' => 'product_wise'])); ?>">Product-wise</a>
                <a class="<?php echo ($view_type === 'batch_wise') ? 'active' : ''; ?>" href="?<?php echo h(buildQuery(['view_type' => 'batch_wise'])); ?>">Batch-wise</a>
                <a class="<?php echo ($view_type === 'settlement') ? 'active' : ''; ?>" href="?<?php echo h(buildQuery(['view_type' => 'settlement'])); ?>">Settlement</a>
                <a class="<?php echo ($view_type === 'detail') ? 'active' : ''; ?>" href="?<?php echo h(buildQuery(['view_type' => 'detail'])); ?>">Detail</a>
            </div>

            <?php if ($view_type === 'summary'): ?>

                <div class="report-card">
                    <div class="card-header-custom">
                        <h5>Raw Material Summary</h5>
                        <a href="?<?php echo h(buildQuery(['export' => 'csv'])); ?>" class="btn-light-custom">Export Detail CSV</a>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Raw Material</th>
                                        <th>RM Owner</th>
                                        <th>Unit</th>
                                        <th class="text-right">Current Stock</th>
                                        <th class="text-right">Average Price</th>
                                        <th class="text-right">Last Purchase</th>
                                        <th class="text-right">Batch Count</th>
                                        <th class="text-right">Product Count</th>
                                        <th class="text-right">Used Qty</th>
                                        <th class="text-right">Used Avg Rate</th>
                                        <th class="text-right">Used Amount</th>
                                        <th class="text-right">Settlement Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($summaryRawRows)): ?>
                                        <?php foreach ($summaryRawRows as $row): ?>
                                            <tr>
                                                <td><strong><?php echo h($row['material_name']); ?></strong></td>
                                                <td>
                                                    <span class="badge-owner <?php echo ($row['owner_type'] === 'CMD') ? 'badge-cmd' : ''; ?>">
                                                        <?php echo h($row['owner_type']); ?>
                                                    </span>
                                                </td>
                                                <td><span class="badge-unit"><?php echo h($row['unit']); ?></span></td>
                                                <td class="text-right"><?php echo qtyFormat($row['current_stock']); ?></td>
                                                <td class="text-right"><?php echo moneyFormat($row['average_price']); ?></td>
                                                <td class="text-right"><?php echo moneyFormat($row['last_purchase_price']); ?></td>
                                                <td class="text-right"><?php echo number_format((int)$row['batch_count']); ?></td>
                                                <td class="text-right"><?php echo number_format((int)$row['product_count']); ?></td>
                                                <td class="text-right"><?php echo qtyFormat($row['total_qty_used']); ?></td>
                                                <td class="text-right"><?php echo moneyFormat($row['avg_rate']); ?></td>
                                                <td class="text-right"><strong><?php echo moneyFormat($row['total_amount']); ?></strong></td>
                                                <td class="text-right"><strong><?php echo moneyFormat($row['settlement_amount']); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12">
                                                <div class="empty-box">No raw material usage found for selected filters.</div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($view_type === 'month_wise'): ?>

                <div class="report-card">
                    <div class="card-header-custom">
                        <h5>Month-wise Raw Material Usage</h5>
                        <a href="?<?php echo h(buildQuery(['export' => 'csv'])); ?>" class="btn-light-custom">Export Detail CSV</a>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Month</th>
                                        <th>Raw Material</th>
                                        <th>RM Owner</th>
                                        <th>Used From</th>
                                        <th>Unit</th>
                                        <th class="text-right">Batch Count</th>
                                        <th class="text-right">Product Count</th>
                                        <th class="text-right">Total Qty Used</th>
                                        <th class="text-right">Average Rate</th>
                                        <th class="text-right">Total Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($monthWiseRows)): ?>
                                        <?php foreach ($monthWiseRows as $row): ?>
                                            <tr>
                                                <td><strong><?php echo h($row['month_name']); ?></strong></td>
                                                <td><?php echo h($row['material_name']); ?></td>
                                                <td>
                                                    <span class="badge-owner <?php echo ($row['owner_type'] === 'CMD') ? 'badge-cmd' : ''; ?>">
                                                        <?php echo h($row['owner_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h(strtoupper((string)$row['material_owner_company'])); ?></td>
                                                <td><span class="badge-unit"><?php echo h($row['unit']); ?></span></td>
                                                <td class="text-right"><?php echo number_format((int)$row['batch_count']); ?></td>
                                                <td class="text-right"><?php echo number_format((int)$row['product_count']); ?></td>
                                                <td class="text-right"><?php echo qtyFormat($row['total_qty_used']); ?></td>
                                                <td class="text-right"><?php echo moneyFormat($row['avg_rate']); ?></td>
                                                <td class="text-right"><strong><?php echo moneyFormat($row['total_amount']); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10">
                                                <div class="empty-box">No month-wise raw material data found.</div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($view_type === 'product_wise'): ?>

                <div class="report-card">
                    <div class="card-header-custom">
                        <h5>Product-wise Raw Material Usage</h5>
                        <a href="?<?php echo h(buildQuery(['export' => 'csv'])); ?>" class="btn-light-custom">Export Detail CSV</a>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Product</th>
                                        <th>Product Owner</th>
                                        <th>Raw Material</th>
                                        <th>RM Owner</th>
                                        <th>Used From</th>
                                        <th>Unit</th>
                                        <th class="text-right">Batch Count</th>
                                        <th class="text-right">Total Qty Used</th>
                                        <th class="text-right">Average Rate</th>
                                        <th class="text-right">Total Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($productWiseRows)): ?>
                                        <?php foreach ($productWiseRows as $row): ?>
                                            <tr>
                                                <td><strong><?php echo h($row['product_name']); ?></strong></td>
                                                <td>
                                                    <span class="badge-owner <?php echo ($row['product_owner'] === 'CMD') ? 'badge-cmd' : ''; ?>">
                                                        <?php echo h($row['product_owner']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h($row['material_name']); ?></td>
                                                <td>
                                                    <span class="badge-owner <?php echo ($row['owner_type'] === 'CMD') ? 'badge-cmd' : ''; ?>">
                                                        <?php echo h($row['owner_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h(strtoupper((string)$row['material_owner_company'])); ?></td>
                                                <td><span class="badge-unit"><?php echo h($row['unit']); ?></span></td>
                                                <td class="text-right"><?php echo number_format((int)$row['batch_count']); ?></td>
                                                <td class="text-right"><?php echo qtyFormat($row['total_qty_used']); ?></td>
                                                <td class="text-right"><?php echo moneyFormat($row['avg_rate']); ?></td>
                                                <td class="text-right"><strong><?php echo moneyFormat($row['total_amount']); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="10">
                                                <div class="empty-box">No product-wise raw material data found.</div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($view_type === 'batch_wise'): ?>

                <div class="report-card">
                    <div class="card-header-custom">
                        <h5>Batch-wise Raw Material Usage</h5>
                        <a href="?<?php echo h(buildQuery(['export' => 'csv'])); ?>" class="btn-light-custom">Export Detail CSV</a>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Batch Code</th>
                                        <th>Production Date</th>
                                        <th>Batch Owner</th>
                                        <th>Product</th>
                                        <th>Product Qty</th>
                                        <th>Raw Material</th>
                                        <th>Used From</th>
                                        <th>Unit</th>
                                        <th class="text-right">Qty Used</th>
                                        <th class="text-right">Avg Rate</th>
                                        <th class="text-right">Amount</th>
                                        <th class="text-right">RM Cost / Unit</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($batchWiseRows)): ?>
                                        <?php foreach ($batchWiseRows as $row): ?>
                                            <?php
                                            $costPerUnit = 0;
                                            if ((float)$row['product_qty'] > 0) {
                                                $costPerUnit = (float)$row['total_amount'] / (float)$row['product_qty'];
                                            }
                                            ?>
                                            <tr>
                                                <td><strong><?php echo h($row['batch_code']); ?></strong></td>
                                                <td><?php echo h(dateFormatSafe($row['production_date'])); ?></td>
                                                <td>
                                                    <span class="badge-owner <?php echo ($row['batch_owner'] === 'CMD') ? 'badge-cmd' : ''; ?>">
                                                        <?php echo h($row['batch_owner']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h($row['product_name']); ?></td>
                                                <td class="text-right"><?php echo qtyFormat($row['product_qty']); ?></td>
                                                <td><?php echo h($row['material_name']); ?></td>
                                                <td><?php echo h(strtoupper((string)$row['material_owner_company'])); ?></td>
                                                <td><span class="badge-unit"><?php echo h($row['unit']); ?></span></td>
                                                <td class="text-right"><?php echo qtyFormat($row['total_qty_used']); ?></td>
                                                <td class="text-right"><?php echo moneyFormat($row['avg_rate']); ?></td>
                                                <td class="text-right"><strong><?php echo moneyFormat($row['total_amount']); ?></strong></td>
                                                <td class="text-right"><?php echo moneyFormat($costPerUnit); ?></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="12">
                                                <div class="empty-box">No batch-wise raw material data found.</div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($view_type === 'settlement'): ?>

                <div class="report-card">
                    <div class="card-header-custom">
                        <h5>Settlement Summary</h5>
                        <a href="?<?php echo h(buildQuery(['export' => 'csv'])); ?>" class="btn-light-custom">Export Detail CSV</a>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Payable From</th>
                                        <th>Payable To</th>
                                        <th>Status</th>
                                        <th class="text-right">Entry Count</th>
                                        <th class="text-right">Batch Count</th>
                                        <th class="text-right">Total Qty</th>
                                        <th class="text-right">Settlement Amount</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($settlementRows)): ?>
                                        <?php foreach ($settlementRows as $row): ?>
                                            <tr>
                                                <td><strong><?php echo h(strtoupper((string)$row['payable_from'])); ?></strong></td>
                                                <td><strong><?php echo h(strtoupper((string)$row['payable_to'])); ?></strong></td>
                                                <td>
                                                    <span class="badge-status <?php echo ($row['settlement_status'] === 'pending') ? 'badge-pending' : (($row['settlement_status'] === 'settled') ? 'badge-settled' : ''); ?>">
                                                        <?php echo h(ucfirst((string)$row['settlement_status'])); ?>
                                                    </span>
                                                </td>
                                                <td class="text-right"><?php echo number_format((int)$row['entry_count']); ?></td>
                                                <td class="text-right"><?php echo number_format((int)$row['batch_count']); ?></td>
                                                <td class="text-right"><?php echo qtyFormat($row['total_qty_used']); ?></td>
                                                <td class="text-right"><strong><?php echo moneyFormat($row['total_amount']); ?></strong></td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="7">
                                                <div class="empty-box">No settlement data found.</div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php elseif ($view_type === 'detail'): ?>

                <div class="report-card">
                    <div class="card-header-custom">
                        <h5>Detailed Raw Material Usage</h5>
                        <a href="?<?php echo h(buildQuery(['export' => 'csv'])); ?>" class="btn-light-custom">Export CSV</a>
                    </div>

                    <div class="card-body-custom">
                        <div class="table-responsive">
                            <table class="report-table">
                                <thead>
                                    <tr>
                                        <th>Production Date</th>
                                        <th>Batch Code</th>
                                        <th>Batch Owner</th>
                                        <th>Product</th>
                                        <th>Product Qty</th>
                                        <th>Raw Material</th>
                                        <th>RM Owner</th>
                                        <th>Used From</th>
                                        <th class="text-right">Qty Used</th>
                                        <th>Unit</th>
                                        <th class="text-right">Rate</th>
                                        <th class="text-right">Amount</th>
                                        <th>Settlement</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($detailRows)): ?>
                                        <?php foreach ($detailRows as $row): ?>
                                            <tr>
                                                <td><?php echo h(dateFormatSafe($row['production_date'])); ?></td>
                                                <td><strong><?php echo h($row['batch_code']); ?></strong></td>
                                                <td>
                                                    <span class="badge-owner <?php echo ($row['batch_owner'] === 'CMD') ? 'badge-cmd' : ''; ?>">
                                                        <?php echo h($row['batch_owner']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h($row['product_name']); ?></td>
                                                <td class="text-right"><?php echo qtyFormat($row['product_qty']); ?></td>
                                                <td><?php echo h($row['material_name']); ?></td>
                                                <td>
                                                    <span class="badge-owner <?php echo ($row['owner_type'] === 'CMD') ? 'badge-cmd' : ''; ?>">
                                                        <?php echo h($row['owner_type']); ?>
                                                    </span>
                                                </td>
                                                <td><?php echo h(strtoupper((string)$row['material_owner_company'])); ?></td>
                                                <td class="text-right"><?php echo qtyFormat($row['quantity_used']); ?></td>
                                                <td><span class="badge-unit"><?php echo h($row['unit']); ?></span></td>
                                                <td class="text-right"><?php echo moneyFormat($row['rate']); ?></td>
                                                <td class="text-right"><strong><?php echo moneyFormat($row['amount']); ?></strong></td>
                                                <td>
                                                    <?php if ((int)$row['settlement_required'] === 1): ?>
                                                        <span class="badge-status <?php echo ($row['settlement_status'] === 'pending') ? 'badge-pending' : (($row['settlement_status'] === 'settled') ? 'badge-settled' : ''); ?>">
                                                            <?php echo h(ucfirst((string)$row['settlement_status'])); ?>
                                                        </span>
                                                        <div class="small-muted">
                                                            <?php echo h(strtoupper((string)$row['payable_from'])); ?>
                                                            →
                                                            <?php echo h(strtoupper((string)$row['payable_to'])); ?>
                                                        </div>
                                                    <?php else: ?>
                                                        <span class="badge-status">No</span>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="13">
                                                <div class="empty-box">No detailed raw material usage data found.</div>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

            <?php endif; ?>

        </div>
    </section>
</div>

<?php include('include/footer.php'); ?>