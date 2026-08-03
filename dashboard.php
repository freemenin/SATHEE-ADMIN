<?php
require_once 'include/require_login.php';
require_once 'include/permission_helper.php';
require_once 'include/dashboard_helper.php';
require_once 'include/dashboard_data.php';

$currentRole = getCurrentUserRole();
if (!$currentRole || $currentRole['user_status'] !== 'active' || $currentRole['role_status'] !== 'active') {
    session_destroy();
    header('Location: login.php?error=inactive_role');
    exit;
}

$departmentCode = strtoupper(trim((string)($currentRole['department_code'] ?? 'GENERAL')));
$isAdminDashboard = $departmentCode === 'ADMIN' || isAdministrator();
$allowedWidgets = getAllowedDashboardWidgets();

$salesData = [];
$productionData = [];
$purchaseData = [];
$adminData = [];
$generalData = [];

if ($isAdminDashboard) {
    $salesData = getSalesDashboardData();
    $productionData = getProductionDashboardData();
    $purchaseData = getPurchaseDashboardData();
    $adminData = getAdminDashboardData();
} elseif ($departmentCode === 'SALES' && hasPermission('ORDERS', 'view')) {
    // Non-admin sales users only see their own order numbers, not the whole company's.
    $salesData = getSalesDashboardData((int)($_SESSION['user_id'] ?? 0));
} elseif ($departmentCode === 'PRODUCTION' && (hasPermission('BATCHES', 'view') || hasPermission('PRODUCTS', 'view'))) {
    $productionData = getProductionDashboardData();
} elseif ($departmentCode === 'PURCHASE' && hasPermission('RAW_MATERIALS', 'view')) {
    $purchaseData = getPurchaseDashboardData();
} else {
    $generalData = getGeneralDashboardData();
}

$dashboardTitles = [
    'ADMIN' => 'Administration Dashboard',
    'SALES' => 'Sales Dashboard',
    'PRODUCTION' => 'Production Dashboard',
    'PURCHASE' => 'Purchase Dashboard',
    'GENERAL' => 'My Dashboard',
];
$dashboardSubtitles = [
    'ADMIN' => 'Complete sales, production, purchase and administration overview.',
    'SALES' => 'Order activity, assignment status and recent sales workflow.',
    'PRODUCTION' => 'Product, batch and production quantity overview.',
    'PURCHASE' => 'Raw material stock and consumption overview.',
    'GENERAL' => 'Your dashboard is ready. Use your assigned shortcuts below.',
];

