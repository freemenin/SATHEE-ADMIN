<?php
require_once 'include/require_permission.php';
requirePermission('ORDERS', 'view');
include('include/db.php');
include('include/require_login.php');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

include('include/header.php');

/* =========================
   Helpers
========================= */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyFormat($value) {
    return '₹' . number_format((float)$value, 2);
}

function safeDate($date) {
    if (empty($date)) {
        return '-';
    }

    $timestamp = strtotime($date);

    if (!$timestamp) {
        return '-';
    }

    return date('d M Y', $timestamp);
}

function buildQueryString($extra = []) {
    $query = array_merge($_GET, $extra);
    return http_build_query($query);
}

function statusClass($status) {
    $status = trim((string)$status);

    switch ($status) {
        case 'Delivered':
            return 'st-delivered';

        case 'Cancelled':
            return 'st-cancelled';

        case 'Assigned':
        case 'Ready to Delivery':
        case 'Packed':
            return 'st-assigned';

        case 'change distributor':
            return 'st-danger';

        case 'not_assigned':
        case 'Not Assigned':
            return 'st-unassigned';

        case 'New':
        case 'Open':
            return 'st-new';

        default:
            return 'st-pending';
    }
}

/*
|--------------------------------------------------------------------------
| Mobile Search Cleaner
|--------------------------------------------------------------------------
| Examples:
| +91 8690 504 346 => 8690504346
| 91 86905 04346   => 8690504346
| 8690 504 346     => 8690504346
| 08690504346      => 8690504346
*/
function cleanIndianMobile($mobile) {
    $mobile = trim((string)$mobile);

    // Remove everything except digits
    $digits = preg_replace('/\D+/', '', $mobile);

    if ($digits === '') {
        return false;
    }

    // Remove India country code 91
    if (strlen($digits) === 12 && substr($digits, 0, 2) === '91') {
        $digits = substr($digits, 2);
    }

    // Remove starting 0
    if (strlen($digits) === 11 && substr($digits, 0, 1) === '0') {
        $digits = substr($digits, 1);
    }

    // If user entered something like 0091xxxxxxxxxx
    if (strlen($digits) === 14 && substr($digits, 0, 4) === '0091') {
        $digits = substr($digits, 4);
    }

    // Final mobile must be 10 digits and start with 6, 7, 8, or 9
    if (!preg_match('/^[6-9][0-9]{9}$/', $digits)) {
        return false;
    }

    return $digits;
}

function build_where_and_params(&$types, &$params, $q, $from, $to, $payment_mode, $order_status) {
    $where = [];
    $types = '';
    $params = [];

    if ($q !== '') {
        $like = "%{$q}%";
        $cleanMobile = cleanIndianMobile($q);

        if ($cleanMobile !== false) {
            /*
                Mobile search fixed here.

                If user searches:
                +91 8690 504 346
                91 86905 04346
                8690 504 346
                08690504346

                It will search database as:
                8690504346
            */
            $where[] = "(
                o.invoice_number LIKE ?
                OR o.order_id LIKE ?
                OR c.full_name LIKE ?
                OR c.mobile_number = ?
                OR d.distributor_name LIKE ?
            )";

            $types .= 'sssss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $cleanMobile;
            $params[] = $like;
        } else {
            $where[] = "(
                o.invoice_number LIKE ?
                OR o.order_id LIKE ?
                OR c.full_name LIKE ?
                OR c.mobile_number LIKE ?
                OR d.distributor_name LIKE ?
            )";

            $types .= 'sssss';
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
            $params[] = $like;
        }
    }

    if ($from !== '' && $to !== '') {
        $where[] = "DATE(o.order_date) BETWEEN ? AND ?";
        $types .= 'ss';
        $params[] = $from;
        $params[] = $to;
    } elseif ($from !== '') {
        $where[] = "DATE(o.order_date) >= ?";
        $types .= 's';
        $params[] = $from;
    } elseif ($to !== '') {
        $where[] = "DATE(o.order_date) <= ?";
        $types .= 's';
        $params[] = $to;
    }

    if ($payment_mode !== '') {
        $where[] = "o.payment_mode = ?";
        $types .= 's';
        $params[] = $payment_mode;
    }

    if ($order_status !== '') {
        if ($order_status === 'not_assigned') {
            $where[] = "o.distributor_assigned_at IS NULL";
        } else {
            $where[] = "o.order_status = ?";
            $types .= 's';
            $params[] = $order_status;
        }
    }

    return $where ? 'WHERE ' . implode(' AND ', $where) : '';
}

/* =========================
   Flash Message
========================= */
$flash_code = $_GET['msg'] ?? '';
$flash_id   = isset($_GET['id']) ? (int)$_GET['id'] : null;
$flash_inv  = trim($_GET['inv'] ?? '');

