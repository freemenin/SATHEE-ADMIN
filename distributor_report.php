<?php
require_once 'include/require_permission.php';
requirePermission('DISTRIBUTOR_REPORT', 'view');
include('include/require_login.php');
include('include/header.php');

date_default_timezone_set('Asia/Kolkata');

function money($amount) {
    return '₹' . number_format((float)$amount, 2);
}

function percentVal($value) {
    return number_format((float)$value, 2) . '%';
}

function cleanText($text) {
    return htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
}

function formatDateNice($date) {
    if (empty($date)) return '-';
    return date('d M Y', strtotime($date));
}

function reportMonthText($from_date, $to_date) {
    if (empty($from_date) || empty($to_date)) return '-';

    $fromMonth = date('F Y', strtotime($from_date));
    $toMonth   = date('F Y', strtotime($to_date));

    if ($fromMonth === $toMonth) {
        return $fromMonth;
    }

    return $fromMonth . ' to ' . $toMonth;
}

$range = $_GET['range'] ?? 'this_month';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';
$distributor_id = intval($_GET['distributor_id'] ?? 0);
$status = $_GET['status'] ?? 'dispatched';
$product_owner = $_GET['product_owner'] ?? 'all';
$print_mode = isset($_GET['print']) && $_GET['print'] == '1';

$today = date('Y-m-d');

if ($range === 'today') {
    $from_date = $today;
    $to_date = $today;
} elseif ($range === 'yesterday') {
    $from_date = date('Y-m-d', strtotime('-1 day'));
    $to_date = date('Y-m-d', strtotime('-1 day'));
} elseif ($range === 'this_month') {
    $from_date = date('Y-m-01');
    $to_date = $today;
} elseif ($range === 'last_month') {
    $from_date = date('Y-m-01', strtotime('first day of last month'));
    $to_date = date('Y-m-t', strtotime('last day of last month'));
} elseif ($range === 'custom') {
    if ($from_date === '') {
        $from_date = $today;
    }

    if ($to_date === '') {
        $to_date = $today;
    }
}

if (strtotime($from_date) > strtotime($to_date)) {
    $tmp = $from_date;
    $from_date = $to_date;
    $to_date = $tmp;
}

$days = max(1, (strtotime($to_date) - strtotime($from_date)) / 86400 + 1);
$report_month_text = reportMonthText($from_date, $to_date);
$report_period_text = formatDateNice($from_date) . ' to ' . formatDateNice($to_date);
$range_labels = [
    'today' => 'Today',
    'yesterday' => 'Yesterday',
    'this_month' => 'This Month',
    'last_month' => 'Last Month',
    'custom' => 'Custom Date Range'
];
$range_label = $range_labels[$range] ?? 'Custom Date Range';