function h($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function dashboardNumber($value): string
{
    return number_format((float)$value, ((float)$value == (int)$value ? 0 : 2));
}

function dashboardMoney($value): string
{
    return '₹' . number_format((float)$value, 0);
}

function dashboardKpiCard(string $title, $value, string $icon, string $class = 'primary', string $subtitle = ''): void
{
    ?>
    <div class="col-6 col-xl-3">
        <div class="card border-0 shadow-sm dashboard-kpi h-100">
            <div class="card-body">
                <div class="d-flex align-items-start justify-content-between gap-3">
                    <div>
                        <div class="text-muted small fw-semibold"><?= h($title) ?></div>
                        <div class="fs-3 fw-bold mt-1"><?= h($value) ?></div>
                    </div>
                    <div class="dashboard-kpi-icon text-bg-<?= h($class) ?>">
                        <i class="<?= h($icon) ?>"></i>
                    </div>
                </div>
                <?php if ($subtitle !== ''): ?>
                    <div class="text-muted small mt-2"><?= h($subtitle) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php
}

function dashboardQuickLinks(array $shortcuts): void
{
    if (!$shortcuts) {
        echo '<div class="text-muted small">No permitted shortcuts are available.</div>';
        return;
    }
    echo '<div class="d-flex flex-wrap gap-2">';
    foreach ($shortcuts as $page) {
        $icon = trim($page['icon_class'] ?? '') ?: 'bi bi-arrow-right-circle';
        echo '<a class="btn btn-sm btn-primary" href="' . h($page['page_url'] ?? '#') . '">';
        echo '<i class="' . h($icon) . ' me-1"></i>' . h($page['page_name'] ?? 'Open') . '</a>';
    }
    echo '</div>';
}

$preferredShortcuts = [
    'SALES' => ['ORDERS', 'PENDING_ORDERS', 'CUSTOMERS'],
    'PRODUCTION' => ['PRODUCTS', 'BATCHES', 'PRODUCT_REPORT', 'REPORTS'],
    'PURCHASE' => ['RAW_MATERIALS', 'RAW_MATERIAL_PURCHASE', 'RAW_MATERIAL_REPORT', 'PURCHASE_REQUESTS', 'REPORTS'],
    'ADMIN' => [],
    'GENERAL' => [],
];

include('include/header.php');
?>

<div class="container-fluid py-4 dashboard-page">
    <div class="card border-0 shadow-sm mb-3">
        <div class="card-body d-flex flex-wrap align-items-center justify-content-between gap-3">
            <div>
                <div class="text-muted small fw-semibold"><?= h(date('l, d M Y')) ?></div>
                <h4 class="fw-bold mb-1">Good <?= h(date('A') === 'AM' ? 'Morning' : 'Evening') ?>, <?= h($currentRole['name'] ?: ($_SESSION['user_name'] ?? 'User')) ?></h4>
                <div class="text-muted"><?= h($dashboardTitles[$departmentCode] ?? 'My Dashboard') ?> · <?= h($currentRole['role_name'] ?? '') ?></div>
                <div class="small text-muted mt-1"><?= h($dashboardSubtitles[$departmentCode] ?? $dashboardSubtitles['GENERAL']) ?></div>
            </div>
            <div class="text-end">
                <span class="badge text-bg-light border"><?= h($departmentCode) ?></span>
                <div class="small text-muted mt-2">Last updated <?= h(date('h:i A')) ?></div>
            </div>
        </div>
    </div>

    <?php if ($isAdminDashboard && canViewFinancialAmounts()): ?>
        <div class="row g-3 mb-3">
            <?php
            dashboardKpiCard('Total Revenue', dashboardMoney($adminData['total_order_amount'] ?? 0), 'bi bi-currency-rupee', 'success', 'All order amount');
            dashboardKpiCard('Monthly Revenue', dashboardMoney($adminData['month_order_amount'] ?? 0), 'bi bi-cash-stack', 'success', 'Current month');
            dashboardKpiCard('Delivered Revenue', dashboardMoney($adminData['delivered_order_amount'] ?? 0), 'bi bi-check-circle', 'info', 'Delivered orders');
            dashboardKpiCard('Users', (int)($adminData['total_users'] ?? 0), 'bi bi-people', 'secondary', 'Registered users');
            ?>
        </div>
    <?php endif; ?>

    <?php if ($salesData): ?>
        <section class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="fw-bold mb-0">Sales</h5>
                <?php dashboardQuickLinks(dashboardPermittedShortcuts($preferredShortcuts['SALES'])); ?>
            </div>
            <div class="row g-3 mb-3">
                <?php
                dashboardKpiCard('Total Orders', $salesData['total_orders'] ?? 0, 'bi bi-cart', 'primary');
                dashboardKpiCard('Orders Today', $salesData['today_orders'] ?? 0, 'bi bi-calendar-day', 'info');
                dashboardKpiCard('Not Assigned', $salesData['unassigned_orders'] ?? 0, 'bi bi-person-x', 'warning');
                dashboardKpiCard('Change Distributor', $salesData['change_distributor_pending'] ?? 0, 'bi bi-arrow-repeat', 'danger');
                dashboardKpiCard('Cancelled', $salesData['cancelled_orders'] ?? 0, 'bi bi-x-circle', 'secondary');
                dashboardKpiCard('Delivered', $salesData['delivered_orders'] ?? 0, 'bi bi-truck', 'success');
                dashboardKpiCard('Ready to Delivery', $salesData['ready_delivery_orders'] ?? 0, 'bi bi-box-arrow-up', 'info');
                dashboardKpiCard('Repeat Orders', $salesData['repeat_orders'] ?? 0, 'bi bi-repeat', 'primary');
                ?>
            </div>

            <div class="row g-3 mb-3">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">Last 30 Days Order Trend</div>
                        <div class="card-body dashboard-chart"><canvas id="salesTrendChart"></canvas><div class="dashboard-empty d-none">No dashboard data is available for the selected period.</div></div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">Order Status</div>
                        <div class="card-body dashboard-chart"><canvas id="salesStatusChart"></canvas><div class="dashboard-empty d-none">No dashboard data is available for the selected period.</div></div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm mb-3">
                <div class="card-body">
                    <div class="row g-3 align-items-center">
                        <div class="col-md-4"><div class="text-muted small">Current Month Orders</div><div class="fs-4 fw-bold"><?= (int)($salesData['current_month_orders'] ?? 0) ?></div></div>
                        <div class="col-md-4"><div class="text-muted small">Last Month Same Period</div><div class="fs-4 fw-bold"><?= (int)($salesData['last_month_same_period_orders'] ?? 0) ?></div></div>
                        <div class="col-md-4"><div class="text-muted small">Growth</div><div class="fs-4 fw-bold"><?= h(number_format((float)($salesData['order_growth_percentage'] ?? 0), 2)) ?>%</div></div>
                    </div>
                </div>
            </div>

            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Recent Orders</div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Order Status</th><th>Delivery Status</th><th>Distributor</th></tr></thead>
                        <tbody>
                        <?php foreach (($salesData['recent_orders'] ?? []) as $order): ?>
                            <tr>
                                <td><?= h($order['invoice_number'] ?? '-') ?></td>
                                <td><?= h($order['customer_name'] ?? '-') ?></td>
                                <td><?= h($order['order_date'] ?? '-') ?></td>
                                <td><span class="badge text-bg-light border"><?= h($order['order_status'] ?: '-') ?></span></td>
                                <td><?= h($order['delivery_status'] ?: '-') ?></td>
                                <td><?= h($order['distributor_name'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($salesData['recent_orders'])): ?><tr><td colspan="6" class="text-muted">No recent orders found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($productionData): ?>
        <section class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="fw-bold mb-0">Production</h5>
                <?php dashboardQuickLinks(dashboardPermittedShortcuts($preferredShortcuts['PRODUCTION'])); ?>
            </div>
            <div class="row g-3 mb-3">
                <?php
                dashboardKpiCard('Active Products', $productionData['active_products'] ?? 0, 'bi bi-box', 'primary');
                dashboardKpiCard('Total Batches', $productionData['total_batches'] ?? 0, 'bi bi-collection', 'info');
                dashboardKpiCard('Batches Today', $productionData['today_batches'] ?? 0, 'bi bi-calendar-check', 'success');
                dashboardKpiCard('Monthly Batches', $productionData['month_batches'] ?? 0, 'bi bi-calendar3', 'secondary');
                dashboardKpiCard('Production Today', dashboardNumber($productionData['today_production_qty'] ?? 0), 'bi bi-boxes', 'warning');
                dashboardKpiCard('Monthly Production', dashboardNumber($productionData['month_production_qty'] ?? 0), 'bi bi-graph-up', 'primary');
                dashboardKpiCard('SATHEE Batches', $productionData['sathee_batches'] ?? 0, 'bi bi-award', 'success');
                dashboardKpiCard('CMD Batches', $productionData['cmd_batches'] ?? 0, 'bi bi-diagram-3', 'info');
                ?>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold">Daily Production</div><div class="card-body dashboard-chart"><canvas id="productionTrendChart"></canvas><div class="dashboard-empty d-none">No dashboard data is available for the selected period.</div></div></div></div>
                <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold">Product-wise Quantity</div><div class="card-body dashboard-chart"><canvas id="productionProductChart"></canvas><div class="dashboard-empty d-none">No dashboard data is available for the selected period.</div></div></div></div>
            </div>
            <div class="card border-0 shadow-sm">
                <div class="card-header bg-white fw-semibold">Recent Production Batches</div>
                <div class="card-body table-responsive">
                    <table class="table table-sm align-middle">
                        <thead class="table-light"><tr><th>Batch</th><th>Product</th><th>Qty</th><th>Production Date</th><th>Owner</th><th>Created By</th></tr></thead>
                        <tbody>
                        <?php foreach (($productionData['recent_batches'] ?? []) as $batch): ?>
                            <tr><td><?= h($batch['batch_code'] ?? '-') ?></td><td><?= h($batch['product_name'] ?? '-') ?></td><td><?= h(dashboardNumber($batch['product_qty'] ?? 0)) ?></td><td><?= h($batch['production_date'] ?? '-') ?></td><td><?= h($batch['batch_owner'] ?? '-') ?></td><td><?= h($batch['created_by_name'] ?? '-') ?></td></tr>
                        <?php endforeach; ?>
                        <?php if (empty($productionData['recent_batches'])): ?><tr><td colspan="6" class="text-muted">No recent batches found.</td></tr><?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($purchaseData): ?>
        <section class="mb-3">
            <div class="d-flex align-items-center justify-content-between mb-2">
                <h5 class="fw-bold mb-0">Purchase & Raw Materials</h5>
                <?php dashboardQuickLinks(dashboardPermittedShortcuts($preferredShortcuts['PURCHASE'])); ?>
            </div>
            <div class="row g-3 mb-3">
                <?php
                dashboardKpiCard('Raw Materials', $purchaseData['total_raw_materials'] ?? 0, 'bi bi-box', 'primary');
                dashboardKpiCard('Active Materials', $purchaseData['active_raw_materials'] ?? 0, 'bi bi-check2-circle', 'success');
                dashboardKpiCard('Low Stock', $purchaseData['low_stock_materials'] ?? 0, 'bi bi-exclamation-triangle', 'warning');
                dashboardKpiCard('Out of Stock', $purchaseData['out_of_stock_materials'] ?? 0, 'bi bi-x-octagon', 'danger');
                dashboardKpiCard('Usage Today', dashboardNumber($purchaseData['today_usage_qty'] ?? 0), 'bi bi-box-arrow-down', 'info');
                dashboardKpiCard('Monthly Usage', dashboardNumber($purchaseData['month_usage_qty'] ?? 0), 'bi bi-graph-down', 'secondary');
                ?>
            </div>
            <div class="row g-3 mb-3">
                <div class="col-lg-7"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold">Consumption Trend</div><div class="card-body dashboard-chart"><canvas id="purchaseTrendChart"></canvas><div class="dashboard-empty d-none">No dashboard data is available for the selected period.</div></div></div></div>
                <div class="col-lg-5"><div class="card border-0 shadow-sm h-100"><div class="card-header bg-white fw-semibold">Top Used Materials</div><div class="card-body dashboard-chart"><canvas id="purchaseTopChart"></canvas><div class="dashboard-empty d-none">No dashboard data is available for the selected period.</div></div></div></div>
            </div>
            <div class="row g-3">
                <div class="col-lg-8">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">Recent Raw Material Usage</div>
                        <div class="card-body table-responsive">
                            <table class="table table-sm align-middle">
                                <thead class="table-light"><tr><th>Batch</th><th>Material</th><th>Qty</th><th>Unit</th><th>Production Date</th><th>Owner</th></tr></thead>
                                <tbody>
                                <?php foreach (($purchaseData['recent_usage'] ?? []) as $usage): ?>
                                    <tr><td><?= h($usage['batch_code'] ?? '-') ?></td><td><?= h($usage['material_name'] ?? '-') ?></td><td><?= h(dashboardNumber($usage['quantity_used'] ?? 0)) ?></td><td><?= h($usage['unit'] ?? '') ?></td><td><?= h($usage['production_date'] ?? '-') ?></td><td><?= h($usage['batch_owner'] ?? '-') ?></td></tr>
                                <?php endforeach; ?>
                                <?php if (empty($purchaseData['recent_usage'])): ?><tr><td colspan="6" class="text-muted">No recent material usage found.</td></tr><?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card border-0 shadow-sm h-100">
                        <div class="card-header bg-white fw-semibold">Low-stock Materials</div>
                        <div class="card-body">
                            <?php if (!empty($purchaseData['low_stock_list'])): ?>
                                <div class="list-group list-group-flush">
                                    <?php foreach ($purchaseData['low_stock_list'] as $material): ?>
                                        <div class="list-group-item px-0 d-flex justify-content-between gap-2">
                                            <span><?= h($material['material_name'] ?? '-') ?></span>
                                            <span class="text-muted small"><?= h(dashboardNumber($material['stock'] ?? 0)) ?> / <?= h(dashboardNumber($material['minimum_stock'] ?? 0)) ?> <?= h($material['unit'] ?? '') ?></span>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php else: ?>
                                <div class="text-muted small">No low-stock warnings.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </section>
    <?php endif; ?>

    <?php if (!$salesData && !$productionData && !$purchaseData && !$adminData): ?>
        <?php $generalData = $generalData ?: getGeneralDashboardData(); ?>
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5 class="fw-bold">Welcome</h5>
                <p class="text-muted mb-3"><?= h($generalData['message'] ?? '') ?></p>
                <?php dashboardQuickLinks($generalData['shortcuts'] ?? []); ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
(function () {
    const chartDefaults = { responsive: true, maintainAspectRatio: false, animation: false, plugins: { legend: { position: 'bottom' } } };

    function initChart(id, type, rows, label, color) {
        const canvas = document.getElementById(id);
        if (!canvas || typeof Chart === 'undefined') return;
        const empty = canvas.parentElement.querySelector('.dashboard-empty');
        rows = Array.isArray(rows) ? rows : [];
        if (!rows.length) {
            canvas.classList.add('d-none');
            if (empty) empty.classList.remove('d-none');
            return;
        }
        new Chart(canvas, {
            type: type,
            data: {
                labels: rows.map(row => row.label || '-'),
                datasets: [{ label: label, data: rows.map(row => Number(row.total || 0)), borderWidth: 2, backgroundColor: color, borderColor: color, tension: 0.32 }]
            },
            options: Object.assign({}, chartDefaults, { scales: type === 'doughnut' ? {} : { y: { beginAtZero: true, ticks: { precision: 0 } } } })
        });
    }

    initChart('salesTrendChart', 'line', <?= dashboardJson($salesData['daily_trend'] ?? []) ?>, 'Orders', '#2563eb');
    initChart('salesStatusChart', 'doughnut', <?= dashboardJson($salesData['status_chart'] ?? []) ?>, 'Orders', ['#2563eb', '#16a34a', '#f59e0b', '#dc2626', '#64748b', '#0891b2']);
    initChart('productionTrendChart', 'bar', <?= dashboardJson($productionData['daily_production_trend'] ?? []) ?>, 'Quantity', '#16a34a');
    initChart('productionProductChart', 'bar', <?= dashboardJson($productionData['product_wise_production'] ?? []) ?>, 'Quantity', '#0f766e');
    initChart('purchaseTrendChart', 'line', <?= dashboardJson($purchaseData['usage_trend'] ?? []) ?>, 'Quantity', '#7c3aed');
    initChart('purchaseTopChart', 'bar', <?= dashboardJson($purchaseData['top_used_materials'] ?? []) ?>, 'Quantity', '#ea580c');
})();
</script>

<style>
.dashboard-page .card { border-radius: 8px; }
.dashboard-kpi { min-height: 116px; }
.dashboard-kpi-icon {
    width: 42px;
    height: 42px;
    border-radius: 8px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    flex: 0 0 auto;
}
.dashboard-chart { height: 300px; position: relative; }
.dashboard-empty {
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #6b7280;
    font-size: 14px;
    text-align: center;
}
@media (max-width: 575.98px) {
    .dashboard-kpi .fs-3 { font-size: 1.35rem !important; }
    .dashboard-chart { height: 240px; }
}
</style>

<?php include('include/footer.php'); ?>