$FLASH_MAP = [
    'method-not-allowed' => ['danger',  'Method not allowed.'],
    'csrf-failed'        => ['danger',  'Security check failed. Please try again.'],
    'invalid-order'      => ['warning', 'Invalid order ID.'],
    'order-not-found'    => ['warning', 'Order not found.'],
    'already-delivered'  => ['info',    'Order already delivered — cannot cancel.'],
    'already-cancelled'  => ['danger',  'Order already cancelled.'],
    'update-failed'      => ['danger',  'Update failed. Please try again.'],
    'order-cancelled'    => ['success', 'Order {inv} cancelled successfully.'],
];

$flash = null;

if ($flash_code && isset($FLASH_MAP[$flash_code])) {
    [$type, $text] = $FLASH_MAP[$flash_code];

    $text = str_replace(
        ['{id}', '{inv}'],
        [(string)$flash_id, $flash_inv !== '' ? $flash_inv : '-'],
        $text
    );

    $flash = [
        'type' => $type,
        'text' => $text
    ];
}

/* =========================
   Inputs
========================= */
$q             = trim($_GET['q'] ?? '');
$from          = trim($_GET['from'] ?? '');
$to            = trim($_GET['to'] ?? '');
$payment_mode  = trim($_GET['payment_mode'] ?? ($_GET['pay_status'] ?? ''));
$order_status  = trim($_GET['order_status'] ?? ($_GET['del_status'] ?? ''));
$quick         = trim($_GET['quick'] ?? '');

$page          = max(1, (int)($_GET['page'] ?? 1));
$per_page      = (int)($_GET['per_page'] ?? 25);

$allowed_per_page = [25, 50, 100, 200];

if (!in_array($per_page, $allowed_per_page, true)) {
    $per_page = 25;
}

/* =========================
   Quick Date
========================= */
if ($quick === 'today') {
    $from = date('Y-m-d');
    $to = date('Y-m-d');
} elseif ($quick === 'yesterday') {
    $from = date('Y-m-d', strtotime('-1 day'));
    $to = date('Y-m-d', strtotime('-1 day'));
} elseif ($quick === '7days') {
    $from = date('Y-m-d', strtotime('-6 days'));
    $to = date('Y-m-d');
} elseif ($quick === '30days') {
    $from = date('Y-m-d', strtotime('-29 days'));
    $to = date('Y-m-d');
} elseif ($quick === 'this_month') {
    $from = date('Y-m-01');
    $to = date('Y-m-d');
} elseif ($quick === 'last_month') {
    $from = date('Y-m-01', strtotime('first day of last month'));
    $to = date('Y-m-t', strtotime('last day of last month'));
}

$offset = ($page - 1) * $per_page;

/* =========================
   Where
========================= */
$types = '';
$params = [];

$whereSql = build_where_and_params(
    $types,
    $params,
    $q,
    $from,
    $to,
    $payment_mode,
    $order_status
);

// Base filter without order_status is used for status tab counts.
$typesBase = '';
$paramsBase = [];

$whereSqlBase = build_where_and_params(
    $typesBase,
    $paramsBase,
    $q,
    $from,
    $to,
    $payment_mode,
    ''
);

/* =========================
   Count
========================= */
$sqlCount = "
    SELECT COUNT(*) AS total_rows
    FROM orders o
    JOIN customers c ON c.customer_id = o.customer_id
    LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
    $whereSql
";

$stmtCount = $mysqli->prepare($sqlCount);

if (!$stmtCount) {
    die('Count Prepare Error: ' . $mysqli->error);
}

if ($types !== '') {
    $stmtCount->bind_param($types, ...$params);
}

$stmtCount->execute();
$countRow = $stmtCount->get_result()->fetch_assoc();

