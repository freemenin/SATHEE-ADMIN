<?php
require_once 'include/require_permission.php';
requirePermission('DASHBOARD_WIDGETS', 'view');
require_once 'include/dashboard_helper.php';
require_once 'include/csrf_helper.php';

$widgets = [];
if (dashboardTableExists('dashboard_widgets')) {
    $result = $mysqli->query("
        SELECT w.*, p.page_name
        FROM dashboard_widgets w
        LEFT JOIN system_pages p ON p.page_code = w.related_page_code
        ORDER BY w.department_code ASC, w.display_order ASC, w.widget_id ASC
    ");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $widgets[] = $row;
        }
    }
}

include('include/header.php');
?>
<div class="container-fluid py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h4 class="fw-bold mb-1">Dashboard Widgets</h4>
            <div class="text-muted small">Manage role-aware dashboard widget definitions.</div>
        </div>
        <a class="btn btn-primary" href="dashboard_widget_form.php"><i class="bi bi-plus-lg me-1"></i>Add Widget</a>
    </div>
    <div class="card border-0 shadow-sm">
        <div class="card-body table-responsive">
            <table class="table table-sm align-middle">
                <thead class="table-light">
                    <tr><th>Widget</th><th>Code</th><th>Department</th><th>Type</th><th>Related Page</th><th>Flags</th><th>Order</th><th>Status</th><th class="text-end">Actions</th></tr>
                </thead>
                <tbody>
                <?php foreach ($widgets as $widget): ?>
                    <tr>
                        <td><?= htmlspecialchars($widget['title'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><code><?= htmlspecialchars($widget['widget_code'] ?? '', ENT_QUOTES, 'UTF-8') ?></code></td>
                        <td><?= htmlspecialchars($widget['department_code'] ?? 'GENERAL', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($widget['widget_type'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars($widget['related_page_code'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <?php if ((int)($widget['contains_amount'] ?? 0) === 1): ?><span class="badge text-bg-warning">Amount</span><?php endif; ?>
                            <?php if ((int)($widget['admin_only'] ?? 0) === 1): ?><span class="badge text-bg-danger">Admin Only</span><?php endif; ?>
                        </td>
                        <td><?= (int)($widget['display_order'] ?? 0) ?></td>
                        <td><span class="badge text-bg-<?= ($widget['status'] ?? '') === 'active' ? 'success' : 'secondary' ?>"><?= htmlspecialchars($widget['status'] ?? '', ENT_QUOTES, 'UTF-8') ?></span></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="dashboard_widget_form.php?id=<?= (int)$widget['widget_id'] ?>">Edit</a>
                            <form class="d-inline" method="post" action="dashboard_widget_status.php">
                                <?= csrfTokenField() ?>
                                <input type="hidden" name="widget_id" value="<?= (int)$widget['widget_id'] ?>">
                                <button class="btn btn-sm btn-outline-secondary" type="submit">Toggle</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$widgets): ?><tr><td colspan="9" class="text-muted">No dashboard widgets found. Run the role based dashboard migration first.</td></tr><?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include('include/footer.php'); ?>
