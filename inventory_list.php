<?php
require_once 'include/require_permission.php';
requirePermission('INVENTORY', 'view');
include('include/require_login.php');
include('include/db.php');
include('include/header.php');

/* =========================
   Helper Functions
========================= */
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function moneyFormat($value) {
    if ($value === null || $value === '' || !is_numeric($value)) {
        return '-';
    }
    return '₹' . number_format((float)$value, 2);
}

function buildQueryString($extra = []) {
    $query = array_merge($_GET, $extra);
    return http_build_query($query);
}

/* =========================
   Filters
========================= */
$search     = trim($_GET['search'] ?? '');
$item_type  = trim($_GET['item_type'] ?? '');
$source     = trim($_GET['source'] ?? '');
$from_date  = trim($_GET['from_date'] ?? '');
$to_date    = trim($_GET['to_date'] ?? '');

$page       = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page   = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;

$allowed_per_page = [10, 25, 50, 100];
if (!in_array($per_page, $allowed_per_page)) {
    $per_page = 25;
}

$offset = ($page - 1) * $per_page;

/* =========================
   Quick Date Filters
========================= */
$quick = $_GET['quick'] ?? '';

if ($quick === 'today') {
    $from_date = date('Y-m-d');
    $to_date = date('Y-m-d');
} elseif ($quick === 'yesterday') {
    $from_date = date('Y-m-d', strtotime('-1 day'));
    $to_date = date('Y-m-d', strtotime('-1 day'));
} elseif ($quick === '7days') {
    $from_date = date('Y-m-d', strtotime('-6 days'));
    $to_date = date('Y-m-d');
} elseif ($quick === '30days') {
    $from_date = date('Y-m-d', strtotime('-29 days'));
    $to_date = date('Y-m-d');
} elseif ($quick === 'this_month') {
    $from_date = date('Y-m-01');
    $to_date = date('Y-m-d');
} elseif ($quick === 'last_month') {
    $from_date = date('Y-m-01', strtotime('first day of last month'));
    $to_date = date('Y-m-t', strtotime('last day of last month'));
}

/* =========================
   WHERE Condition
========================= */
$where = [];
$params = [];
$types = '';

if ($search !== '') {
    $where[] = "(
        i.inventory_id LIKE ?
        OR p.title LIKE ?
        OR r.material_name LIKE ?
        OR i.unit LIKE ?
        OR i.source LIKE ?
        OR i.note LIKE ?
    )";

    $search_like = "%{$search}%";
    for ($i = 0; $i < 6; $i++) {
        $params[] = $search_like;
        $types .= 's';
    }
}

if ($item_type !== '' && in_array($item_type, ['product', 'raw_material'])) {
    $where[] = "i.item_type = ?";
    $params[] = $item_type;
    $types .= 's';
}

if ($source !== '') {
    $where[] = "i.source = ?";
    $params[] = $source;
    $types .= 's';
}

if ($from_date !== '') {
    $where[] = "DATE(i.created_at) >= ?";
    $params[] = $from_date;
    $types .= 's';
}

if ($to_date !== '') {
    $where[] = "DATE(i.created_at) <= ?";
    $params[] = $to_date;
    $types .= 's';
}

$where_sql = '';
if (!empty($where)) {
    $where_sql = 'WHERE ' . implode(' AND ', $where);
}

/* =========================
   Total Count
========================= */
$count_sql = "
    SELECT COUNT(*) AS total
    FROM inventory i
    LEFT JOIN products p 
        ON i.item_type = 'product' 
        AND i.item_id = p.product_id
    LEFT JOIN raw_materials r 
        ON i.item_type = 'raw_material' 
        AND i.item_id = r.raw_material_id
    $where_sql
";

$count_stmt = $mysqli->prepare($count_sql);

if (!empty($params)) {
    $count_stmt->bind_param($types, ...$params);
}