$total_rows = (int)($countRow['total_rows'] ?? 0);
$total_pages = max(1, (int)ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

/* =========================
   All Tab Count
========================= */
$sqlAllTabCount = "
    SELECT COUNT(*) AS total_rows
    FROM orders o
    JOIN customers c ON c.customer_id = o.customer_id
    LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
    $whereSqlBase
";

$stmtAllTabCount = $mysqli->prepare($sqlAllTabCount);

if (!$stmtAllTabCount) {
    die('All Tab Count Prepare Error: ' . $mysqli->error);
}

if ($typesBase !== '') {
    $stmtAllTabCount->bind_param($typesBase, ...$paramsBase);
}

$stmtAllTabCount->execute();
$allTabRow = $stmtAllTabCount->get_result()->fetch_assoc();
$all_tab_total = (int)($allTabRow['total_rows'] ?? 0);

/* =========================
   Summary
========================= */
$sqlSummary = "
    SELECT
        COUNT(*) AS total_orders,
        COALESCE(SUM(o.grand_total), 0) AS grand_total,
        COALESCE(SUM(o.tax), 0) AS total_tax,
        COALESCE(SUM(o.discount), 0) AS total_discount,
        SUM(CASE WHEN o.order_status = 'Delivered' THEN 1 ELSE 0 END) AS delivered_orders,
        SUM(CASE WHEN o.order_status = 'Cancelled' THEN 1 ELSE 0 END) AS cancelled_orders,
        SUM(CASE WHEN o.order_status NOT IN ('Delivered', 'Cancelled') THEN 1 ELSE 0 END) AS active_orders
    FROM orders o
    JOIN customers c ON c.customer_id = o.customer_id
    LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
    $whereSql
";

$stmtSummary = $mysqli->prepare($sqlSummary);

if (!$stmtSummary) {
    die('Summary Prepare Error: ' . $mysqli->error);
}

if ($types !== '') {
    $stmtSummary->bind_param($types, ...$params);
}

$stmtSummary->execute();
$summary = $stmtSummary->get_result()->fetch_assoc();

$total_orders     = (int)($summary['total_orders'] ?? 0);
$grand_total      = (float)($summary['grand_total'] ?? 0);
$total_tax        = (float)($summary['total_tax'] ?? 0);
$total_discount   = (float)($summary['total_discount'] ?? 0);
$delivered_orders = (int)($summary['delivered_orders'] ?? 0);
$cancelled_orders = (int)($summary['cancelled_orders'] ?? 0);
$active_orders    = (int)($summary['active_orders'] ?? 0);

/* =========================
   Status Counts
========================= */
$statusCountSql = "
    SELECT 
        o.order_status,
        COUNT(*) AS total
    FROM orders o
    JOIN customers c ON c.customer_id = o.customer_id
    LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
    $whereSqlBase
    GROUP BY o.order_status
";

$stmtStatusCount = $mysqli->prepare($statusCountSql);

if (!$stmtStatusCount) {
    die('Status Count Prepare Error: ' . $mysqli->error);
}

if ($typesBase !== '') {
    $stmtStatusCount->bind_param($typesBase, ...$paramsBase);
}

$stmtStatusCount->execute();
$statusCountResult = $stmtStatusCount->get_result();

$statusCounts = [];

while ($sr = $statusCountResult->fetch_assoc()) {
    $statusCounts[$sr['order_status']] = (int)$sr['total'];
}

/* =========================
   Not Assigned Count
========================= */
$sqlNotAssignedCount = "
    SELECT COUNT(*) AS total
    FROM orders o
    JOIN customers c ON c.customer_id = o.customer_id
    LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
    $whereSqlBase
    " . ($whereSqlBase !== '' ? " AND " : " WHERE ") . " o.distributor_assigned_at IS NULL
";

$stmtNotAssignedCount = $mysqli->prepare($sqlNotAssignedCount);

if (!$stmtNotAssignedCount) {
    die('Not Assigned Count Prepare Error: ' . $mysqli->error);
}

if ($typesBase !== '') {
    $stmtNotAssignedCount->bind_param($typesBase, ...$paramsBase);
}

$stmtNotAssignedCount->execute();
$notAssignedCountRow = $stmtNotAssignedCount->get_result()->fetch_assoc();
$not_assigned_count = (int)($notAssignedCountRow['total'] ?? 0);

/* =========================
   Main List
========================= */
$sqlList = "
    SELECT
        o.order_id,
        o.invoice_number,
        o.order_date,
        o.order_status,
        o.payment_mode,
        COALESCE(o.delivery_status, 'Pending') AS delivery_status,
        o.subtotal,
        o.tax,
        o.discount,
        o.grand_total,
        c.full_name,
        c.mobile_number,
        o.distributor_id,
        o.distributor_assigned_at,
        COALESCE(d.distributor_name, 'Unassigned') AS distributor_name,
        COALESCE(items.item_count, 0) AS item_count,
        COALESCE(comments.comment_count, 0) AS comment_count
    FROM orders o
    JOIN customers c ON c.customer_id = o.customer_id
    LEFT JOIN distributors d ON d.distributor_id = o.distributor_id
    LEFT JOIN (
        SELECT order_id, SUM(quantity) AS item_count
        FROM order_items
        GROUP BY order_id
    ) items ON items.order_id = o.order_id
    LEFT JOIN (
        SELECT order_id, COUNT(*) AS comment_count
        FROM order_comments
        GROUP BY order_id
    ) comments ON comments.order_id = o.order_id
    $whereSql
    ORDER BY o.order_id DESC
    LIMIT ? OFFSET ?
";

$typesList = $types . 'ii';
$paramsList = array_merge($params, [$per_page, $offset]);

$stmtList = $mysqli->prepare($sqlList);

if (!$stmtList) {
    die('List Prepare Error: ' . $mysqli->error);
}

$stmtList->bind_param($typesList, ...$paramsList);
$stmtList->execute();
$resList = $stmtList->get_result();

$orders = [];

while ($row = $resList->fetch_assoc()) {
    $orders[] = $row;
}

$toast = $_SESSION['toast'] ?? '';
unset($_SESSION['toast']);
?>

<style>
    body {
        background: #f4f6f9;
    }

    .order-wrap {
        padding: 18px;
    }

    .page-head {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 16px 18px;
        margin-bottom: 14px;
    }

    .page-title {
        font-size: 22px;
        font-weight: 800;
        color: #111827;
        margin: 0;
    }

    .page-desc {
        color: #6b7280;
        font-size: 13px;
        margin-top: 3px;
    }

    .btn-main {
        border-radius: 10px;
        font-weight: 700;
        padding: 8px 14px;
    }

    .mini-summary {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 12px 16px;
        margin-bottom: 14px;
    }

    .summary-item {
        border-right: 1px solid #e5e7eb;
    }

    .summary-item:last-child {
        border-right: 0;
    }

    .summary-label {
        font-size: 12px;
        color: #6b7280;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: 0.3px;
    }

    .summary-value {
        font-size: 19px;
        color: #111827;
        font-weight: 900;
        margin-top: 2px;
    }

    .filter-box {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 14px;
        margin-bottom: 14px;
    }

    .filter-box label {
        font-size: 12px;
        font-weight: 800;
        color: #374151;
        margin-bottom: 4px;
    }

    .filter-box .form-control,
    .filter-box .form-select {
        border-radius: 9px;
        font-size: 13px;
        min-height: 38px;
    }

    .quick-tabs {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        margin-top: 10px;
    }

    .quick-tabs a {
        font-size: 12px;
        border-radius: 999px;
        padding: 5px 10px;
        text-decoration: none;
        border: 1px solid #d1d5db;
        color: #374151;
        background: #ffffff;
        font-weight: 700;
    }

    .quick-tabs a.active,
    .quick-tabs a:hover {
        background: #111827;
        color: #ffffff;
        border-color: #111827;
    }

    .status-tabs {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 8px;
        display: flex;
        gap: 7px;
        flex-wrap: wrap;
        margin-bottom: 14px;
    }

    .status-tabs a {
        text-decoration: none;
        color: #374151;
        background: #f9fafb;
        border: 1px solid #e5e7eb;
        padding: 8px 12px;
        border-radius: 999px;
        font-size: 13px;
        font-weight: 800;
    }

    .status-tabs a.active {
        background: #2563eb;
        color: #ffffff;
        border-color: #2563eb;
    }

    .status-tabs span {
        opacity: 0.75;
        margin-left: 4px;
    }

    .list-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        overflow: hidden;
    }

    .table-toolbar {
        padding: 12px 14px;
        border-bottom: 1px solid #e5e7eb;
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #ffffff;
    }

    .table-toolbar .title {
        font-size: 15px;
        font-weight: 900;
        color: #111827;
    }

    .table-toolbar .small-info {
        color: #6b7280;
        font-size: 12px;
        font-weight: 700;
    }

    .order-table {
        margin-bottom: 0;
        white-space: nowrap;
    }

    .order-table thead th {
        background: #f9fafb;
        color: #6b7280;
        font-size: 11px;
        font-weight: 900;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        border-bottom: 1px solid #e5e7eb;
        padding: 11px 12px;
    }

    .order-table tbody td {
        padding: 14px 12px;
        vertical-align: middle;
        color: #111827;
        font-size: 13px;
        border-bottom: 1px solid #f0f2f5;
    }

    .order-table tbody tr:hover {
        background: #f9fafb;
    }

    .invoice-no {
        font-weight: 900;
        color: #111827;
        text-decoration: none;
        font-size: 14px;
    }

    .invoice-no:hover {
        color: #2563eb;
    }

    .sub-text {
        font-size: 11px;
        color: #6b7280;
        font-weight: 600;
    }

    .customer-name {
        font-weight: 900;
        color: #111827;
        max-width: 270px;
        white-space: normal;
        line-height: 1.25;
    }

    .money {
        font-weight: 900;
        color: #111827;
    }

    .pill {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 900;
        padding: 5px 9px;
        line-height: 1;
    }

    .pill-items {
        background: #e0f2fe;
        color: #075985;
        min-width: 28px;
    }

    .pill-comment {
        background: #f3f4f6;
        color: #374151;
        min-width: 34px;
    }

    .dist-pill {
        background: #f8fafc;
        border: 1px solid #e5e7eb;
        color: #374151;
        border-radius: 999px;
        padding: 5px 9px;
        font-size: 11px;
        font-weight: 800;
    }

    .status-pill {
        display: inline-flex;
        align-items: center;
        border-radius: 999px;
        padding: 6px 10px;
        font-size: 11px;
        font-weight: 900;
    }

    .st-delivered {
        background: #dcfce7;
        color: #166534;
    }

    .st-assigned {
        background: #dbeafe;
        color: #1d4ed8;
    }

    .st-cancelled {
        background: #e5e7eb;
        color: #111827;
    }

    .st-danger {
        background: #fee2e2;
        color: #b91c1c;
    }

    .st-unassigned {
        background: #fff7ed;
        color: #c2410c;
    }

    .st-new {
        background: #fef3c7;
        color: #92400e;
    }

    .st-pending {
        background: #f3f4f6;
        color: #374151;
    }

    .dropdown-action .btn {
        border-radius: 9px;
        font-weight: 800;
        font-size: 12px;
    }

    .dropdown-menu {
        border-radius: 12px;
        border: 1px solid #e5e7eb;
        box-shadow: 0 12px 35px rgba(15, 23, 42, 0.12);
        padding: 8px;
    }

    .dropdown-item {
        border-radius: 8px;
        font-size: 13px;
        font-weight: 700;
        padding: 8px 10px;
    }

    .dropdown-item:hover {
        background: #f3f4f6;
    }

    .pagination-wrap {
        padding: 13px 14px;
        background: #ffffff;
        border-top: 1px solid #e5e7eb;
    }

    .pagination .page-link {
        border-radius: 9px;
        margin: 0 2px;
        font-size: 13px;
        font-weight: 800;
        color: #111827;
    }

    .pagination .active .page-link {
        background: #2563eb;
        border-color: #2563eb;
        color: #ffffff;
    }

    .mobile-list {
        display: none;
    }

    .mobile-order-card {
        background: #ffffff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 14px;
        margin-bottom: 12px;
    }

    .mobile-top {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: flex-start;
    }

    .mobile-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 10px;
        margin-top: 12px;
    }

    .mobile-label {
        font-size: 11px;
        color: #6b7280;
        font-weight: 800;
        text-transform: uppercase;
    }

    .mobile-value {
        font-size: 13px;
        color: #111827;
        font-weight: 800;
        margin-top: 2px;
    }

    @media (max-width: 768px) {
        .order-wrap {
            padding: 12px;
        }

        .page-head {
            padding: 14px;
        }

        .page-title {
            font-size: 20px;
        }

        .desktop-table {
            display: none;
        }

        .mobile-list {
            display: block;
        }

        .summary-item {
            border-right: 0;
            border-bottom: 1px solid #e5e7eb;
            padding-bottom: 10px;
            margin-bottom: 10px;
        }

        .summary-item:last-child {
            border-bottom: 0;
            padding-bottom: 0;
            margin-bottom: 0;
        }

        .summary-value {
            font-size: 18px;
        }

        .table-toolbar {
            flex-direction: column;
            align-items: flex-start;
            gap: 3px;
        }
    }
