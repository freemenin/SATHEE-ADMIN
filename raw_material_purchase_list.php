<?php
require_once 'include/require_permission.php';
requirePermission('RAW_MATERIAL_PURCHASE', 'view');
include('include/header.php');
?>

<?php
$search            = trim($_GET['search'] ?? '');
$from_date         = trim($_GET['from_date'] ?? '');
$to_date           = trim($_GET['to_date'] ?? '');
$quick_range       = trim($_GET['quick_range'] ?? '');
$owner_company     = trim($_GET['owner_company'] ?? '');
$paid_by_company   = trim($_GET['paid_by_company'] ?? '');
$settlement_status = trim($_GET['settlement_status'] ?? '');
$page              = max(1, intval($_GET['page'] ?? 1));
$per_page          = intval($_GET['per_page'] ?? 10);

$allowed_per_page = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page)) {
    $per_page = 10;
}

/* Quick Date Filter */
$today = date('Y-m-d');

if ($quick_range !== '') {
    if ($quick_range === 'today') {
        $from_date = $today;
        $to_date = $today;
    } elseif ($quick_range === 'yesterday') {
        $from_date = date('Y-m-d', strtotime('-1 day'));
        $to_date = date('Y-m-d', strtotime('-1 day'));
    } elseif ($quick_range === '7days') {
        $from_date = date('Y-m-d', strtotime('-6 days'));
        $to_date = $today;
    } elseif ($quick_range === '30days') {
        $from_date = date('Y-m-d', strtotime('-29 days'));
        $to_date = $today;
    } elseif ($quick_range === 'this_month') {
        $from_date = date('Y-m-01');
        $to_date = $today;
    } elseif ($quick_range === 'last_month') {
        $from_date = date('Y-m-01', strtotime('first day of last month'));
        $to_date = date('Y-m-t', strtotime('last day of last month'));
    }
}

$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(
        p.purchase_id LIKE ? 
        OR v.vendor_name LIKE ? 
        OR p.note LIKE ?
    )";
    $search_like = "%{$search}%";
    $params[] = $search_like;
    $params[] = $search_like;
    $params[] = $search_like;
    $types .= 'sss';
}

if ($from_date !== '') {
    $where[] = "p.purchase_date >= ?";
    $params[] = $from_date;
    $types .= 's';
}

if ($to_date !== '') {
    $where[] = "p.purchase_date <= ?";
    $params[] = $to_date;
    $types .= 's';
}

if ($owner_company !== '') {
    $where[] = "p.owner_company = ?";
    $params[] = $owner_company;
    $types .= 's';
}

if ($paid_by_company !== '') {
    $where[] = "p.paid_by_company = ?";
    $params[] = $paid_by_company;
    $types .= 's';
}

if ($settlement_status !== '') {
    $where[] = "p.settlement_status = ?";
    $params[] = $settlement_status;
    $types .= 's';
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = "WHERE " . implode(" AND ", $where);
}

/* Summary Query */
$summary_sql = "
    SELECT 
        COUNT(p.purchase_id) AS total_purchases,
        COALESCE(SUM(p.total_amount), 0) AS total_amount,
        COALESCE(SUM(IFNULL(ic.total_items, 0)), 0) AS total_items
    FROM raw_material_purchases p
    INNER JOIN vendors v ON p.vendor_id = v.vendor_id
    LEFT JOIN (
        SELECT purchase_id, COUNT(*) AS total_items
        FROM raw_material_purchase_items
        GROUP BY purchase_id
    ) ic ON p.purchase_id = ic.purchase_id
    $where_sql
";

$summary_stmt = $mysqli->prepare($summary_sql);
if (!$summary_stmt) {
    die("Summary Query Error: " . $mysqli->error);
}

if (!empty($params)) {
    $summary_stmt->bind_param($types, ...$params);
}

$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();
$summary_stmt->close();

$total_purchase_count  = intval($summary['total_purchases'] ?? 0);
$total_purchase_amount = floatval($summary['total_amount'] ?? 0);
$total_item_count      = intval($summary['total_items'] ?? 0);

$total_pages = max(1, ceil($total_purchase_count / $per_page));
if ($page > $total_pages) {
    $page = $total_pages;
}

$offset = ($page - 1) * $per_page;