$count_stmt->execute();
$count_result = $count_stmt->get_result();
$total_rows = (int)($count_result->fetch_assoc()['total'] ?? 0);
$total_pages = max(1, ceil($total_rows / $per_page));

if ($page > $total_pages) {
    $page = $total_pages;
    $offset = ($page - 1) * $per_page;
}

/* =========================
   Summary Count
========================= */
$summary_sql = "
    SELECT 
        COUNT(*) AS total_entries,
        SUM(CASE WHEN i.item_type = 'product' THEN 1 ELSE 0 END) AS product_entries,
        SUM(CASE WHEN i.item_type = 'raw_material' THEN 1 ELSE 0 END) AS raw_material_entries,
        COALESCE(SUM(i.qty_change), 0) AS total_qty_change
    FROM inventory i
    LEFT JOIN products p 
        ON i.item_type = 'product' 
        AND i.item_id = p.product_id
    LEFT JOIN raw_materials r 
        ON i.item_type = 'raw_material' 
        AND i.item_id = r.raw_material_id
    $where_sql
";

$summary_stmt = $mysqli->prepare($summary_sql);

if (!empty($params)) {
    $summary_stmt->bind_param($types, ...$params);
}

$summary_stmt->execute();
$summary = $summary_stmt->get_result()->fetch_assoc();

$total_entries = (int)($summary['total_entries'] ?? 0);
$product_entries = (int)($summary['product_entries'] ?? 0);
$raw_material_entries = (int)($summary['raw_material_entries'] ?? 0);
$total_qty_change = (float)($summary['total_qty_change'] ?? 0);

/* =========================
   Source Dropdown
========================= */
$source_options = [];
$source_result = $mysqli->query("
    SELECT DISTINCT source 
    FROM inventory 
    WHERE source IS NOT NULL 
    AND source != '' 
    ORDER BY source ASC
");

if ($source_result) {
    while ($srow = $source_result->fetch_assoc()) {
        $source_options[] = $srow['source'];
    }
}

/* =========================
   Main Data Query
========================= */
$data_sql = "
    SELECT 
        i.*,
        CASE 
            WHEN i.item_type = 'product' THEN p.title
            WHEN i.item_type = 'raw_material' THEN r.material_name
            ELSE 'Unknown'
        END AS item_name
    FROM inventory i
    LEFT JOIN products p 
        ON i.item_type = 'product' 
        AND i.item_id = p.product_id
    LEFT JOIN raw_materials r 
        ON i.item_type = 'raw_material' 
        AND i.item_id = r.raw_material_id
    $where_sql
    ORDER BY i.created_at DESC
    LIMIT ? OFFSET ?
";

$data_stmt = $mysqli->prepare($data_sql);

$data_params = $params;
$data_types = $types . 'ii';
$data_params[] = $per_page;
$data_params[] = $offset;

$data_stmt->bind_param($data_types, ...$data_params);
$data_stmt->execute();
$result = $data_stmt->get_result();
?>

<style>
    .inventory-page {
        background: #f7f8fb;
        min-height: calc(100vh - 80px);
    }

    .inv-header-card {
        border: 0;
        border-radius: 18px;
        background: linear-gradient(135deg, #ffffff, #f2f5ff);
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }

    .inv-title {
        font-weight: 800;
        color: #172033;
        letter-spacing: -0.3px;
    }

    .inv-subtitle {
        color: #6b7280;
        font-size: 14px;
    }

    .summary-card {
        border: 0;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.06);
        height: 100%;
    }

    .summary-label {
        color: #6b7280;
        font-size: 13px;
        font-weight: 600;
    }

    .summary-value {
        color: #111827;
        font-size: 24px;
        font-weight: 800;
        line-height: 1.2;
    }

    .filter-card {
        border: 0;
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
    }

    .inventory-table-card {
        border: 0;
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.06);
        overflow: hidden;
    }

    .inventory-table {
        margin-bottom: 0;
        white-space: nowrap;
    }

    .inventory-table thead th {
        background: #f3f4f6;
        color: #374151;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: 0.4px;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: middle;
    }

    .inventory-table tbody td {
        vertical-align: middle;
        font-size: 14px;
        color: #374151;
    }

    .item-name {
        font-weight: 700;
        color: #111827;
        min-width: 220px;
        max-width: 320px;
        white-space: normal;
    }

    .type-badge {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 10px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
    }

    .type-product {
        background: #e8f2ff;
        color: #075985;
    }

    .type-raw {
        background: #fff7ed;
        color: #9a3412;
    }

    .qty-positive {
        color: #15803d;
        font-weight: 800;
    }

    .qty-negative {
        color: #dc2626;
        font-weight: 800;
    }

    .note-cell {
        max-width: 260px;
        white-space: normal;
        color: #6b7280;
    }

    .source-pill {
        background: #f3f4f6;
        color: #374151;
        padding: 5px 9px;
        border-radius: 999px;
        font-size: 12px;
        font-weight: 700;
        display: inline-block;
    }

    .action-btn {
        border-radius: 10px;
        font-weight: 700;
    }

    .mobile-inv-card {
        border: 0;
        border-radius: 18px;
        background: #ffffff;
        box-shadow: 0 8px 22px rgba(15, 23, 42, 0.07);
        margin-bottom: 14px;
    }

    .mobile-inv-card .label {
        font-size: 12px;
        color: #6b7280;
        font-weight: 700;
    }

    .mobile-inv-card .value {
        font-size: 14px;
        color: #111827;
        font-weight: 700;
    }

    .quick-filter a {
        border-radius: 999px;
        font-size: 13px;
        font-weight: 700;
    }

    .pagination .page-link {
        border-radius: 10px;
        margin: 0 3px;
        color: #111827;
        font-weight: 700;
    }

    .pagination .active .page-link {
        background: #2563eb;
        border-color: #2563eb;
        color: #fff;
    }

    @media (max-width: 767px) {
        .container-fluid {
            padding-left: 12px;
            padding-right: 12px;
        }

        .inv-title {
            font-size: 20px;
        }

        .summary-value {
            font-size: 20px;
        }

        .desktop-table-wrapper {
            display: none;
        }
    }

    @media (min-width: 768px) {
        .mobile-card-wrapper {
            display: none;
        }
    }