</style>

<?php if ($flash): ?>
    <?php
    $toastClass = [
        'success' => 'bg-success text-white',
        'danger'  => 'bg-danger text-white',
        'warning' => 'bg-warning text-dark',
        'info'    => 'bg-info text-dark',
    ][$flash['type']] ?? 'bg-secondary text-white';
    ?>

    <div class="position-fixed top-50 start-50 translate-middle p-2" style="z-index:1080;">
        <div id="appToast"
             class="toast align-items-center shadow-lg rounded-3 <?= h($toastClass) ?>"
             role="status"
             data-bs-autohide="true"
             data-bs-delay="4000">
            <div class="d-flex">
                <div class="toast-body fw-semibold">
                    <?= h($flash['text']) ?>
                </div>
                <button type="button"
                        class="btn-close <?= str_contains($toastClass, 'text-white') ? 'btn-close-white' : '' ?> me-2 m-auto"
                        data-bs-dismiss="toast"></button>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="order-wrap">

    <div class="page-head">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
            <div>
                <h4 class="page-title">Orders</h4>
                <div class="page-desc">
                    Search, filter, view, edit and manage all customer orders from one clean page.
                </div>
            </div>

            <div class="d-flex gap-2 flex-wrap">
                <a href="add_order.php" class="btn btn-warning btn-main">
                    + New Order
                </a>

                <a href="export_orders.php?<?= h(http_build_query($_GET)) ?>" class="btn btn-outline-secondary btn-main">
                    Export CSV
                </a>
            </div>
        </div>
    </div>

    <div class="mini-summary">
        <div class="row g-3">
            <div class="col-6 col-md-2 summary-item">
                <div class="summary-label">Orders</div>
                <div class="summary-value"><?= number_format($total_orders) ?></div>
            </div>

            <div class="col-6 col-md-2 summary-item">
                <div class="summary-label">Grand Total</div>
                <div class="summary-value"><?= moneyFormat($grand_total) ?></div>
            </div>

            <div class="col-6 col-md-2 summary-item">
                <div class="summary-label">Active</div>
                <div class="summary-value"><?= number_format($active_orders) ?></div>
            </div>

            <div class="col-6 col-md-2 summary-item">
                <div class="summary-label">Delivered</div>
                <div class="summary-value text-success"><?= number_format($delivered_orders) ?></div>
            </div>

            <div class="col-6 col-md-2 summary-item">
                <div class="summary-label">Tax</div>
                <div class="summary-value"><?= moneyFormat($total_tax) ?></div>
            </div>

            <div class="col-6 col-md-2 summary-item">
                <div class="summary-label">Discount</div>
                <div class="summary-value text-danger"><?= moneyFormat($total_discount) ?></div>
            </div>
        </div>
    </div>

    <div class="filter-box">
        <form method="GET">
            <div class="row g-2 align-items-end">
                <div class="col-12 col-md-3">
                    <label>Search</label>
                    <input type="text"
                           name="q"
                           class="form-control"
                           placeholder="Invoice / name / mobile / order id"
                           value="<?= h($q) ?>">
                </div>

                <div class="col-6 col-md-2">
                    <label>From</label>
                    <input type="date" name="from" class="form-control" value="<?= h($from) ?>">
                </div>

                <div class="col-6 col-md-2">
                    <label>To</label>
                    <input type="date" name="to" class="form-control" value="<?= h($to) ?>">
                </div>

                <div class="col-6 col-md-2">
                    <label>Status</label>
                    <select name="order_status" class="form-select">
                        <option value="">All Status</option>

                        <?php
                        $statusOptions = [
                            'New',
                            'Open',
                            'Assigned',
                            'Ready to Delivery',
                            'not_assigned',
                            'Delivered',
                            'Cancelled',
                            'change distributor'
                        ];

                        foreach ($statusOptions as $opt):
                        ?>
                            <option value="<?= h($opt) ?>" <?= $order_status === $opt ? 'selected' : '' ?>>
                                <?= h($opt === 'not_assigned' ? 'Not Assigned' : $opt) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-6 col-md-1">
                    <label>Rows</label>
                    <select name="per_page" class="form-select">
                        <?php foreach ($allowed_per_page as $pp): ?>
                            <option value="<?= (int)$pp ?>" <?= $per_page === $pp ? 'selected' : '' ?>>
                                <?= (int)$pp ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-dark btn-main w-100">
                        Apply
                    </button>

                    <a href="order_list.php" class="btn btn-outline-secondary btn-main w-100">
                        Reset
                    </a>
                </div>
            </div>

            <input type="hidden" name="page" value="1">

            <div class="quick-tabs">
                <a class="<?= $quick === 'today' ? 'active' : '' ?>" href="order_list.php?<?= h(buildQueryString(['quick' => 'today', 'page' => 1])) ?>">Today</a>
                <a class="<?= $quick === 'yesterday' ? 'active' : '' ?>" href="order_list.php?<?= h(buildQueryString(['quick' => 'yesterday', 'page' => 1])) ?>">Yesterday</a>
                <a class="<?= $quick === '7days' ? 'active' : '' ?>" href="order_list.php?<?= h(buildQueryString(['quick' => '7days', 'page' => 1])) ?>">7 Days</a>
                <a class="<?= $quick === '30days' ? 'active' : '' ?>" href="order_list.php?<?= h(buildQueryString(['quick' => '30days', 'page' => 1])) ?>">30 Days</a>
                <a class="<?= $quick === 'this_month' ? 'active' : '' ?>" href="order_list.php?<?= h(buildQueryString(['quick' => 'this_month', 'page' => 1])) ?>">This Month</a>
                <a class="<?= $quick === 'last_month' ? 'active' : '' ?>" href="order_list.php?<?= h(buildQueryString(['quick' => 'last_month', 'page' => 1])) ?>">Last Month</a>
            </div>
        </form>
    </div>

    <div class="status-tabs">
        <a href="order_list.php?<?= h(buildQueryString(['order_status' => '', 'del_status' => '', 'page' => 1])) ?>"
           class="<?= $order_status === '' ? 'active' : '' ?>">
            All <span><?= number_format($all_tab_total) ?></span>
        </a>

        <a href="order_list.php?<?= h(buildQueryString(['order_status' => 'not_assigned', 'del_status' => '', 'page' => 1])) ?>"
           class="<?= $order_status === 'not_assigned' ? 'active' : '' ?>">
            Not Assigned <span><?= number_format($not_assigned_count) ?></span>
        </a>

        <?php foreach (['Assigned', 'Delivered', 'Cancelled', 'Ready to Delivery', 'change distributor'] as $st): ?>
            <a href="order_list.php?<?= h(buildQueryString(['order_status' => $st, 'del_status' => '', 'page' => 1])) ?>"
               class="<?= $order_status === $st ? 'active' : '' ?>">
                <?= h($st) ?> <span><?= number_format($statusCounts[$st] ?? 0) ?></span>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="list-card">

        <div class="table-toolbar">
            <div>
                <div class="title">Order List</div>
                <div class="small-info">
                    Showing <?= $total_rows > 0 ? number_format($offset + 1) : 0 ?>
                    to <?= number_format(min($offset + $per_page, $total_rows)) ?>
                    of <?= number_format($total_rows) ?> orders
                </div>
            </div>

            <div class="small-info">
                Page <?= number_format($page) ?> of <?= number_format($total_pages) ?>
            </div>
        </div>

        <div class="table-responsive desktop-table">
            <table class="table order-table">
                <thead>
                    <tr>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Date</th>
                        <th class="text-end">Amount</th>
                        <th>Items</th>
                        <th>Distributor</th>
                        <th>Payment</th>
                        <th>Status</th>
                        <th>Comments</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="10" class="text-center py-5 text-muted">
                                <div class="fw-bold fs-5 text-dark">No orders found</div>
                                <div>Try changing search, date or status filter.</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $row): ?>
                            <?php
                            $isDelivered = $row['order_status'] === 'Delivered';
                            $isCancelled = $row['order_status'] === 'Cancelled';
                            $isNotAssigned = empty($row['distributor_assigned_at']);
                            $displayStatus = $isNotAssigned ? 'Not Assigned' : ($row['order_status'] ?: 'Pending');
                            ?>

                            <tr>
                                <td>
                                    <a class="invoice-no" href="view_order.php?id=<?= (int)$row['order_id'] ?>">
                                        <?= h($row['invoice_number']) ?>
                                    </a>
                                    <div class="sub-text">Order #<?= (int)$row['order_id'] ?></div>
                                </td>

                                <td>
                                    <div class="customer-name"><?= h($row['full_name']) ?></div>
                                    <div class="sub-text"><?= h($row['mobile_number']) ?></div>
                                </td>

                                <td><?= safeDate($row['order_date']) ?></td>

                                <td class="text-end money">
                                    <?= moneyFormat($row['grand_total']) ?>
                                </td>

                                <td>
                                    <span class="pill pill-items"><?= (int)$row['item_count'] ?></span>
                                </td>

                                <td>
                                    <span class="dist-pill"><?= h($row['distributor_name']) ?></span>
                                </td>

                                <td>
                                    <strong><?= h($row['payment_mode'] ?: '-') ?></strong>
                                </td>

                                <td>
                                    <span class="status-pill <?= h(statusClass($isNotAssigned ? 'not_assigned' : $row['order_status'])) ?>">
                                        <?= h($displayStatus) ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="pill pill-comment">💬 <?= (int)$row['comment_count'] ?></span>
                                </td>

                                <td class="text-end">
                                    <div class="dropdown dropdown-action">
                                        <button class="btn btn-sm btn-outline-dark dropdown-toggle"
                                                type="button"
                                                data-bs-toggle="dropdown"
                                                aria-expanded="false">
                                            Action
                                        </button>

                                        <ul class="dropdown-menu dropdown-menu-end">
                                            <li>
                                                <a class="dropdown-item" href="view_order.php?id=<?= (int)$row['order_id'] ?>">
                                                    View Order
                                                </a>
                                            </li>

                                            <li>
                                                <a class="dropdown-item" href="edit_order.php?id=<?= (int)$row['order_id'] ?>">
                                                    Edit Order
                                                </a>
                                            </li>

                                            <?php if (!$isDelivered && !$isCancelled): ?>
                                                <li><hr class="dropdown-divider"></li>

                                                <li>
                                                    <form method="post"
                                                          action="order_cancel.php"
                                                          onsubmit="return confirm('Cancel this order?');">
                                                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                                        <input type="hidden" name="order_id" value="<?= (int)$row['order_id'] ?>">
                                                        <input type="hidden" name="invoice" value="<?= h($row['invoice_number']) ?>">
                                                        <input type="hidden" name="return_to" value="order_list.php">

                                                        <button type="submit" class="dropdown-item text-danger">
                                                            Cancel Order
                                                        </button>
                                                    </form>
                                                </li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="mobile-list p-2">
            <?php if (empty($orders)): ?>
                <div class="mobile-order-card text-center">
                    <div class="fw-bold fs-5">No orders found</div>
                    <div class="text-muted">Try changing filters.</div>
                </div>
            <?php else: ?>
                <?php foreach ($orders as $row): ?>
                    <?php
                    $isDelivered = $row['order_status'] === 'Delivered';
                    $isCancelled = $row['order_status'] === 'Cancelled';
                    $isNotAssigned = empty($row['distributor_assigned_at']);
                    $displayStatus = $isNotAssigned ? 'Not Assigned' : ($row['order_status'] ?: 'Pending');
                    ?>

                    <div class="mobile-order-card">
                        <div class="mobile-top">
                            <div>
                                <a class="invoice-no" href="view_order.php?id=<?= (int)$row['order_id'] ?>">
                                    <?= h($row['invoice_number']) ?>
                                </a>
                                <div class="sub-text">
                                    Order #<?= (int)$row['order_id'] ?> · <?= safeDate($row['order_date']) ?>
                                </div>
                            </div>

                            <span class="status-pill <?= h(statusClass($isNotAssigned ? 'not_assigned' : $row['order_status'])) ?>">
                                <?= h($displayStatus) ?>
                            </span>
                        </div>

                        <div class="mt-2">
                            <div class="customer-name"><?= h($row['full_name']) ?></div>
                            <div class="sub-text"><?= h($row['mobile_number']) ?></div>
                        </div>

                        <div class="mobile-grid">
                            <div>
                                <div class="mobile-label">Amount</div>
                                <div class="mobile-value"><?= moneyFormat($row['grand_total']) ?></div>
                            </div>

                            <div>
                                <div class="mobile-label">Payment</div>
                                <div class="mobile-value"><?= h($row['payment_mode'] ?: '-') ?></div>
                            </div>

                            <div>
                                <div class="mobile-label">Items</div>
                                <div class="mobile-value"><?= (int)$row['item_count'] ?></div>
                            </div>

                            <div>
                                <div class="mobile-label">Comments</div>
                                <div class="mobile-value">💬 <?= (int)$row['comment_count'] ?></div>
                            </div>

                            <div style="grid-column: span 2;">
                                <div class="mobile-label">Distributor</div>
                                <div class="mobile-value"><?= h($row['distributor_name']) ?></div>
                            </div>
                        </div>

                        <div class="d-flex gap-2 mt-3">
                            <a href="view_order.php?id=<?= (int)$row['order_id'] ?>" class="btn btn-sm btn-outline-primary w-100 btn-main">
                                View
                            </a>

                            <a href="edit_order.php?id=<?= (int)$row['order_id'] ?>" class="btn btn-sm btn-outline-warning w-100 btn-main">
                                Edit
                            </a>
                        </div>

                        <?php if (!$isDelivered && !$isCancelled): ?>
                            <form method="post"
                                  action="order_cancel.php"
                                  class="mt-2"
                                  onsubmit="return confirm('Cancel this order?');">
                                <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                <input type="hidden" name="order_id" value="<?= (int)$row['order_id'] ?>">
                                <input type="hidden" name="invoice" value="<?= h($row['invoice_number']) ?>">
                                <input type="hidden" name="return_to" value="order_list.php">

                                <button type="submit" class="btn btn-sm btn-outline-danger w-100 btn-main">
                                    Cancel Order
                                </button>
                            </form>
                        <?php endif; ?>
                    </div>

                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if ($total_pages > 1): ?>
            <div class="pagination-wrap">
                <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between gap-2">
                    <div class="text-muted small fw-bold">
                        Showing <?= $total_rows > 0 ? number_format($offset + 1) : 0 ?>
                        - <?= number_format(min($offset + $per_page, $total_rows)) ?>
                        of <?= number_format($total_rows) ?>
                    </div>

                    <nav>
                        <ul class="pagination mb-0 flex-wrap">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="order_list.php?<?= h(buildQueryString(['page' => max(1, $page - 1)])) ?>">
                                    Previous
                                </a>
                            </li>

                            <?php
                            $start_page = max(1, $page - 2);
                            $end_page = min($total_pages, $page + 2);

                            if ($start_page > 1):
                            ?>
                                <li class="page-item">
                                    <a class="page-link" href="order_list.php?<?= h(buildQueryString(['page' => 1])) ?>">1</a>
                                </li>

                                <?php if ($start_page > 2): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php for ($p = $start_page; $p <= $end_page; $p++): ?>
                                <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                                    <a class="page-link" href="order_list.php?<?= h(buildQueryString(['page' => $p])) ?>">
                                        <?= (int)$p ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($end_page < $total_pages): ?>
                                <?php if ($end_page < $total_pages - 1): ?>
                                    <li class="page-item disabled">
                                        <span class="page-link">...</span>
                                    </li>
                                <?php endif; ?>

                                <li class="page-item">
                                    <a class="page-link" href="order_list.php?<?= h(buildQueryString(['page' => $total_pages])) ?>">
                                        <?= (int)$total_pages ?>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="order_list.php?<?= h(buildQueryString(['page' => min($total_pages, $page + 1)])) ?>">
                                    Next
                                </a>
                            </li>
                        </ul>
                    </nav>
                </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var toastEl = document.getElementById('appToast');

    if (toastEl && window.bootstrap && bootstrap.Toast) {
        new bootstrap.Toast(toastEl).show();
    }
});
</script>

<?php if (!empty($toast)): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    alert(<?= json_encode($toast) ?>);
});
</script>
<?php endif; ?>

<?php include('include/footer.php'); ?>