/* Main Query */
$sql = "
    SELECT 
        p.purchase_id,
        p.vendor_id,
        p.purchase_date,
        p.total_amount,
        p.note,
        p.created_by,
        p.created_at,
        p.owner_company,
        p.paid_by_company,
        p.settlement_required,
        p.settlement_status,
        v.vendor_name,
        IFNULL(ic.total_items, 0) AS total_items
    FROM raw_material_purchases p
    INNER JOIN vendors v ON p.vendor_id = v.vendor_id
    LEFT JOIN (
        SELECT purchase_id, COUNT(*) AS total_items
        FROM raw_material_purchase_items
        GROUP BY purchase_id
    ) ic ON p.purchase_id = ic.purchase_id
    $where_sql
    ORDER BY p.purchase_date DESC, p.purchase_id DESC
    LIMIT ? OFFSET ?
";

$list_params = $params;
$list_types = $types . 'ii';
$list_params[] = $per_page;
$list_params[] = $offset;

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    die("Main Query Error: " . $mysqli->error);
}

$stmt->bind_param($list_types, ...$list_params);
$stmt->execute();
$result = $stmt->get_result();

$purchases = [];
while ($row = $result->fetch_assoc()) {
    $purchases[] = $row;
}
$stmt->close();

function buildQueryString($extra = []) {
    $query = $_GET;

    foreach ($extra as $key => $value) {
        $query[$key] = $value;
    }

    return http_build_query($query);
}

$start_record = ($total_purchase_count > 0) ? ($offset + 1) : 0;
$end_record = min($offset + $per_page, $total_purchase_count);
?>