$distributors = $mysqli->query("
    SELECT distributor_id, distributor_code, distributor_name, mobile_number
    FROM distributors
    ORDER BY distributor_name ASC
");

$where = [];
$params = [];
$types = '';

$where[] = "m.purchase_date BETWEEN ? AND ?";
$params[] = $from_date;
$params[] = $to_date;
$types .= 'ss';

if ($status !== 'all') {
    $where[] = "m.status = ?";
    $params[] = $status;
    $types .= 's';
}

if ($distributor_id > 0) {
    $where[] = "m.distributor_id = ?";
    $params[] = $distributor_id;
    $types .= 'i';
}

if ($product_owner !== 'all') {
    $where[] = "p.product_owner = ?";
    $params[] = $product_owner;
    $types .= 's';
}

$where_sql = "WHERE " . implode(" AND ", $where);

/* Distributor Summary */
$summarySql = "
    SELECT 
        d.distributor_id,
        d.distributor_code,
        d.distributor_name,
        d.mobile_number,
        COUNT(DISTINCT m.purchase_id) AS total_requests,
        COALESCE(SUM(i.qty), 0) AS total_qty,
        COALESCE(SUM(i.amount), 0) AS total_sales,
        COALESCE(SUM(
            CASE 
                WHEN p.retail_price IS NOT NULL 
                AND p.wholesale_price IS NOT NULL
                THEN ((p.retail_price - p.wholesale_price) * i.qty)
                ELSE 0
            END
        ), 0) AS estimated_profit
    FROM distributor_purchase_master m
    INNER JOIN distributors d ON m.distributor_id = d.distributor_id
    LEFT JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
    LEFT JOIN products p ON i.product_id = p.product_id
    $where_sql
    GROUP BY d.distributor_id
    ORDER BY estimated_profit DESC, total_sales DESC
";

$stmt = $mysqli->prepare($summarySql);
if (!$stmt) {
    die("Summary query error: " . $mysqli->error);
}
$stmt->bind_param($types, ...$params);
$stmt->execute();
$summaryResult = $stmt->get_result();

$summaryRows = [];
$total_requests = 0;
$total_qty = 0;
$total_sales = 0;
$total_profit = 0;

while ($row = $summaryResult->fetch_assoc()) {
    $summaryRows[] = $row;
    $total_requests += (int)$row['total_requests'];
    $total_qty += (float)$row['total_qty'];
    $total_sales += (float)$row['total_sales'];
    $total_profit += (float)$row['estimated_profit'];
}
$stmt->close();

$total_margin_percent = ($total_sales > 0) ? (($total_profit / $total_sales) * 100) : 0;
$avg_order_value = ($total_requests > 0) ? ($total_sales / $total_requests) : 0;
$avg_daily_sales = $total_sales / $days;
$avg_daily_profit = $total_profit / $days;

/* Status Summary */
$statusWhere = [];
$statusParams = [];
$statusTypes = '';

$statusWhere[] = "m.purchase_date BETWEEN ? AND ?";
$statusParams[] = $from_date;
$statusParams[] = $to_date;
$statusTypes .= 'ss';

if ($distributor_id > 0) {
    $statusWhere[] = "m.distributor_id = ?";
    $statusParams[] = $distributor_id;
    $statusTypes .= 'i';
}

if ($product_owner !== 'all') {
    $statusWhere[] = "p.product_owner = ?";
    $statusParams[] = $product_owner;
    $statusTypes .= 's';
}

$status_where_sql = "WHERE " . implode(" AND ", $statusWhere);

$statusSummarySql = "
    SELECT 
        m.status,
        COUNT(DISTINCT m.purchase_id) AS total_requests,
        COALESCE(SUM(i.qty), 0) AS total_qty,
        COALESCE(SUM(i.amount), 0) AS total_sales
    FROM distributor_purchase_master m
    LEFT JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
    LEFT JOIN products p ON i.product_id = p.product_id
    $status_where_sql
    GROUP BY m.status
";

$statusSummary = [
    'draft' => ['requests' => 0, 'qty' => 0, 'sales' => 0],
    'ready' => ['requests' => 0, 'qty' => 0, 'sales' => 0],
    'dispatched' => ['requests' => 0, 'qty' => 0, 'sales' => 0],
];

$stmt = $mysqli->prepare($statusSummarySql);
if ($stmt) {
    $stmt->bind_param($statusTypes, ...$statusParams);
    $stmt->execute();
    $statusResult = $stmt->get_result();

    while ($row = $statusResult->fetch_assoc()) {
        $s = $row['status'];
        if (isset($statusSummary[$s])) {
            $statusSummary[$s] = [
                'requests' => (int)$row['total_requests'],
                'qty' => (float)$row['total_qty'],
                'sales' => (float)$row['total_sales'],
            ];
        }
    }
    $stmt->close();
}

/* Top Product Per Distributor */
$topProductMap = [];
$topProductSql = "
    SELECT 
        x.distributor_id,
        x.product_title,
        x.total_qty,
        x.total_sales
    FROM (
        SELECT
            m.distributor_id,
            i.product_title,
            SUM(i.qty) AS total_qty,
            SUM(i.amount) AS total_sales
        FROM distributor_purchase_master m
        LEFT JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
        LEFT JOIN products p ON i.product_id = p.product_id
        $where_sql
        GROUP BY m.distributor_id, i.product_id, i.product_title
    ) x
    INNER JOIN (
        SELECT 
            distributor_id,
            MAX(total_qty) AS max_qty
        FROM (
            SELECT
                m.distributor_id,
                i.product_id,
                SUM(i.qty) AS total_qty
            FROM distributor_purchase_master m
            LEFT JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
            LEFT JOIN products p ON i.product_id = p.product_id
            $where_sql
            GROUP BY m.distributor_id, i.product_id
        ) y
        GROUP BY distributor_id
    ) z ON x.distributor_id = z.distributor_id AND x.total_qty = z.max_qty
";

$topParams = array_merge($params, $params);
$topTypes = $types . $types;
$stmt = $mysqli->prepare($topProductSql);
if ($stmt) {
    $stmt->bind_param($topTypes, ...$topParams);
    $stmt->execute();
    $topResult = $stmt->get_result();

    while ($row = $topResult->fetch_assoc()) {
        if (!isset($topProductMap[$row['distributor_id']])) {
            $topProductMap[$row['distributor_id']] = $row;
        }
    }
    $stmt->close();
}

/* Overall Top Products */
$overallProductRows = [];
$overallProductSql = "
    SELECT
        i.product_id,
        i.product_title,
        COALESCE(SUM(i.qty), 0) AS total_qty,
        COALESCE(SUM(i.amount), 0) AS total_sales,
        COALESCE(SUM(
            CASE 
                WHEN p.retail_price IS NOT NULL 
                AND p.wholesale_price IS NOT NULL
                THEN ((p.retail_price - p.wholesale_price) * i.qty)
                ELSE 0
            END
        ), 0) AS estimated_profit
    FROM distributor_purchase_master m
    INNER JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
    LEFT JOIN products p ON i.product_id = p.product_id
    $where_sql
    GROUP BY i.product_id, i.product_title
    ORDER BY total_qty DESC, total_sales DESC
    LIMIT 10
";

$stmt = $mysqli->prepare($overallProductSql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $overallProductResult = $stmt->get_result();

    while ($row = $overallProductResult->fetch_assoc()) {
        $overallProductRows[] = $row;
    }
    $stmt->close();
}

/* Overall Monthly Data */
$overallMonthlyRows = [];
$overallMonthlySql = "
    SELECT
        DATE_FORMAT(m.purchase_date, '%Y-%m') AS month_key,
        DATE_FORMAT(m.purchase_date, '%M %Y') AS month_name,
        COUNT(DISTINCT m.purchase_id) AS total_requests,
        COALESCE(SUM(i.qty), 0) AS total_qty,
        COALESCE(SUM(i.amount), 0) AS total_sales,
        COALESCE(SUM(
            CASE 
                WHEN p.retail_price IS NOT NULL 
                AND p.wholesale_price IS NOT NULL
                THEN ((p.retail_price - p.wholesale_price) * i.qty)
                ELSE 0
            END
        ), 0) AS estimated_profit
    FROM distributor_purchase_master m
    LEFT JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
    LEFT JOIN products p ON i.product_id = p.product_id
    $where_sql
    GROUP BY month_key, month_name
    ORDER BY month_key ASC
";

$stmt = $mysqli->prepare($overallMonthlySql);
if ($stmt) {
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $overallMonthlyResult = $stmt->get_result();

    while ($row = $overallMonthlyResult->fetch_assoc()) {
        $overallMonthlyRows[] = $row;
    }
    $stmt->close();
}

/* Distributor Detail */
$selectedDistributor = null;
$productRows = [];
$monthlyRows = [];
$purchaseRows = [];

if ($distributor_id > 0) {
    $stmt = $mysqli->prepare("
        SELECT distributor_id, distributor_code, distributor_name, mobile_number, email, address, pincode
        FROM distributors
        WHERE distributor_id = ?
    ");

    if (!$stmt) {
        die("Distributor query error: " . $mysqli->error);
    }

    $stmt->bind_param("i", $distributor_id);
    $stmt->execute();
    $selectedDistributor = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $productSql = "
        SELECT
            i.product_id,
            i.product_title,
            COALESCE(SUM(i.qty), 0) AS total_qty,
            COALESCE(SUM(i.amount), 0) AS total_sales,
            COUNT(DISTINCT m.purchase_id) AS request_count,
            COALESCE(AVG(i.rate), 0) AS avg_rate,
            COALESCE(AVG(p.wholesale_price), 0) AS avg_wholesale_price,
            COALESCE(AVG(p.retail_price), 0) AS avg_retail_price,
            COALESCE(AVG(p.retail_price - p.wholesale_price), 0) AS profit_per_piece,
            COALESCE(SUM(
                CASE 
                    WHEN p.retail_price IS NOT NULL 
                    AND p.wholesale_price IS NOT NULL
                    THEN ((p.retail_price - p.wholesale_price) * i.qty)
                    ELSE 0
                END
            ), 0) AS estimated_profit
        FROM distributor_purchase_master m
        INNER JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
        LEFT JOIN products p ON i.product_id = p.product_id
        $where_sql
        GROUP BY i.product_id, i.product_title
        ORDER BY total_qty DESC, total_sales DESC
    ";

    $stmt = $mysqli->prepare($productSql);
    if (!$stmt) {
        die("Product report query error: " . $mysqli->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $productResult = $stmt->get_result();

    while ($row = $productResult->fetch_assoc()) {
        $productRows[] = $row;
    }
    $stmt->close();

    $monthlySql = "
        SELECT
            DATE_FORMAT(m.purchase_date, '%Y-%m') AS month_key,
            DATE_FORMAT(m.purchase_date, '%M %Y') AS month_name,
            COUNT(DISTINCT m.purchase_id) AS total_requests,
            COALESCE(SUM(i.qty), 0) AS total_qty,
            COALESCE(SUM(i.amount), 0) AS total_sales,
            COALESCE(SUM(
                CASE 
                    WHEN p.retail_price IS NOT NULL 
                    AND p.wholesale_price IS NOT NULL
                    THEN ((p.retail_price - p.wholesale_price) * i.qty)
                    ELSE 0
                END
            ), 0) AS estimated_profit
        FROM distributor_purchase_master m
        LEFT JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
        LEFT JOIN products p ON i.product_id = p.product_id
        $where_sql
        GROUP BY month_key, month_name
        ORDER BY month_key ASC
    ";

    $stmt = $mysqli->prepare($monthlySql);
    if (!$stmt) {
        die("Monthly query error: " . $mysqli->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $monthlyResult = $stmt->get_result();

    while ($row = $monthlyResult->fetch_assoc()) {
        $monthlyRows[] = $row;
    }
    $stmt->close();

    $purchaseSql = "
        SELECT DISTINCT
            m.purchase_id,
            m.purchase_no,
            m.purchase_date,
            m.status,
            m.total_qty,
            m.total_amount,
            m.remarks,
            m.created_at
        FROM distributor_purchase_master m
        LEFT JOIN distributor_purchase_items i ON m.purchase_id = i.purchase_id
        LEFT JOIN products p ON i.product_id = p.product_id
        $where_sql
        ORDER BY m.purchase_date DESC, m.purchase_id DESC
    ";

    $stmt = $mysqli->prepare($purchaseSql);
    if (!$stmt) {
        die("Purchase request query error: " . $mysqli->error);
    }

    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $purchaseResult = $stmt->get_result();

    while ($row = $purchaseResult->fetch_assoc()) {
        $purchaseRows[] = $row;
    }
    $stmt->close();
}

/* Chart Data */
$chartDistributorNames = [];
$chartDistributorProfit = [];
$chartDistributorSales = [];
$chartDistributorMargin = [];

foreach (array_slice($summaryRows, 0, 10) as $row) {
    $sales = (float)$row['total_sales'];
    $profit = (float)$row['estimated_profit'];

    $chartDistributorNames[] = $row['distributor_name'];
    $chartDistributorProfit[] = round($profit, 2);
    $chartDistributorSales[] = round($sales, 2);
    $chartDistributorMargin[] = $sales > 0 ? round(($profit / $sales) * 100, 2) : 0;
}

$chartOverallProductNames = [];
$chartOverallProductQty = [];
$chartOverallProductProfit = [];

foreach ($overallProductRows as $row) {
    $chartOverallProductNames[] = $row['product_title'];
    $chartOverallProductQty[] = round((float)$row['total_qty'], 2);
    $chartOverallProductProfit[] = round((float)$row['estimated_profit'], 2);
}

$chartOverallMonthNames = [];
$chartOverallMonthSales = [];
$chartOverallMonthProfit = [];

foreach ($overallMonthlyRows as $row) {
    $chartOverallMonthNames[] = $row['month_name'];
    $chartOverallMonthSales[] = round((float)$row['total_sales'], 2);
    $chartOverallMonthProfit[] = round((float)$row['estimated_profit'], 2);
}

$chartStatusLabels = ['Draft', 'Ready', 'Dispatched'];
$chartStatusRequests = [
    $statusSummary['draft']['requests'],
    $statusSummary['ready']['requests'],
    $statusSummary['dispatched']['requests'],
];

$chartProductNames = [];
$chartProductQty = [];
$chartProductProfit = [];

foreach (array_slice($productRows, 0, 10) as $row) {
    $chartProductNames[] = $row['product_title'];
    $chartProductQty[] = round((float)$row['total_qty'], 2);
    $chartProductProfit[] = round((float)$row['estimated_profit'], 2);
}

$chartMonthNames = [];
$chartMonthSales = [];
$chartMonthProfit = [];

foreach ($monthlyRows as $row) {
    $chartMonthNames[] = $row['month_name'];
    $chartMonthSales[] = round((float)$row['total_sales'], 2);
    $chartMonthProfit[] = round((float)$row['estimated_profit'], 2);
}

$queryForPrint = $_GET;
$queryForPrint['print'] = 1;
$printUrl = 'distributor_report.php?' . http_build_query($queryForPrint);

$report_for_text = ($distributor_id > 0 && $selectedDistributor)
    ? $selectedDistributor['distributor_name']
    : 'All Distributors';
?>

<style>
body {
    background: #f5f6fa;
}

.page-wrap {
    padding: 18px;
}

.page-title-box,
.sa-card,
.summary-box,
.chart-box,
.pdf-report-header {
    background: #fff;
    border-radius: 14px;
    box-shadow: 0 4px 14px rgba(0,0,0,0.06);
}

.page-title-box {
    padding: 16px 18px;
    margin-bottom: 18px;
}

.page-title-box h4 {
    font-size: 20px;
    font-weight: 700;
    margin: 0;
}

.sa-card {
    overflow: hidden;
    margin-bottom: 20px;
}

.sa-card-header {
    padding: 15px 18px;
    border-bottom: 1px solid #eef0f4;
    background: #fff;
    font-weight: 700;
}

.sa-card-body {
    padding: 18px;
}

.form-label {
    font-size: 13px;
    font-weight: 600;
    color: #444;
}

.form-control,
.form-select {
    border-radius: 10px;
    min-height: 42px;
    font-size: 14px;
}

.btn {
    border-radius: 10px;
    font-weight: 600;
}

.pdf-report-header {
    padding: 18px;
    margin-bottom: 18px;
    border: 1px solid #eef2f7;
}

.pdf-brand-row {
    display: flex;
    justify-content: space-between;
    gap: 16px;
    align-items: flex-start;
    border-bottom: 1px solid #e5e7eb;
    padding-bottom: 12px;
    margin-bottom: 12px;
}

.pdf-brand-title {
    font-size: 22px;
    font-weight: 900;
    color: #111827;
    letter-spacing: .4px;
}

.pdf-brand-subtitle {
    font-size: 13px;
    color: #6b7280;
    margin-top: 3px;
}

.pdf-meta-grid {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
}

.pdf-meta-item {
    background: #f8fafc;
    border: 1px solid #edf2f7;
    border-radius: 12px;
    padding: 10px 12px;
}

.pdf-meta-label {
    font-size: 11px;
    color: #6b7280;
    text-transform: uppercase;
    font-weight: 800;
    letter-spacing: .35px;
}

.pdf-meta-value {
    font-size: 14px;
    color: #111827;
    font-weight: 800;
    margin-top: 3px;
}

.summary-grid {
    display: grid;
    grid-template-columns: repeat(6, 1fr);
    gap: 14px;
    margin-bottom: 18px;
}

.summary-box {
    padding: 15px;
}

.summary-label {
    font-size: 12px;
    color: #6b7280;
    font-weight: 600;
}

.summary-value {
    font-size: 20px;
    font-weight: 800;
    color: #111827;
    margin-top: 4px;
}

.chart-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 18px;
    margin-bottom: 20px;
}

.chart-grid-3 {
    display: grid;
    grid-template-columns: 2fr 2fr 1.3fr;
    gap: 18px;
    margin-bottom: 20px;
}

.chart-box {
    background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
    border: 1px solid #eef2f7;
    padding: 18px;
}

.chart-box h6 {
    font-size: 15px;
    font-weight: 700;
    margin-bottom: 14px;
    color: #0f172a;
}

.chart-canvas-wrap {
    position: relative;
    height: 320px;
}

.chart-canvas-small {
    height: 260px;
}

.table thead th {
    font-size: 13px;
    white-space: nowrap;
}

.table td {
    font-size: 14px;
    vertical-align: middle;
}

.badge-soft {
    border-radius: 999px;
    padding: 5px 9px;
    font-size: 12px;
    font-weight: 700;
}

.badge-draft { background: #f3f4f6; color: #374151; }
.badge-ready { background: #fff7ed; color: #c2410c; }
.badge-dispatched { background: #ecfdf5; color: #047857; }

.rank-badge {
    display: inline-flex;
    min-width: 34px;
    height: 34px;
    align-items: center;
    justify-content: center;
    background: #111827;
    color: #fff;
    border-radius: 999px;
    font-size: 13px;
    font-weight: 800;
}

.rank-1 { background: #f59e0b; }
.rank-2 { background: #6b7280; }
.rank-3 { background: #b45309; }

.performance-good { color: #047857; font-weight: 700; }
.performance-average { color: #c2410c; font-weight: 700; }
.performance-low { color: #b91c1c; font-weight: 700; }
.profit-positive { color: #047857; font-weight: 700; }
.profit-zero { color: #6b7280; font-weight: 700; }
.top-product-text { font-weight: 700; color: #111827; }
.print-only { display: none; }

@media (max-width: 1200px) {
    .chart-grid-3 { grid-template-columns: 1fr; }
    .summary-grid { grid-template-columns: repeat(3, 1fr); }
    .pdf-meta-grid { grid-template-columns: repeat(2, 1fr); }
}

@media (max-width: 992px) {
    .summary-grid { grid-template-columns: repeat(2, 1fr); }
    .chart-grid { grid-template-columns: 1fr; }
}

@media (max-width: 768px) {
    .page-wrap { padding: 12px; }
    .summary-grid { grid-template-columns: 1fr; }
    .pdf-brand-row { flex-direction: column; }
    .pdf-meta-grid { grid-template-columns: 1fr; }
    .table { min-width: 1250px; }
    .btn-mobile-full { width: 100%; margin-bottom: 8px; }
    .chart-canvas-wrap { height: 280px; }
    .chart-canvas-small { height: 240px; }
}

@media print {
    @page {
        size: A4 landscape;
        margin: 8mm;
    }

    html,
    body {
        background: #ffffff !important;
        -webkit-print-color-adjust: exact !important;
        print-color-adjust: exact !important;
    }

    body * {
        visibility: hidden;
    }

    #pdfReportArea,
    #pdfReportArea * {
        visibility: visible;
    }

    #pdfReportArea {
        position: absolute;
        left: 0;
        top: 0;
        width: 100%;
        padding: 0 !important;
    }

    .no-print,
    .sa-sidebar,
    .sidebar,
    .navbar,
    .topbar,
    .main-header,
    .main-sidebar,
    .footer,
    footer,
    .page-title-box.no-print,
    .filter-card {
        display: none !important;
    }

    .print-only {
        display: block !important;
    }

    .page-wrap {
        padding: 0 !important;
    }

    .pdf-report-header,
    .summary-box,
    .chart-box,
    .sa-card,
    .page-title-box {
        box-shadow: none !important;
        border: 1px solid #d9dee8 !important;
        page-break-inside: avoid;
    }

    .pdf-report-header,
    .summary-grid,
    .chart-grid,
    .chart-grid-3,
    .sa-card,
    .page-title-box {
        margin-bottom: 10px !important;
    }

    .summary-grid {
        grid-template-columns: repeat(6, 1fr) !important;
        gap: 8px !important;
    }

    .summary-box {
        padding: 9px !important;
    }

    .summary-label {
        font-size: 9px !important;
    }

    .summary-value {
        font-size: 13px !important;
    }

    .chart-grid-3 {
        grid-template-columns: 1.5fr 1.5fr 1fr !important;
        gap: 10px !important;
    }

    .chart-grid {
        grid-template-columns: 1fr 1fr !important;
        gap: 10px !important;
    }

    .chart-box {
        padding: 10px !important;
    }

    .chart-box h6 {
        font-size: 11px !important;
        margin-bottom: 6px !important;
    }

    .chart-canvas-wrap {
        height: 205px !important;
    }

    .chart-canvas-small {
        height: 205px !important;
    }

    canvas {
        max-width: 100% !important;
    }

    .sa-card-header {
        padding: 8px 10px !important;
        font-size: 12px !important;
    }

    .sa-card-body {
        padding: 8px !important;
    }

    .table {
        width: 100% !important;
        min-width: 0 !important;
        margin-bottom: 0 !important;
    }

    .table th,
    .table td {
        font-size: 9px !important;
        padding: 4px 5px !important;
        white-space: normal !important;
    }

    .rank-badge {
        min-width: 22px !important;
        height: 22px !important;
        font-size: 9px !important;
    }

    .pdf-brand-title {
        font-size: 18px !important;
    }

    .pdf-brand-subtitle,
    .pdf-meta-value {
        font-size: 10px !important;
    }

    .pdf-meta-label {
        font-size: 8px !important;
    }

    .pdf-meta-grid {
        grid-template-columns: repeat(4, 1fr) !important;
        gap: 6px !important;
    }

    .pdf-meta-item {
        padding: 7px !important;
    }

    .page-break-before {
        page-break-before: always;
    }
}
</style>

<div class="container-fluid page-wrap">

    <div class="page-title-box no-print">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center">
            <div>
                <h4>📊 Distributor Report</h4>
                <small class="text-muted">
                    Distributor ranking, sales, retail profit, product performance, monthly analysis and PDF printout.
                </small>
            </div>

            <div class="mt-2 mt-md-0 d-flex flex-column flex-md-row gap-2">
                <button type="button" class="btn btn-success btn-sm btn-mobile-full" onclick="printDistributorReport()">
                    🖨️ Print / Save PDF
                </button>

                <a href="<?= cleanText($printUrl); ?>" target="_blank" class="btn btn-outline-success btn-sm btn-mobile-full">
                    📄 Open PDF View
                </a>

                <a href="distributor_report.php" class="btn btn-secondary btn-sm btn-mobile-full">
                    Reset Report
                </a>
            </div>
        </div>
    </div>

    <div class="sa-card filter-card no-print">
        <div class="sa-card-header">Filter Report</div>
        <div class="sa-card-body">
            <form method="GET" class="row g-3 align-items-end">

                <div class="col-md-2">
                    <label class="form-label">Date Range</label>
                    <select name="range" id="range" class="form-select" onchange="toggleCustomDate()">
                        <option value="today" <?= ($range === 'today') ? 'selected' : ''; ?>>Today</option>
                        <option value="yesterday" <?= ($range === 'yesterday') ? 'selected' : ''; ?>>Yesterday</option>
                        <option value="this_month" <?= ($range === 'this_month') ? 'selected' : ''; ?>>This Month</option>
                        <option value="last_month" <?= ($range === 'last_month') ? 'selected' : ''; ?>>Last Month</option>
                        <option value="custom" <?= ($range === 'custom') ? 'selected' : ''; ?>>Custom</option>
                    </select>
                </div>

                <div class="col-md-2 custom-date-box">
                    <label class="form-label">From Date</label>
                    <input type="date" name="from_date" class="form-control" value="<?= cleanText($from_date); ?>">
                </div>

                <div class="col-md-2 custom-date-box">
                    <label class="form-label">To Date</label>
                    <input type="date" name="to_date" class="form-control" value="<?= cleanText($to_date); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">Product Owner</label>
                    <select name="product_owner" class="form-select">
                        <option value="all" <?= ($product_owner === 'all') ? 'selected' : ''; ?>>All</option>
                        <option value="SATHEE" <?= ($product_owner === 'SATHEE') ? 'selected' : ''; ?>>SATHEE</option>
                        <option value="CMD" <?= ($product_owner === 'CMD') ? 'selected' : ''; ?>>CMD</option>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Distributor</label>
                    <select name="distributor_id" class="form-select">
                        <option value="0">All Distributors</option>
                        <?php while ($d = $distributors->fetch_assoc()): ?>
                            <option value="<?= intval($d['distributor_id']); ?>" <?= ($distributor_id === intval($d['distributor_id'])) ? 'selected' : ''; ?>>
                                <?= cleanText($d['distributor_name']); ?><?= !empty($d['distributor_code']) ? ' - ' . cleanText($d['distributor_code']) : ''; ?>
                            </option>
                        <?php endwhile; ?>
                    </select>
                </div>

                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="all" <?= ($status === 'all') ? 'selected' : ''; ?>>All</option>
                        <option value="draft" <?= ($status === 'draft') ? 'selected' : ''; ?>>Draft</option>
                        <option value="ready" <?= ($status === 'ready') ? 'selected' : ''; ?>>Ready</option>
                        <option value="dispatched" <?= ($status === 'dispatched') ? 'selected' : ''; ?>>Dispatched</option>
                    </select>
                </div>

                <div class="col-12 d-flex flex-column flex-md-row gap-2">
                    <button type="submit" class="btn btn-primary btn-mobile-full">Apply Filter</button>
                    <a href="distributor_report.php" class="btn btn-light border btn-mobile-full">Reset</a>
                    <button type="button" class="btn btn-success btn-mobile-full" onclick="printDistributorReport()">Print / Save PDF</button>
                </div>
            </form>
        </div>
    </div>

    <div id="pdfReportArea">
        <div class="pdf-report-header">
            <div class="pdf-brand-row">
                <div>
                    <div class="pdf-brand-title">SATHEE CRM - Distributor Report</div>
                    <div class="pdf-brand-subtitle">
                        Filtered sales, wholesale value, estimated retail profit, monthly and product performance.
                    </div>
                </div>
                <div class="text-md-end">
                    <div class="pdf-brand-subtitle">Generated On</div>
                    <div class="pdf-meta-value"><?= date('d M Y, h:i A'); ?></div>
                </div>
            </div>

            <div class="pdf-meta-grid">
                <div class="pdf-meta-item">
                    <div class="pdf-meta-label">Report For</div>
                    <div class="pdf-meta-value"><?= cleanText($report_for_text); ?></div>
                </div>
                <div class="pdf-meta-item">
                    <div class="pdf-meta-label">Month Data</div>
                    <div class="pdf-meta-value"><?= cleanText($report_month_text); ?></div>
                </div>
                <div class="pdf-meta-item">
                    <div class="pdf-meta-label">Date Range</div>
                    <div class="pdf-meta-value"><?= cleanText($report_period_text); ?></div>
                </div>
                <div class="pdf-meta-item">
                    <div class="pdf-meta-label">Range Type</div>
                    <div class="pdf-meta-value"><?= cleanText($range_label); ?></div>
                </div>
                <div class="pdf-meta-item">
                    <div class="pdf-meta-label">Status</div>
                    <div class="pdf-meta-value"><?= cleanText(ucfirst($status)); ?></div>
                </div>
                <div class="pdf-meta-item">
                    <div class="pdf-meta-label">Product Owner</div>
                    <div class="pdf-meta-value"><?= cleanText($product_owner === 'all' ? 'All' : $product_owner); ?></div>
                </div>
                <div class="pdf-meta-item">
                    <div class="pdf-meta-label">Total Days</div>
                    <div class="pdf-meta-value"><?= number_format($days); ?> Day(s)</div>
                </div>
                <div class="pdf-meta-item">
                    <div class="pdf-meta-label">Report Type</div>
                    <div class="pdf-meta-value"><?= ($distributor_id > 0) ? 'Single Distributor' : 'All Distributors'; ?></div>
                </div>
            </div>
        </div>

        <div class="summary-grid">
            <div class="summary-box">
                <div class="summary-label">Total Requests</div>
                <div class="summary-value"><?= number_format($total_requests); ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Total Qty</div>
                <div class="summary-value"><?= number_format($total_qty); ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Wholesale Sales</div>
                <div class="summary-value"><?= money($total_sales); ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Retail Profit</div>
                <div class="summary-value"><?= money($total_profit); ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Profit Margin</div>
                <div class="summary-value"><?= percentVal($total_margin_percent); ?></div>
            </div>
            <div class="summary-box">
                <div class="summary-label">Avg Order Value</div>
                <div class="summary-value"><?= money($avg_order_value); ?></div>
            </div>
        </div>

        <div class="chart-grid-3">
            <div class="chart-box">
                <h6>🏆 Top 10 Distributors by Retail Profit</h6>
                <div class="chart-canvas-wrap"><canvas id="distributorProfitChart"></canvas></div>
            </div>
            <div class="chart-box">
                <h6>📈 Wholesale Sales vs Retail Profit</h6>
                <div class="chart-canvas-wrap"><canvas id="distributorSalesProfitChart"></canvas></div>
            </div>
            <div class="chart-box">
                <h6>📌 Request Status</h6>
                <div class="chart-canvas-wrap chart-canvas-small"><canvas id="statusPieChart"></canvas></div>
            </div>
        </div>

        <div class="chart-grid">
            <div class="chart-box">
                <h6>📦 Top 10 Products by Quantity</h6>
                <div class="chart-canvas-wrap"><canvas id="overallProductQtyChart"></canvas></div>
            </div>
            <div class="chart-box">
                <h6>📅 Monthly Sales vs Retail Profit</h6>
                <div class="chart-canvas-wrap"><canvas id="overallMonthlyChart"></canvas></div>
            </div>
        </div>

        <div class="sa-card">
            <div class="sa-card-header">Distributor Summary</div>
            <div class="sa-card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-hover align-middle">
                        <thead class="table-light">
                            <tr>
                                <th>Rank</th>
                                <th>Distributor</th>
                                <th>Mobile</th>
                                <th>Top Product</th>
                                <th>Requests</th>
                                <th>Total Qty</th>
                                <th>Wholesale Sales</th>
                                <th>Retail Profit</th>
                                <th>Margin %</th>
                                <th>Avg Order Value</th>
                                <th>Avg Daily Profit</th>
                                <th>Performance</th>
                                <th class="no-print">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($summaryRows)): ?>
                                <?php $rank = 1; foreach ($summaryRows as $row): ?>
                                    <?php
                                    $dist_sales = (float)$row['total_sales'];
                                    $dist_profit = (float)$row['estimated_profit'];
                                    $dist_requests = (int)$row['total_requests'];
                                    $dist_margin = ($dist_sales > 0) ? (($dist_profit / $dist_sales) * 100) : 0;
                                    $dist_aov = ($dist_requests > 0) ? ($dist_sales / $dist_requests) : 0;
                                    $dist_avg_daily_profit = $dist_profit / $days;

                                    if ($dist_avg_daily_profit >= 1000) {
                                        $performance = '<span class="performance-good">Good</span>';
                                    } elseif ($dist_avg_daily_profit >= 300) {
                                        $performance = '<span class="performance-average">Average</span>';
                                    } else {
                                        $performance = '<span class="performance-low">Low</span>';
                                    }

                                    $rankClass = '';
                                    if ($rank == 1) $rankClass = 'rank-1';
                                    if ($rank == 2) $rankClass = 'rank-2';
                                    if ($rank == 3) $rankClass = 'rank-3';

                                    $topProduct = $topProductMap[$row['distributor_id']] ?? null;
                                    ?>
                                    <tr>
                                        <td><span class="rank-badge <?= $rankClass; ?>">#<?= $rank; ?></span></td>
                                        <td>
                                            <strong><?= cleanText($row['distributor_name']); ?></strong><br>
                                            <small class="text-muted"><?= cleanText($row['distributor_code'] ?? '-'); ?></small>
                                        </td>
                                        <td><?= cleanText($row['mobile_number'] ?? '-'); ?></td>
                                        <td>
                                            <?php if ($topProduct): ?>
                                                <span class="top-product-text"><?= cleanText($topProduct['product_title']); ?></span><br>
                                                <small class="text-muted">Qty: <?= number_format($topProduct['total_qty']); ?></small>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= number_format($row['total_requests']); ?></td>
                                        <td><?= number_format($row['total_qty']); ?></td>
                                        <td><strong><?= money($dist_sales); ?></strong></td>
                                        <td><span class="<?= ($dist_profit > 0) ? 'profit-positive' : 'profit-zero'; ?>"><?= money($dist_profit); ?></span></td>
                                        <td><?= percentVal($dist_margin); ?></td>
                                        <td><?= money($dist_aov); ?></td>
                                        <td><?= money($dist_avg_daily_profit); ?></td>
                                        <td><?= $performance; ?></td>
                                        <td class="no-print">
                                            <a href="distributor_report.php?range=<?= urlencode($range); ?>&from_date=<?= urlencode($from_date); ?>&to_date=<?= urlencode($to_date); ?>&status=<?= urlencode($status); ?>&product_owner=<?= urlencode($product_owner); ?>&distributor_id=<?= intval($row['distributor_id']); ?>" class="btn btn-sm btn-primary">View Detail</a>
                                        </td>
                                    </tr>
                                    <?php $rank++; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <tr><td colspan="13" class="text-center text-muted">No distributor purchase data found for selected filter.</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <?php if ($distributor_id > 0 && $selectedDistributor): ?>
            <div class="page-title-box">
                <h4>👤 Distributor Detail: <?= cleanText($selectedDistributor['distributor_name']); ?></h4>
                <small class="text-muted">
                    Mobile: <?= cleanText($selectedDistributor['mobile_number'] ?? '-'); ?> |
                    Period: <?= cleanText($report_period_text); ?> |
                    Month Data: <?= cleanText($report_month_text); ?> |
                    Product Owner: <?= cleanText($product_owner); ?>
                </small>
            </div>

            <div class="chart-grid">
                <div class="chart-box">
                    <h6>📦 Distributor Top Products by Quantity</h6>
                    <div class="chart-canvas-wrap"><canvas id="productQtyChart"></canvas></div>
                </div>
                <div class="chart-box">
                    <h6>💰 Distributor Product-wise Profit</h6>
                    <div class="chart-canvas-wrap"><canvas id="productProfitChart"></canvas></div>
                </div>
            </div>

            <div class="chart-grid">
                <div class="chart-box">
                    <h6>📅 Distributor Monthly Sales vs Profit</h6>
                    <div class="chart-canvas-wrap"><canvas id="monthlySalesProfitChart"></canvas></div>
                </div>
                <div class="chart-box">
                    <h6>📊 Product Qty vs Profit</h6>
                    <div class="chart-canvas-wrap"><canvas id="productQtyProfitChart"></canvas></div>
                </div>
            </div>

            <div class="sa-card">
                <div class="sa-card-header">Product-wise Performance</div>
                <div class="sa-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Product</th>
                                    <th>Requests</th>
                                    <th>Total Qty</th>
                                    <th>Distributor Rate</th>
                                    <th>Wholesale Price</th>
                                    <th>Retail Price</th>
                                    <th>Profit/Pcs</th>
                                    <th>Wholesale Sales</th>
                                    <th>Retail Profit</th>
                                    <th>Margin %</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($productRows)): ?>
                                    <?php $p = 1; foreach ($productRows as $row): ?>
                                        <?php
                                        $product_sales = (float)$row['total_sales'];
                                        $product_profit = (float)$row['estimated_profit'];
                                        $product_margin = ($product_sales > 0) ? (($product_profit / $product_sales) * 100) : 0;
                                        ?>
                                        <tr>
                                            <td><?= $p++; ?></td>
                                            <td><strong><?= cleanText($row['product_title']); ?></strong></td>
                                            <td><?= number_format($row['request_count']); ?></td>
                                            <td><?= number_format($row['total_qty']); ?></td>
                                            <td><?= money($row['avg_rate']); ?></td>
                                            <td><?= money($row['avg_wholesale_price']); ?></td>
                                            <td><?= money($row['avg_retail_price']); ?></td>
                                            <td><span class="<?= ((float)$row['profit_per_piece'] > 0) ? 'profit-positive' : 'profit-zero'; ?>"><?= money($row['profit_per_piece']); ?></span></td>
                                            <td><strong><?= money($product_sales); ?></strong></td>
                                            <td><span class="<?= ($product_profit > 0) ? 'profit-positive' : 'profit-zero'; ?>"><?= money($product_profit); ?></span></td>
                                            <td><?= percentVal($product_margin); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="11" class="text-center text-muted">No product data found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="sa-card">
                <div class="sa-card-header">Monthly Income / Sales</div>
                <div class="sa-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Month</th>
                                    <th>Requests</th>
                                    <th>Total Qty</th>
                                    <th>Wholesale Sales</th>
                                    <th>Retail Profit</th>
                                    <th>Margin %</th>
                                    <th>Avg Order Value</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($monthlyRows)): ?>
                                    <?php $m = 1; foreach ($monthlyRows as $row): ?>
                                        <?php
                                        $month_sales = (float)$row['total_sales'];
                                        $month_profit = (float)$row['estimated_profit'];
                                        $month_requests = (int)$row['total_requests'];
                                        $month_margin = ($month_sales > 0) ? (($month_profit / $month_sales) * 100) : 0;
                                        $month_aov = ($month_requests > 0) ? ($month_sales / $month_requests) : 0;
                                        ?>
                                        <tr>
                                            <td><?= $m++; ?></td>
                                            <td><strong><?= cleanText($row['month_name']); ?></strong></td>
                                            <td><?= number_format($month_requests); ?></td>
                                            <td><?= number_format($row['total_qty']); ?></td>
                                            <td><strong><?= money($month_sales); ?></strong></td>
                                            <td><span class="<?= ($month_profit > 0) ? 'profit-positive' : 'profit-zero'; ?>"><?= money($month_profit); ?></span></td>
                                            <td><?= percentVal($month_margin); ?></td>
                                            <td><?= money($month_aov); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="8" class="text-center text-muted">No monthly data found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="sa-card">
                <div class="sa-card-header">Purchase Request List</div>
                <div class="sa-card-body">
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>#</th>
                                    <th>Purchase No</th>
                                    <th>Date</th>
                                    <th>Status</th>
                                    <th>Total Qty</th>
                                    <th>Wholesale Amount</th>
                                    <th>Remarks</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (!empty($purchaseRows)): ?>
                                    <?php $r = 1; foreach ($purchaseRows as $row): ?>
                                        <?php
                                        $badgeClass = 'badge-draft';
                                        if ($row['status'] === 'ready') $badgeClass = 'badge-ready';
                                        elseif ($row['status'] === 'dispatched') $badgeClass = 'badge-dispatched';
                                        ?>
                                        <tr>
                                            <td><?= $r++; ?></td>
                                            <td><strong><?= cleanText($row['purchase_no']); ?></strong></td>
                                            <td><?= date('d M Y', strtotime($row['purchase_date'])); ?></td>
                                            <td><span class="badge-soft <?= $badgeClass; ?>"><?= ucfirst(cleanText($row['status'])); ?></span></td>
                                            <td><?= number_format($row['total_qty']); ?></td>
                                            <td><strong><?= money($row['total_amount']); ?></strong></td>
                                            <td><?= cleanText($row['remarks'] ?? '-'); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <tr><td colspan="7" class="text-center text-muted">No purchase request found.</td></tr>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<script>
let reportCharts = [];
const autoPrintMode = <?= $print_mode ? 'true' : 'false'; ?>;

function toggleCustomDate() {
    const range = document.getElementById('range');
    if (!range) return;

    const boxes = document.querySelectorAll('.custom-date-box');
    boxes.forEach(function(box) {
        box.style.display = (range.value === 'custom') ? 'block' : 'none';
    });
}

function rupeeLabel(value) {
    return '₹' + Number(value).toLocaleString('en-IN');
}

function registerChart(ctx, config) {
    if (!ctx) return null;

    if (!config.options) config.options = {};
    config.options.responsive = true;
    config.options.maintainAspectRatio = false;
    config.options.animation = false;

    const chart = new Chart(ctx, config);
    reportCharts.push(chart);
    return chart;
}

function resizeChartsForPrint() {
    reportCharts.forEach(function(chart) {
        try {
            chart.resize();
            chart.update('none');
        } catch (e) {}
    });
}

function printDistributorReport() {
    resizeChartsForPrint();
    setTimeout(function() {
        window.print();
    }, 450);
}

window.addEventListener('beforeprint', resizeChartsForPrint);
window.addEventListener('afterprint', function() {
    setTimeout(resizeChartsForPrint, 300);
});

const chartColors = {
    blue: 'rgba(37, 99, 235, 0.85)',
    blueLight: 'rgba(37, 99, 235, 0.18)',
    green: 'rgba(5, 150, 105, 0.85)',
    greenLight: 'rgba(5, 150, 105, 0.18)',
    orange: 'rgba(245, 158, 11, 0.85)',
    orangeLight: 'rgba(245, 158, 11, 0.18)',
    red: 'rgba(220, 38, 38, 0.85)',
    redLight: 'rgba(220, 38, 38, 0.18)',
    purple: 'rgba(124, 58, 237, 0.85)',
    purpleLight: 'rgba(124, 58, 237, 0.18)',
    sky: 'rgba(14, 165, 233, 0.85)',
    pink: 'rgba(236, 72, 153, 0.85)',
    slate: 'rgba(15, 23, 42, 0.85)',
    slateLight: 'rgba(15, 23, 42, 0.15)'
};

const chartPalette = [
    'rgba(37, 99, 235, 0.85)',
    'rgba(5, 150, 105, 0.85)',
    'rgba(245, 158, 11, 0.85)',
    'rgba(220, 38, 38, 0.85)',
    'rgba(124, 58, 237, 0.85)',
    'rgba(14, 165, 233, 0.85)',
    'rgba(236, 72, 153, 0.85)',
    'rgba(22, 163, 74, 0.85)',
    'rgba(234, 88, 12, 0.85)',
    'rgba(15, 23, 42, 0.85)'
];

document.addEventListener('DOMContentLoaded', function() {
    toggleCustomDate();

    const distributorNames = <?= json_encode($chartDistributorNames); ?>;
    const distributorProfit = <?= json_encode($chartDistributorProfit); ?>;
    const distributorSales = <?= json_encode($chartDistributorSales); ?>;
    const distributorMargin = <?= json_encode($chartDistributorMargin); ?>;

    const overallProductNames = <?= json_encode($chartOverallProductNames); ?>;
    const overallProductQty = <?= json_encode($chartOverallProductQty); ?>;

    const overallMonthNames = <?= json_encode($chartOverallMonthNames); ?>;
    const overallMonthSales = <?= json_encode($chartOverallMonthSales); ?>;
    const overallMonthProfit = <?= json_encode($chartOverallMonthProfit); ?>;

    const statusLabels = <?= json_encode($chartStatusLabels); ?>;
    const statusRequests = <?= json_encode($chartStatusRequests); ?>;

    const productNames = <?= json_encode($chartProductNames); ?>;
    const productQty = <?= json_encode($chartProductQty); ?>;
    const productProfit = <?= json_encode($chartProductProfit); ?>;

    const monthNames = <?= json_encode($chartMonthNames); ?>;
    const monthSales = <?= json_encode($chartMonthSales); ?>;
    const monthProfit = <?= json_encode($chartMonthProfit); ?>;

    registerChart(document.getElementById('distributorProfitChart'), {
        type: 'bar',
        data: {
            labels: distributorNames,
            datasets: [{
                label: 'Retail Profit',
                data: distributorProfit,
                backgroundColor: chartPalette,
                borderColor: chartPalette,
                borderWidth: 1,
                borderRadius: 8
            }]
        },
        options: {
            plugins: {
                tooltip: { callbacks: { label: function(context) { return 'Retail Profit: ' + rupeeLabel(context.raw); } } }
            },
            scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return rupeeLabel(value); } } } }
        }
    });

    registerChart(document.getElementById('distributorSalesProfitChart'), {
        type: 'bar',
        data: {
            labels: distributorNames,
            datasets: [
                {
                    label: 'Wholesale Sales',
                    data: distributorSales,
                    backgroundColor: chartColors.blue,
                    borderColor: chartColors.blue,
                    borderWidth: 1,
                    borderRadius: 8
                },
                {
                    label: 'Retail Profit',
                    data: distributorProfit,
                    backgroundColor: chartColors.green,
                    borderColor: chartColors.green,
                    borderWidth: 1,
                    borderRadius: 8
                },
                {
                    label: 'Margin %',
                    data: distributorMargin,
                    type: 'line',
                    yAxisID: 'y1',
                    tension: 0.35,
                    borderWidth: 3,
                    borderColor: chartColors.orange,
                    backgroundColor: chartColors.orangeLight,
                    pointBackgroundColor: chartColors.orange,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4
                }
            ]
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.dataset.label === 'Margin %') return 'Margin: ' + Number(context.raw).toFixed(2) + '%';
                            return context.dataset.label + ': ' + rupeeLabel(context.raw);
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true, ticks: { callback: function(value) { return rupeeLabel(value); } } },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: function(value) { return value + '%'; } } }
            }
        }
    });

    registerChart(document.getElementById('statusPieChart'), {
        type: 'doughnut',
        data: {
            labels: statusLabels,
            datasets: [{
                label: 'Requests',
                data: statusRequests,
                backgroundColor: [chartColors.slate, chartColors.orange, chartColors.green],
                borderColor: '#ffffff',
                borderWidth: 3,
                hoverOffset: 8
            }]
        }
    });

    registerChart(document.getElementById('overallProductQtyChart'), {
        type: 'bar',
        data: {
            labels: overallProductNames,
            datasets: [{
                label: 'Quantity Sold',
                data: overallProductQty,
                backgroundColor: chartPalette,
                borderColor: chartPalette,
                borderWidth: 1,
                borderRadius: 8,
                barThickness: 22
            }]
        },
        options: {
            indexAxis: 'y',
            plugins: {
                legend: { display: true, labels: { usePointStyle: true, boxWidth: 10, font: { size: 13, weight: '600' } } },
                tooltip: { callbacks: { label: function(context) { return 'Quantity Sold: ' + Number(context.raw).toLocaleString('en-IN'); } } }
            },
            scales: {
                x: { beginAtZero: true, ticks: { callback: function(value) { return Number(value).toLocaleString('en-IN'); } }, title: { display: true, text: 'Quantity' } },
                y: { ticks: { autoSkip: false, font: { size: 12 } } }
            }
        }
    });

    registerChart(document.getElementById('overallMonthlyChart'), {
        type: 'line',
        data: {
            labels: overallMonthNames,
            datasets: [
                {
                    label: 'Wholesale Sales',
                    data: overallMonthSales,
                    tension: 0.35,
                    borderWidth: 3,
                    borderColor: chartColors.blue,
                    backgroundColor: chartColors.blueLight,
                    pointBackgroundColor: chartColors.blue,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    fill: true
                },
                {
                    label: 'Retail Profit',
                    data: overallMonthProfit,
                    tension: 0.35,
                    borderWidth: 3,
                    borderColor: chartColors.green,
                    backgroundColor: chartColors.greenLight,
                    pointBackgroundColor: chartColors.green,
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 4,
                    fill: true
                }
            ]
        },
        options: {
            plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + rupeeLabel(context.raw); } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return rupeeLabel(value); } } } }
        }
    });

    registerChart(document.getElementById('productQtyChart'), {
        type: 'bar',
        data: {
            labels: productNames,
            datasets: [{ label: 'Quantity Sold', data: productQty, backgroundColor: chartPalette, borderColor: chartPalette, borderWidth: 1, borderRadius: 8 }]
        },
        options: { indexAxis: 'y', scales: { x: { beginAtZero: true } } }
    });

    registerChart(document.getElementById('productProfitChart'), {
        type: 'bar',
        data: {
            labels: productNames,
            datasets: [{ label: 'Retail Profit', data: productProfit, backgroundColor: chartPalette, borderColor: chartPalette, borderWidth: 1, borderRadius: 8 }]
        },
        options: {
            indexAxis: 'y',
            plugins: { tooltip: { callbacks: { label: function(context) { return 'Retail Profit: ' + rupeeLabel(context.raw); } } } },
            scales: { x: { beginAtZero: true, ticks: { callback: function(value) { return rupeeLabel(value); } } } }
        }
    });

    registerChart(document.getElementById('monthlySalesProfitChart'), {
        type: 'line',
        data: {
            labels: monthNames,
            datasets: [
                {
                    label: 'Wholesale Sales', data: monthSales, tension: 0.35, borderWidth: 3,
                    borderColor: chartColors.blue, backgroundColor: chartColors.blueLight,
                    pointBackgroundColor: chartColors.blue, pointBorderColor: '#ffffff', pointBorderWidth: 2, pointRadius: 4, fill: true
                },
                {
                    label: 'Retail Profit', data: monthProfit, tension: 0.35, borderWidth: 3,
                    borderColor: chartColors.green, backgroundColor: chartColors.greenLight,
                    pointBackgroundColor: chartColors.green, pointBorderColor: '#ffffff', pointBorderWidth: 2, pointRadius: 4, fill: true
                }
            ]
        },
        options: {
            plugins: { tooltip: { callbacks: { label: function(context) { return context.dataset.label + ': ' + rupeeLabel(context.raw); } } } },
            scales: { y: { beginAtZero: true, ticks: { callback: function(value) { return rupeeLabel(value); } } } }
        }
    });

    registerChart(document.getElementById('productQtyProfitChart'), {
        type: 'bar',
        data: {
            labels: productNames,
            datasets: [
                { label: 'Quantity Sold', data: productQty, backgroundColor: chartColors.blue, borderColor: chartColors.blue, borderWidth: 1, borderRadius: 8 },
                {
                    label: 'Retail Profit', data: productProfit, type: 'line', yAxisID: 'y1', tension: 0.35,
                    borderWidth: 3, borderColor: chartColors.green, backgroundColor: chartColors.greenLight,
                    pointBackgroundColor: chartColors.green, pointBorderColor: '#ffffff', pointBorderWidth: 2, pointRadius: 4
                }
            ]
        },
        options: {
            interaction: { mode: 'index', intersect: false },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            if (context.dataset.label === 'Retail Profit') return 'Retail Profit: ' + rupeeLabel(context.raw);
                            return 'Qty: ' + Number(context.raw).toLocaleString('en-IN');
                        }
                    }
                }
            },
            scales: {
                y: { beginAtZero: true },
                y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false }, ticks: { callback: function(value) { return rupeeLabel(value); } } }
            }
        }
    });

    resizeChartsForPrint();

    if (autoPrintMode) {
        setTimeout(function() {
            printDistributorReport();
        }, 900);
    }
});
</script>

<?php include('include/footer.php'); ?>