</style>

<div class="inventory-page">
    <div class="container-fluid py-4">

        <!-- Header -->
        <div class="card inv-header-card mb-4">
            <div class="card-body">
                <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
                    <div>
                        <h4 class="inv-title mb-1">📦 Inventory List</h4>
                        <div class="inv-subtitle">
                            Products + Raw Materials inventory entries with search, filter and paging.
                        </div>
                    </div>

                    <div class="d-flex gap-2 flex-wrap">
                        <a href="add_inventory.php" class="btn btn-primary action-btn">
                            ➕ Add Inventory
                        </a>
                        <a href="inventory_list.php" class="btn btn-light border action-btn">
                            Reset
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row g-3 mb-4">
            <div class="col-6 col-lg-3">
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="summary-label">Total Entries</div>
                        <div class="summary-value"><?= number_format($total_entries) ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="summary-label">Product Entries</div>
                        <div class="summary-value"><?= number_format($product_entries) ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="summary-label">Raw Material Entries</div>
                        <div class="summary-value"><?= number_format($raw_material_entries) ?></div>
                    </div>
                </div>
            </div>

            <div class="col-6 col-lg-3">
                <div class="card summary-card">
                    <div class="card-body">
                        <div class="summary-label">Qty Change Total</div>
                        <div class="summary-value"><?= number_format($total_qty_change, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card filter-card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end">

                    <div class="col-12 col-md-4 col-lg-3">
                        <label class="form-label fw-bold">Search</label>
                        <input 
                            type="text" 
                            name="search" 
                            class="form-control"
                            placeholder="Name, ID, source, note..."
                            value="<?= h($search) ?>"
                        >
                    </div>

                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label fw-bold">Type</label>
                        <select name="item_type" class="form-select">
                            <option value="">All Types</option>
                            <option value="product" <?= $item_type === 'product' ? 'selected' : '' ?>>Product</option>
                            <option value="raw_material" <?= $item_type === 'raw_material' ? 'selected' : '' ?>>Raw Material</option>
                        </select>
                    </div>

                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label fw-bold">Source</label>
                        <select name="source" class="form-select">
                            <option value="">All Sources</option>
                            <?php foreach ($source_options as $src): ?>
                                <option value="<?= h($src) ?>" <?= $source === $src ? 'selected' : '' ?>>
                                    <?= h($src) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label fw-bold">From</label>
                        <input type="date" name="from_date" class="form-control" value="<?= h($from_date) ?>">
                    </div>

                    <div class="col-6 col-md-3 col-lg-2">
                        <label class="form-label fw-bold">To</label>
                        <input type="date" name="to_date" class="form-control" value="<?= h($to_date) ?>">
                    </div>

                    <div class="col-6 col-md-2 col-lg-1">
                        <label class="form-label fw-bold">Rows</label>
                        <select name="per_page" class="form-select">
                            <?php foreach ($allowed_per_page as $num): ?>
                                <option value="<?= $num ?>" <?= $per_page === $num ? 'selected' : '' ?>>
                                    <?= $num ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <input type="hidden" name="page" value="1">

                    <div class="col-12">
                        <div class="d-flex flex-column flex-md-row justify-content-between gap-3">
                            <div class="quick-filter d-flex flex-wrap gap-2">
                                <a href="inventory_list.php?<?= buildQueryString(['quick' => 'today', 'page' => 1]) ?>" class="btn btn-sm btn-light border">Today</a>
                                <a href="inventory_list.php?<?= buildQueryString(['quick' => 'yesterday', 'page' => 1]) ?>" class="btn btn-sm btn-light border">Yesterday</a>
                                <a href="inventory_list.php?<?= buildQueryString(['quick' => '7days', 'page' => 1]) ?>" class="btn btn-sm btn-light border">7 Days</a>
                                <a href="inventory_list.php?<?= buildQueryString(['quick' => '30days', 'page' => 1]) ?>" class="btn btn-sm btn-light border">30 Days</a>
                                <a href="inventory_list.php?<?= buildQueryString(['quick' => 'this_month', 'page' => 1]) ?>" class="btn btn-sm btn-light border">This Month</a>
                                <a href="inventory_list.php?<?= buildQueryString(['quick' => 'last_month', 'page' => 1]) ?>" class="btn btn-sm btn-light border">Last Month</a>
                            </div>

                            <div class="d-flex gap-2">
                                <button type="submit" class="btn btn-primary action-btn">
                                    🔍 Apply Filter
                                </button>
                                <a href="inventory_list.php" class="btn btn-outline-secondary action-btn">
                                    Clear
                                </a>
                            </div>
                        </div>
                    </div>

                </form>
            </div>
        </div>

        <!-- Result Info -->
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3 gap-2">
            <div class="fw-bold text-muted">
                Showing 
                <?= $total_rows > 0 ? number_format($offset + 1) : 0 ?> 
                to 
                <?= number_format(min($offset + $per_page, $total_rows)) ?> 
                of 
                <?= number_format($total_rows) ?> 
                entries
            </div>

            <div class="fw-bold text-muted">
                Page <?= number_format($page) ?> of <?= number_format($total_pages) ?>
            </div>
        </div>

        <!-- Desktop Table -->
        <div class="card inventory-table-card desktop-table-wrapper">
            <div class="table-responsive">
                <table class="table table-hover align-middle inventory-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Type</th>
                            <th>Name</th>
                            <th>Qty</th>
                            <th>Unit</th>
                            <th>Wholesale</th>
                            <th>Retail</th>
                            <th>Cost</th>
                            <th>Source</th>
                            <th>Note</th>
                            <th>Date</th>
                            <th class="text-end">Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($result->num_rows > 0): ?>
                            <?php while ($row = $result->fetch_assoc()): ?>
                                <?php
                                    $qty = (float)$row['qty_change'];
                                    $qty_class = $qty < 0 ? 'qty-negative' : 'qty-positive';

                                    $type_label = $row['item_type'] === 'raw_material' ? 'Raw Material' : 'Product';
                                    $type_class = $row['item_type'] === 'raw_material' ? 'type-raw' : 'type-product';
                                ?>
                                <tr>
                                    <td class="fw-bold">#<?= (int)$row['inventory_id'] ?></td>

                                    <td>
                                        <span class="type-badge <?= $type_class ?>">
                                            <?= $row['item_type'] === 'raw_material' ? '🧪' : '📦' ?>
                                            <?= h($type_label) ?>
                                        </span>
                                    </td>

                                    <td class="item-name">
                                        <?= h($row['item_name'] ?: 'Unknown') ?>
                                    </td>

                                    <td class="<?= $qty_class ?>">
                                        <?= number_format($qty, 2) ?>
                                    </td>

                                    <td><?= h($row['unit'] ?? '-') ?></td>

                                    <td><?= moneyFormat($row['wholesale_price'] ?? null) ?></td>

                                    <td><?= moneyFormat($row['retail_price'] ?? null) ?></td>

                                    <td><?= moneyFormat($row['cost_price'] ?? null) ?></td>

                                    <td>
                                        <span class="source-pill">
                                            <?= h($row['source'] ?: '-') ?>
                                        </span>
                                    </td>

                                    <td class="note-cell">
                                        <?= h($row['note'] ?: '-') ?>
                                    </td>

                                    <td>
                                        <?= !empty($row['created_at']) ? date('d M Y, h:i A', strtotime($row['created_at'])) : '-' ?>
                                    </td>

                                    <td class="text-end">
                                        <div class="btn-group">
                                            <form action="edit_inventory.php" method="POST" class="d-inline">
                                                <input type="hidden" name="inventory_id" value="<?= (int)$row['inventory_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary action-btn">
                                                    Edit
                                                </button>
                                            </form>

                                            <form 
                                                action="delete_inventory.php" 
                                                method="POST" 
                                                class="d-inline ms-1"
                                                onsubmit="return confirm('Are you sure you want to delete this inventory entry?');"
                                            >
                                                <input type="hidden" name="inventory_id" value="<?= (int)$row['inventory_id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-danger action-btn">
                                                    Delete
                                                </button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php else: ?>
                            <tr>
                                <td colspan="12" class="text-center py-5">
                                    <div class="fw-bold fs-5">No inventory entries found</div>
                                    <div class="text-muted">Try changing search or filters.</div>
                                </td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Mobile Cards -->
        <div class="mobile-card-wrapper">
            <?php
            $data_stmt->execute();
            $mobile_result = $data_stmt->get_result();
            ?>

            <?php if ($mobile_result->num_rows > 0): ?>
                <?php while ($row = $mobile_result->fetch_assoc()): ?>
                    <?php
                        $qty = (float)$row['qty_change'];
                        $qty_class = $qty < 0 ? 'qty-negative' : 'qty-positive';

                        $type_label = $row['item_type'] === 'raw_material' ? 'Raw Material' : 'Product';
                        $type_class = $row['item_type'] === 'raw_material' ? 'type-raw' : 'type-product';
                    ?>

                    <div class="card mobile-inv-card">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                                <div>
                                    <div class="text-muted fw-bold small">
                                        #<?= (int)$row['inventory_id'] ?>
                                    </div>
                                    <div class="fw-bold fs-6">
                                        <?= h($row['item_name'] ?: 'Unknown') ?>
                                    </div>
                                </div>

                                <span class="type-badge <?= $type_class ?>">
                                    <?= $row['item_type'] === 'raw_material' ? '🧪' : '📦' ?>
                                    <?= h($type_label) ?>
                                </span>
                            </div>

                            <div class="row g-3">
                                <div class="col-6">
                                    <div class="label">Qty</div>
                                    <div class="value <?= $qty_class ?>">
                                        <?= number_format($qty, 2) ?>
                                    </div>
                                </div>

                                <div class="col-6">
                                    <div class="label">Unit</div>
                                    <div class="value"><?= h($row['unit'] ?? '-') ?></div>
                                </div>

                                <div class="col-4">
                                    <div class="label">Wholesale</div>
                                    <div class="value"><?= moneyFormat($row['wholesale_price'] ?? null) ?></div>
                                </div>

                                <div class="col-4">
                                    <div class="label">Retail</div>
                                    <div class="value"><?= moneyFormat($row['retail_price'] ?? null) ?></div>
                                </div>

                                <div class="col-4">
                                    <div class="label">Cost</div>
                                    <div class="value"><?= moneyFormat($row['cost_price'] ?? null) ?></div>
                                </div>

                                <div class="col-6">
                                    <div class="label">Source</div>
                                    <div class="value"><?= h($row['source'] ?: '-') ?></div>
                                </div>

                                <div class="col-6">
                                    <div class="label">Date</div>
                                    <div class="value">
                                        <?= !empty($row['created_at']) ? date('d M Y', strtotime($row['created_at'])) : '-' ?>
                                    </div>
                                </div>

                                <div class="col-12">
                                    <div class="label">Note</div>
                                    <div class="value"><?= h($row['note'] ?: '-') ?></div>
                                </div>
                            </div>

                            <div class="d-flex gap-2 mt-3">
                                <form action="edit_inventory.php" method="POST" class="w-50">
                                    <input type="hidden" name="inventory_id" value="<?= (int)$row['inventory_id'] ?>">
                                    <button type="submit" class="btn btn-outline-primary w-100 action-btn">
                                        Edit
                                    </button>
                                </form>

                                <form 
                                    action="delete_inventory.php" 
                                    method="POST" 
                                    class="w-50"
                                    onsubmit="return confirm('Are you sure you want to delete this inventory entry?');"
                                >
                                    <input type="hidden" name="inventory_id" value="<?= (int)$row['inventory_id'] ?>">
                                    <button type="submit" class="btn btn-outline-danger w-100 action-btn">
                                        Delete
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>

                <?php endwhile; ?>
            <?php else: ?>
                <div class="card mobile-inv-card">
                    <div class="card-body text-center py-5">
                        <div class="fw-bold fs-5">No inventory entries found</div>
                        <div class="text-muted">Try changing search or filters.</div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <nav class="mt-4">
                <ul class="pagination justify-content-center flex-wrap">

                    <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                        <a class="page-link" href="inventory_list.php?<?= buildQueryString(['page' => max(1, $page - 1)]) ?>">
                            Previous
                        </a>
                    </li>

                    <?php
                    $start_page = max(1, $page - 2);
                    $end_page = min($total_pages, $page + 2);

                    if ($start_page > 1):
                    ?>
                        <li class="page-item">
                            <a class="page-link" href="inventory_list.php?<?= buildQueryString(['page' => 1]) ?>">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                            <li class="page-item disabled">
                                <span class="page-link">...</span>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                            <a class="page-link" href="inventory_list.php?<?= buildQueryString(['page' => $i]) ?>">
                                <?= $i ?>
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
                            <a class="page-link" href="inventory_list.php?<?= buildQueryString(['page' => $total_pages]) ?>">
                                <?= $total_pages ?>
                            </a>
                        </li>
                    <?php endif; ?>

                    <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                        <a class="page-link" href="inventory_list.php?<?= buildQueryString(['page' => min($total_pages, $page + 1)]) ?>">
                            Next
                        </a>
                    </li>

                </ul>
            </nav>
        <?php endif; ?>

    </div>
</div>

<?php include('include/footer.php'); ?>