<style>
    body {
        background: #f4f6fb;
    }

    .sa-page-wrap {
        padding: 22px 0 40px;
    }

    .sa-topbar {
        background: linear-gradient(135deg, #101827, #1f2937);
        color: #fff;
        border-radius: 22px;
        padding: 22px;
        margin-bottom: 18px;
        box-shadow: 0 18px 40px rgba(15, 23, 42, 0.18);
        position: relative;
        overflow: hidden;
    }

    .sa-topbar:after {
        content: "";
        position: absolute;
        right: -70px;
        top: -70px;
        width: 190px;
        height: 190px;
        border-radius: 50%;
        background: rgba(255,255,255,0.08);
    }

    .sa-topbar-content {
        position: relative;
        z-index: 2;
        display: flex;
        justify-content: space-between;
        gap: 16px;
        flex-wrap: wrap;
        align-items: center;
    }

    .sa-page-title {
        font-size: 25px;
        font-weight: 900;
        margin: 0;
        letter-spacing: -0.03em;
    }

    .sa-page-subtitle {
        color: rgba(255,255,255,0.72);
        font-size: 13px;
        margin-top: 5px;
        max-width: 740px;
    }

    .sa-btn-light {
        background: #fff;
        color: #111827;
        border: 0;
        font-weight: 800;
        border-radius: 14px;
        padding: 11px 16px;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 7px;
        box-shadow: 0 10px 22px rgba(0,0,0,0.16);
    }

    .sa-summary-grid {
        display: grid;
        grid-template-columns: repeat(3, minmax(0, 1fr));
        gap: 14px;
        margin-bottom: 18px;
    }

    .sa-summary-box {
        background: #fff;
        border: 1px solid #edf0f7;
        border-radius: 20px;
        padding: 17px;
        box-shadow: 0 10px 26px rgba(15, 23, 42, 0.06);
        position: relative;
        overflow: hidden;
    }

    .sa-summary-box:before {
        content: "";
        position: absolute;
        left: 0;
        top: 0;
        width: 5px;
        height: 100%;
        background: #2563eb;
    }

    .sa-summary-label {
        font-size: 13px;
        color: #6b7280;
        font-weight: 700;
        margin-bottom: 6px;
    }

    .sa-summary-value {
        font-size: 25px;
        font-weight: 950;
        color: #111827;
        line-height: 1.1;
    }

    .sa-summary-note {
        font-size: 12px;
        color: #9ca3af;
        margin-top: 5px;
    }

    .sa-card {
        background: #fff;
        border: 1px solid #edf0f7;
        border-radius: 22px;
        box-shadow: 0 10px 28px rgba(15, 23, 42, 0.06);
        overflow: hidden;
        margin-bottom: 18px;
    }

    .sa-card-header {
        padding: 15px 18px;
        border-bottom: 1px solid #eef0f4;
        background: #fbfcff;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        flex-wrap: wrap;
    }

    .sa-card-title {
        margin: 0;
        font-size: 16px;
        font-weight: 900;
        color: #111827;
    }

    .sa-card-body {
        padding: 18px;
    }

    .form-label {
        font-size: 12px;
        font-weight: 800;
        color: #374151;
        margin-bottom: 6px;
    }

    .form-control,
    .form-select {
        min-height: 42px;
        border-radius: 14px;
        border: 1px solid #dbe1ea;
        font-size: 14px;
        background-color: #fff;
    }

    .form-control:focus,
    .form-select:focus {
        border-color: #2563eb;
        box-shadow: 0 0 0 0.15rem rgba(37, 99, 235, 0.12);
    }

    .btn {
        border-radius: 14px;
        font-weight: 800;
    }

    .quick-filter-wrap {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        margin-top: 13px;
    }

    .quick-chip {
        border: 1px solid #dbe1ea;
        background: #fff;
        color: #374151;
        border-radius: 999px;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 800;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
    }

    .quick-chip.active {
        background: #2563eb;
        color: #fff;
        border-color: #2563eb;
    }

    .sa-table {
        margin-bottom: 0;
    }

    .sa-table thead th {
        background: #f8fafc;
        color: #374151;
        font-size: 12px;
        font-weight: 900;
        white-space: nowrap;
        border-bottom: 1px solid #e5e7eb;
        padding: 13px 12px;
        text-transform: uppercase;
        letter-spacing: 0.02em;
    }

    .sa-table tbody td {
        padding: 14px 12px;
        vertical-align: middle;
        font-size: 14px;
        color: #1f2937;
    }

    .sa-table tbody tr:hover {
        background: #f9fbff;
    }

    .sa-id {
        font-weight: 900;
        color: #111827;
    }

    .sa-vendor {
        font-weight: 900;
        color: #111827;
    }

    .sa-muted {
        font-size: 12px;
        color: #6b7280;
    }

    .sa-note {
        max-width: 220px;
        color: #6b7280;
        font-size: 12px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .sa-badge {
        display: inline-flex;
        align-items: center;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
    }

    .sa-badge-company {
        background: #eef2ff;
        color: #3730a3;
    }

    .sa-badge-paid {
        background: #ecfdf5;
        color: #047857;
    }

    .sa-badge-pending {
        background: #fff7ed;
        color: #c2410c;
    }

    .sa-badge-settled {
        background: #ecfdf5;
        color: #047857;
    }

    .sa-badge-none {
        background: #f3f4f6;
        color: #4b5563;
    }

    .sa-actions {
        display: flex;
        gap: 7px;
        flex-wrap: nowrap;
    }

    .desktop-table-wrap {
        display: block;
    }

    .mobile-card-wrap {
        display: none;
    }

    .purchase-mobile-card {
        border: 1px solid #e5e7eb;
        border-radius: 20px;
        padding: 15px;
        background: #fff;
        box-shadow: 0 8px 18px rgba(15, 23, 42, 0.05);
        margin-bottom: 14px;
    }

    .purchase-mobile-top {
        display: flex;
        justify-content: space-between;
        gap: 10px;
        align-items: flex-start;
        margin-bottom: 12px;
    }

    .purchase-mobile-title {
        font-size: 16px;
        font-weight: 950;
        color: #111827;
        margin-bottom: 3px;
    }

    .purchase-mobile-date {
        font-size: 12px;
        color: #6b7280;
    }

    .purchase-mobile-amount {
        text-align: right;
        font-size: 16px;
        font-weight: 950;
        color: #111827;
        white-space: nowrap;
    }

    .purchase-mobile-info {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
        margin: 12px 0;
    }

    .purchase-info-box {
        background: #f8fafc;
        border-radius: 14px;
        padding: 10px;
    }

    .purchase-info-label {
        color: #6b7280;
        font-size: 11px;
        font-weight: 800;
        margin-bottom: 4px;
    }

    .purchase-info-value {
        color: #111827;
        font-size: 13px;
        font-weight: 900;
    }

    .purchase-mobile-actions {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 8px;
        margin-top: 12px;
    }

    .sa-pagination-wrap {
        padding: 14px 18px;
        border-top: 1px solid #eef0f4;
        background: #fbfcff;
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 12px;
        flex-wrap: wrap;
    }

    .sa-page-info {
        font-size: 13px;
        color: #6b7280;
        font-weight: 700;
    }

    .pagination {
        margin-bottom: 0;
        gap: 5px;
        flex-wrap: wrap;
    }

    .page-link {
        border-radius: 12px !important;
        border: 1px solid #dbe1ea;
        color: #374151;
        font-weight: 800;
        font-size: 13px;
        min-width: 38px;
        text-align: center;
    }

    .page-item.active .page-link {
        background: #2563eb;
        border-color: #2563eb;
    }

    .empty-state {
        padding: 50px 18px;
        text-align: center;
        color: #6b7280;
    }

    .empty-icon {
        width: 58px;
        height: 58px;
        background: #f3f4f6;
        border-radius: 18px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 26px;
        margin-bottom: 12px;
    }

    .empty-state-title {
        font-size: 18px;
        font-weight: 900;
        color: #111827;
        margin-bottom: 4px;
    }

    @media (max-width: 768px) {
        .container-fluid {
            padding-left: 12px;
            padding-right: 12px;
        }

        .sa-topbar {
            border-radius: 18px;
            padding: 18px;
        }

        .sa-page-title {
            font-size: 21px;
        }

        .sa-btn-light {
            width: 100%;
            justify-content: center;
        }

        .sa-summary-grid {
            grid-template-columns: 1fr;
            gap: 10px;
        }

        .sa-summary-value {
            font-size: 22px;
        }

        .desktop-table-wrap {
            display: none;
        }

        .mobile-card-wrap {
            display: block;
            padding: 14px;
        }

        .sa-card-body {
            padding: 14px;
        }

        .sa-pagination-wrap {
            align-items: flex-start;
        }

        .pagination {
            width: 100%;
        }

        .page-link {
            min-width: 34px;
            padding: 6px 10px;
        }

        .purchase-mobile-info {
            grid-template-columns: 1fr 1fr;
        }
    }
</style>

<div class="container-fluid sa-page-wrap">

    <div class="sa-topbar">
        <div class="sa-topbar-content">
            <div>
                <h4 class="sa-page-title">📦 Raw Material Purchases</h4>
                <div class="sa-page-subtitle">
                    Manage vendor purchases, company-wise stock owner, paid-by company, settlement and material purchase records.
                </div>
            </div>

            <?php if (hasPermission('RAW_MATERIAL_PURCHASE', 'add')): ?>
                <a href="raw_material_purchase_add.php" class="sa-btn-light">
                    ➕ New Purchase
                </a>
            <?php endif; ?>
        </div>
    </div>

    <div class="sa-summary-grid">
        <div class="sa-summary-box">
            <div class="sa-summary-label">Total Purchase Amount</div>
            <div class="sa-summary-value">₹<?= number_format($total_purchase_amount, 2); ?></div>
            <div class="sa-summary-note">According to selected filters</div>
        </div>

        <div class="sa-summary-box">
            <div class="sa-summary-label">Total Purchases</div>
            <div class="sa-summary-value"><?= number_format($total_purchase_count); ?></div>
            <div class="sa-summary-note">Total purchase entries found</div>
        </div>

        <div class="sa-summary-box">
            <div class="sa-summary-label">Total Material Rows</div>
            <div class="sa-summary-value"><?= number_format($total_item_count); ?></div>
            <div class="sa-summary-note">Raw material rows in selected purchases</div>
        </div>
    </div>

    <div class="sa-card">
        <div class="sa-card-header">
            <h5 class="sa-card-title">🔎 Search & Filters</h5>

            <a href="raw_material_purchase_list.php" class="btn btn-sm btn-light border">
                Reset All
            </a>
        </div>

        <div class="sa-card-body">
            <form method="GET" class="row g-3 align-items-end">

                <input type="hidden" name="page" value="1">

                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Search</label>
                    <input type="text"
                           name="search"
                           class="form-control"
                           placeholder="Vendor, Purchase ID or Note"
                           value="<?= htmlspecialchars($search); ?>">
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">From Date</label>
                    <input type="date"
                           name="from_date"
                           class="form-control"
                           value="<?= htmlspecialchars($from_date); ?>">
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">To Date</label>
                    <input type="date"
                           name="to_date"
                           class="form-control"
                           value="<?= htmlspecialchars($to_date); ?>">
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Owner Company</label>
                    <select name="owner_company" class="form-select">
                        <option value="">All Owner</option>
                        <option value="SATHEE" <?= ($owner_company === 'SATHEE') ? 'selected' : ''; ?>>SATHEE</option>
                        <option value="CMD" <?= ($owner_company === 'CMD') ? 'selected' : ''; ?>>CMD</option>
                    </select>
                </div>

                <div class="col-lg-2 col-md-6">
                    <label class="form-label">Paid By Company</label>
                    <select name="paid_by_company" class="form-select">
                        <option value="">All Paid By</option>
                        <option value="SATHEE" <?= ($paid_by_company === 'SATHEE') ? 'selected' : ''; ?>>SATHEE</option>
                        <option value="CMD" <?= ($paid_by_company === 'CMD') ? 'selected' : ''; ?>>CMD</option>
                    </select>
                </div>

                <div class="col-lg-1 col-md-6">
                    <label class="form-label">Per Page</label>
                    <select name="per_page" class="form-select">
                        <option value="10" <?= ($per_page == 10) ? 'selected' : ''; ?>>10</option>
                        <option value="25" <?= ($per_page == 25) ? 'selected' : ''; ?>>25</option>
                        <option value="50" <?= ($per_page == 50) ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?= ($per_page == 100) ? 'selected' : ''; ?>>100</option>
                    </select>
                </div>

                <div class="col-lg-3 col-md-6">
                    <label class="form-label">Settlement Status</label>
                    <select name="settlement_status" class="form-select">
                        <option value="">All Status</option>
                        <option value="none" <?= ($settlement_status === 'none') ? 'selected' : ''; ?>>None</option>
                        <option value="pending" <?= ($settlement_status === 'pending') ? 'selected' : ''; ?>>Pending</option>
                        <option value="settled" <?= ($settlement_status === 'settled') ? 'selected' : ''; ?>>Settled</option>
                    </select>
                </div>

                <div class="col-lg-9 col-md-6 d-flex gap-2 flex-wrap">
                    <button type="submit" class="btn btn-primary">
                        🔍 Apply Filter
                    </button>

                    <a href="raw_material_purchase_list.php" class="btn btn-light border">
                        Clear
                    </a>
                </div>

                <div class="col-12">
                    <div class="quick-filter-wrap">
                        <a class="quick-chip <?= ($quick_range === 'today') ? 'active' : ''; ?>" 
                           href="?<?= buildQueryString(['quick_range' => 'today', 'page' => 1]); ?>">
                            Today
                        </a>

                        <a class="quick-chip <?= ($quick_range === 'yesterday') ? 'active' : ''; ?>" 
                           href="?<?= buildQueryString(['quick_range' => 'yesterday', 'page' => 1]); ?>">
                            Yesterday
                        </a>

                        <a class="quick-chip <?= ($quick_range === '7days') ? 'active' : ''; ?>" 
                           href="?<?= buildQueryString(['quick_range' => '7days', 'page' => 1]); ?>">
                            Last 7 Days
                        </a>

                        <a class="quick-chip <?= ($quick_range === '30days') ? 'active' : ''; ?>" 
                           href="?<?= buildQueryString(['quick_range' => '30days', 'page' => 1]); ?>">
                            Last 30 Days
                        </a>

                        <a class="quick-chip <?= ($quick_range === 'this_month') ? 'active' : ''; ?>" 
                           href="?<?= buildQueryString(['quick_range' => 'this_month', 'page' => 1]); ?>">
                            This Month
                        </a>

                        <a class="quick-chip <?= ($quick_range === 'last_month') ? 'active' : ''; ?>" 
                           href="?<?= buildQueryString(['quick_range' => 'last_month', 'page' => 1]); ?>">
                            Last Month
                        </a>
                    </div>
                </div>

            </form>
        </div>
    </div>

    <div class="sa-card">
        <div class="sa-card-header">
            <h5 class="sa-card-title">📋 Purchase List</h5>

            <div class="sa-page-info">
                Showing <?= number_format($start_record); ?> to <?= number_format($end_record); ?> of <?= number_format($total_purchase_count); ?>
            </div>
        </div>

        <!-- Desktop Table -->
        <div class="desktop-table-wrap table-responsive">
            <table class="table table-bordered table-hover sa-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Vendor</th>
                        <th>Date</th>
                        <th>Amount</th>
                        <th>Items</th>
                        <th>Owner</th>
                        <th>Paid By</th>
                        <th>Settlement</th>
                        <th>Created By</th>
                        <th>Note</th>
                        <th style="width: 150px;">Action</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (!empty($purchases)): ?>
                        <?php foreach ($purchases as $row): ?>

                            <?php
                            $status_class = 'sa-badge-none';

                            if ($row['settlement_status'] === 'pending') {
                                $status_class = 'sa-badge-pending';
                            } elseif ($row['settlement_status'] === 'settled') {
                                $status_class = 'sa-badge-settled';
                            }
                            ?>

                            <tr>
                                <td>
                                    <span class="sa-id">#<?= intval($row['purchase_id']); ?></span>
                                </td>

                                <td>
                                    <div class="sa-vendor">
                                        <?= htmlspecialchars($row['vendor_name']); ?>
                                    </div>
                                    <div class="sa-muted">
                                        <?= date('d M Y, h:i A', strtotime($row['created_at'])); ?>
                                    </div>
                                </td>

                                <td>
                                    <?= date('d M Y', strtotime($row['purchase_date'])); ?>
                                </td>

                                <td>
                                    <strong>₹<?= number_format(floatval($row['total_amount']), 2); ?></strong>
                                </td>

                                <td>
                                    <strong><?= intval($row['total_items']); ?></strong>
                                </td>

                                <td>
                                    <span class="sa-badge sa-badge-company">
                                        <?= htmlspecialchars($row['owner_company']); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="sa-badge sa-badge-paid">
                                        <?= htmlspecialchars($row['paid_by_company']); ?>
                                    </span>
                                </td>

                                <td>
                                    <span class="sa-badge <?= $status_class; ?>">
                                        <?= ucfirst(htmlspecialchars($row['settlement_status'])); ?>
                                    </span>
                                </td>

                                <td>
                                    <?= htmlspecialchars($row['created_by'] ?? '-'); ?>
                                </td>

                                <td>
                                    <div class="sa-note" title="<?= htmlspecialchars($row['note'] ?? ''); ?>">
                                        <?= htmlspecialchars($row['note'] ?: '-'); ?>
                                    </div>
                                </td>

                                <td>
                                    <div class="sa-actions">
                                        <button class="btn btn-primary btn-sm"
                                                onclick="viewItems(<?= intval($row['purchase_id']); ?>)">
                                            👁️
                                        </button>

                                        <?php if (hasPermission('RAW_MATERIAL_PURCHASE', 'edit')): ?>
                                            <a href="raw_material_purchase_edit.php?purchase_id=<?= intval($row['purchase_id']); ?>"
                                               class="btn btn-warning btn-sm">
                                                ✏️
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>

                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="11">
                                <div class="empty-state">
                                    <div class="empty-icon">📦</div>
                                    <div class="empty-state-title">No purchase found</div>
                                    <div>Try changing the filter or add a new raw material purchase.</div>
                                </div>
                            </td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Mobile Cards -->
        <div class="mobile-card-wrap">
            <?php if (!empty($purchases)): ?>
                <?php foreach ($purchases as $row): ?>

                    <?php
                    $status_class = 'sa-badge-none';

                    if ($row['settlement_status'] === 'pending') {
                        $status_class = 'sa-badge-pending';
                    } elseif ($row['settlement_status'] === 'settled') {
                        $status_class = 'sa-badge-settled';
                    }
                    ?>

                    <div class="purchase-mobile-card">

                        <div class="purchase-mobile-top">
                            <div>
                                <div class="purchase-mobile-title">
                                    #<?= intval($row['purchase_id']); ?> - <?= htmlspecialchars($row['vendor_name']); ?>
                                </div>
                                <div class="purchase-mobile-date">
                                    Purchase: <?= date('d M Y', strtotime($row['purchase_date'])); ?>
                                </div>
                            </div>

                            <div class="purchase-mobile-amount">
                                ₹<?= number_format(floatval($row['total_amount']), 2); ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <span class="sa-badge sa-badge-company">
                                Owner: <?= htmlspecialchars($row['owner_company']); ?>
                            </span>

                            <span class="sa-badge sa-badge-paid">
                                Paid: <?= htmlspecialchars($row['paid_by_company']); ?>
                            </span>

                            <span class="sa-badge <?= $status_class; ?>">
                                <?= ucfirst(htmlspecialchars($row['settlement_status'])); ?>
                            </span>
                        </div>

                        <div class="purchase-mobile-info">
                            <div class="purchase-info-box">
                                <div class="purchase-info-label">Items</div>
                                <div class="purchase-info-value">
                                    <?= intval($row['total_items']); ?>
                                </div>
                            </div>

                            <div class="purchase-info-box">
                                <div class="purchase-info-label">Created By</div>
                                <div class="purchase-info-value">
                                    <?= htmlspecialchars($row['created_by'] ?? '-'); ?>
                                </div>
                            </div>
                        </div>

                        <?php if (!empty($row['note'])): ?>
                            <div class="purchase-info-box">
                                <div class="purchase-info-label">Note</div>
                                <div class="purchase-info-value">
                                    <?= htmlspecialchars($row['note']); ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="purchase-mobile-actions">
                            <button class="btn btn-primary btn-sm"
                                    onclick="viewItems(<?= intval($row['purchase_id']); ?>)">
                                👁️ View
                            </button>

                            <?php if (hasPermission('RAW_MATERIAL_PURCHASE', 'edit')): ?>
                                <a href="raw_material_purchase_edit.php?purchase_id=<?= intval($row['purchase_id']); ?>"
                                   class="btn btn-warning btn-sm">
                                    ✏️ Edit
                                </a>
                            <?php endif; ?>
                        </div>

                    </div>

                <?php endforeach; ?>
            <?php else: ?>
                <div class="empty-state">
                    <div class="empty-icon">📦</div>
                    <div class="empty-state-title">No purchase found</div>
                    <div>Try changing the filter or add a new raw material purchase.</div>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($total_purchase_count > 0): ?>
            <div class="sa-pagination-wrap">
                <div class="sa-page-info">
                    Page <?= number_format($page); ?> of <?= number_format($total_pages); ?> 
                    | Showing <?= number_format($start_record); ?> - <?= number_format($end_record); ?>
                </div>

                <nav>
                    <ul class="pagination">

                        <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?= buildQueryString(['page' => 1]); ?>">
                                First
                            </a>
                        </li>

                        <li class="page-item <?= ($page <= 1) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?= buildQueryString(['page' => max(1, $page - 1)]); ?>">
                                Prev
                            </a>
                        </li>

                        <?php
                        $start_page = max(1, $page - 2);
                        $end_page = min($total_pages, $page + 2);

                        if ($start_page > 1) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }

                        for ($i = $start_page; $i <= $end_page; $i++):
                        ?>
                            <li class="page-item <?= ($i == $page) ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?= buildQueryString(['page' => $i]); ?>">
                                    <?= $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php
                        if ($end_page < $total_pages) {
                            echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                        }
                        ?>

                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?= buildQueryString(['page' => min($total_pages, $page + 1)]); ?>">
                                Next
                            </a>
                        </li>

                        <li class="page-item <?= ($page >= $total_pages) ? 'disabled' : ''; ?>">
                            <a class="page-link" href="?<?= buildQueryString(['page' => $total_pages]); ?>">
                                Last
                            </a>
                        </li>

                    </ul>
                </nav>
            </div>
        <?php endif; ?>

    </div>

</div>

<!-- View Modal -->
<div class="modal fade" id="viewModal" tabindex="-1" aria-labelledby="viewModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable modal-dialog-centered">
        <div class="modal-content" style="border-radius: 20px; overflow: hidden; border:0;">
            <div class="modal-header" style="background:#f8fafc; border-bottom:1px solid #e5e7eb;">
                <h5 class="modal-title" id="viewModalLabel" style="font-weight:900;">
                    🧪 Purchase Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>

            <div class="modal-body" id="modalContent">
                <div class="text-center text-muted py-4">
                    Loading purchase details...
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function viewItems(purchaseId) {
    const modalContent = document.getElementById('modalContent');

    modalContent.innerHTML = `
        <div class="text-center text-muted py-4">
            Loading purchase details...
        </div>
    `;

    fetch('get_purchase_items.php?purchase_id=' + encodeURIComponent(purchaseId))
        .then(response => response.text())
        .then(data => {
            modalContent.innerHTML = data;
            new bootstrap.Modal(document.getElementById('viewModal')).show();
        })
        .catch(error => {
            modalContent.innerHTML = `
                <div class="alert alert-danger mb-0">
                    Unable to load purchase details. Please try again.
                </div>
            `;
            new bootstrap.Modal(document.getElementById('viewModal')).show();
        });
}
</script>

<?php include('include/footer.php'); ?>