<?php
require_once 'include/require_permission.php';
require_once 'include/csrf_helper.php';
require_once 'include/dashboard_helper.php';

$widgetId = (int)($_GET['id'] ?? 0);
requirePermission('DASHBOARD_WIDGETS', $widgetId > 0 ? 'edit' : 'add');

$widget = [
    'widget_id' => 0, 'widget_name' => '', 'widget_code' => '', 'department_code' => 'GENERAL',
    'related_page_code' => '', 'widget_type' => 'kpi', 'title' => '', 'subtitle' => '',
    'icon_class' => 'bi bi-grid', 'bootstrap_class' => 'primary', 'display_order' => 0,
    'contains_amount' => 0, 'admin_only' => 0, 'status' => 'active',
];

if ($widgetId > 0 && dashboardTableExists('dashboard_widgets')) {
    $stmt = $mysqli->prepare("SELECT * FROM dashboard_widgets WHERE widget_id = ? LIMIT 1");
    $stmt->bind_param('i', $widgetId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($row) {
        $widget = array_merge($widget, $row);
    }
}

$pages = [];
$pageResult = $mysqli->query("SELECT page_code, page_name FROM system_pages WHERE status = 'active' ORDER BY page_name ASC");
if ($pageResult) {
    while ($row = $pageResult->fetch_assoc()) {
        $pages[] = $row;
    }
}

include('include/header.php');
?>
<div class="container-fluid py-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="fw-bold mb-1"><?= $widgetId > 0 ? 'Edit' : 'Add' ?> Dashboard Widget</h4>
            <div class="text-muted small">Configure metadata and security flags.</div>
        </div>
        <a class="btn btn-outline-secondary" href="dashboard_widget_list.php">Back</a>
    </div>
    <form class="card border-0 shadow-sm" method="post" action="dashboard_widget_save.php">
        <div class="card-body">
            <?= csrfTokenField() ?>
            <input type="hidden" name="widget_id" value="<?= (int)$widget['widget_id'] ?>">
            <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Widget Name</label><input class="form-control" name="widget_name" required value="<?= htmlspecialchars($widget['widget_name'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-6"><label class="form-label">Widget Code</label><input class="form-control" name="widget_code" required value="<?= htmlspecialchars($widget['widget_code'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-4">
                    <label class="form-label">Department</label>
                    <select class="form-select" name="department_code">
                        <?php foreach (['GENERAL','SALES','PRODUCTION','PURCHASE','ADMIN'] as $dept): ?>
                            <option value="<?= $dept ?>" <?= $widget['department_code'] === $dept ? 'selected' : '' ?>><?= $dept ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Related Page</label>
                    <select class="form-select" name="related_page_code">
                        <option value="">None</option>
                        <?php foreach ($pages as $page): ?>
                            <option value="<?= htmlspecialchars($page['page_code'], ENT_QUOTES, 'UTF-8') ?>" <?= $widget['related_page_code'] === $page['page_code'] ? 'selected' : '' ?>><?= htmlspecialchars($page['page_name'] . ' (' . $page['page_code'] . ')', ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Widget Type</label>
                    <select class="form-select" name="widget_type">
                        <?php foreach (['kpi','chart','table','list','shortcut','notice'] as $type): ?>
                            <option value="<?= $type ?>" <?= $widget['widget_type'] === $type ? 'selected' : '' ?>><?= ucfirst($type) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6"><label class="form-label">Title</label><input class="form-control" name="title" required value="<?= htmlspecialchars($widget['title'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-6"><label class="form-label">Subtitle</label><input class="form-control" name="subtitle" value="<?= htmlspecialchars($widget['subtitle'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-4"><label class="form-label">Icon Class</label><input class="form-control" name="icon_class" value="<?= htmlspecialchars($widget['icon_class'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-4"><label class="form-label">Bootstrap Class</label><input class="form-control" name="bootstrap_class" value="<?= htmlspecialchars($widget['bootstrap_class'], ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="col-md-2"><label class="form-label">Display Order</label><input class="form-control" type="number" name="display_order" value="<?= (int)$widget['display_order'] ?>"></div>
                <div class="col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status"><option value="active" <?= $widget['status'] === 'active' ? 'selected' : '' ?>>Active</option><option value="inactive" <?= $widget['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option></select>
                </div>
                <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="contains_amount" value="1" <?= (int)$widget['contains_amount'] === 1 ? 'checked' : '' ?>> <span class="form-check-label">Contains financial amount</span></label></div>
                <div class="col-md-6"><label class="form-check"><input class="form-check-input" type="checkbox" name="admin_only" value="1" <?= (int)$widget['admin_only'] === 1 ? 'checked' : '' ?>> <span class="form-check-label">Administrator only</span></label></div>
            </div>
        </div>
        <div class="card-footer bg-white text-end"><button class="btn btn-primary" type="submit">Save Widget</button></div>
    </form>
</div>
<?php include('include/footer.php'); ?>
