<?php
require_once 'include/require_permission.php';

$isEdit = isset($_GET['id']);

if ($isEdit) {
    requirePermission('PAGES', 'edit');
    $pageId = (int)$_GET['id'];
    
    // Fetch page data
    $stmt = $mysqli->prepare("SELECT * FROM system_pages WHERE page_id = ? LIMIT 1");
    $stmt->bind_param("i", $pageId);
    $stmt->execute();
    $result = $stmt->get_result();
    $pageData = $result->fetch_assoc();
    $stmt->close();
    
    if (!$pageData) {
        header('Location: page_list.php');
        exit;
    }
} else {
    requirePermission('PAGES', 'add');
    $pageId = null;
    $pageData = [
        'page_id' => null,
        'parent_id' => null,
        'page_name' => '',
        'page_code' => '',
        'page_url' => '',
        'icon_class' => '',
        'menu_group' => 'Main',
        'description' => '',
        'display_order' => 0,
        'show_in_menu' => 1,
        'status' => 'active'
    ];
}

require_once 'include/csrf_helper.php';
include('include/header.php');

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pageName = trim($_POST['page_name'] ?? '');
    $pageCode = strtoupper(trim($_POST['page_code'] ?? ''));
    $pageUrl = trim($_POST['page_url'] ?? '');
    $iconClass = trim($_POST['icon_class'] ?? '');
    $menuGroup = trim($_POST['menu_group'] ?? 'Main');
    $description = trim($_POST['description'] ?? '');
    $displayOrder = (int)($_POST['display_order'] ?? 0);
    $showInMenu = isset($_POST['show_in_menu']) ? 1 : 0;
    $status = $_POST['status'] ?? 'active';
    $parentId = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;

    // Validate CSRF
    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $errorMessage = 'Invalid request token.';
    } else if ($pageName === '') {
        $errorMessage = 'Page name is required.';
    } else if ($pageCode === '') {
        $errorMessage = 'Page code is required.';
    } else if (!isValidPageCode($pageCode)) {
        $errorMessage = 'Page code must contain only uppercase letters, numbers, and underscores.';
    } else if ($pageUrl && (strpos($pageUrl, '../') !== false || strpos($pageUrl, 'http') !== false)) {
        $errorMessage = 'Page URL cannot contain ../ or external URLs.';
    } else if (!in_array($status, ['active', 'inactive'])) {
        $errorMessage = 'Invalid status.';
    } else if ($parentId && $parentId === $pageId) {
        $errorMessage = 'A page cannot be its own parent.';
    } else {
        // Check for duplicate code
        if ($isEdit) {
            $dupStmt = $mysqli->prepare("SELECT page_id FROM system_pages WHERE page_code = ? AND page_id != ? LIMIT 1");
            $dupStmt->bind_param("si", $pageCode, $pageId);
        } else {
            $dupStmt = $mysqli->prepare("SELECT page_id FROM system_pages WHERE page_code = ? LIMIT 1");
            $dupStmt->bind_param("s", $pageCode);
        }
        
        $dupStmt->execute();
        $dupResult = $dupStmt->get_result();
        $dupStmt->close();

        if ($dupResult->num_rows > 0) {
            $errorMessage = 'A page with this code already exists.';
        } else if ($isEdit) {
            // Update page
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $updateStmt = $mysqli->prepare("
                UPDATE system_pages 
                SET page_name = ?, page_code = ?, page_url = ?, icon_class = ?, menu_group = ?,
                    description = ?, display_order = ?, show_in_menu = ?, status = ?,
                    parent_id = ?, updated_by = ?, updated_at = NOW()
                WHERE page_id = ?
            ");
            $updateStmt->bind_param(
                "ssssssiiiiiii",
                $pageName, $pageCode, $pageUrl, $iconClass, $menuGroup,
                $description, $displayOrder, $showInMenu, $status,
                $parentId, $userId, $pageId
            );
            
            if ($updateStmt->execute()) {
                logPermissionAudit('PAGE_UPDATED', 'PAGE', $pageId);
                $updateStmt->close();
                
                $_SESSION['toast'] = [
                    'type' => 'success',
                    'message' => 'Page updated successfully.'
                ];
                header('Location: page_list.php');
                exit;
            } else {
                $errorMessage = 'Failed to update page.';
                $updateStmt->close();
            }
        } else {
            // Create new page
            $userId = (int)($_SESSION['user_id'] ?? 0);
            $insertStmt = $mysqli->prepare("
                INSERT INTO system_pages 
                (page_name, page_code, page_url, icon_class, menu_group, description, display_order, 
                 show_in_menu, status, parent_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $insertStmt->bind_param(
                "sssssssiiiii",
                $pageName, $pageCode, $pageUrl, $iconClass, $menuGroup, $description, $displayOrder,
                $showInMenu, $status, $parentId, $userId
            );
            
            if ($insertStmt->execute()) {
                $newPageId = $mysqli->insert_id;
                logPermissionAudit('PAGE_CREATED', 'PAGE', $newPageId);
                
                // Automatically grant admin full permission
                $adminRoleStmt = $mysqli->prepare("SELECT role_id FROM roles WHERE role_code = 'ADMIN' LIMIT 1");
                $adminRoleStmt->execute();
                $adminResult = $adminRoleStmt->get_result();
                $adminRole = $adminResult->fetch_assoc();
                $adminRoleStmt->close();
                
                if ($adminRole) {
                    $adminRoleId = (int)$adminRole['role_id'];
                    $permStmt = $mysqli->prepare("
                        INSERT INTO role_page_permissions 
                        (role_id, page_id, can_view, can_add, can_edit, can_delete, can_export, can_approve, created_by)
                        VALUES (?, ?, 1, 1, 1, 1, 1, 1, ?)
                    ");
                    $permStmt->bind_param("iii", $adminRoleId, $newPageId, $userId);
                    $permStmt->execute();
                    $permStmt->close();
                }
                
                $insertStmt->close();
                
                $_SESSION['toast'] = [
                    'type' => 'success',
                    'message' => 'Page created successfully.'
                ];
                header('Location: page_list.php');
                exit;
            } else {
                $errorMessage = 'Failed to create page.';
                $insertStmt->close();
            }
        }
    }
}

// Get all pages for parent selector
$pagesStmt = $mysqli->prepare("SELECT page_id, page_name FROM system_pages WHERE status = 'active' ORDER BY page_name");
$pagesStmt->execute();
$pagesResult = $pagesStmt->get_result();
$allPages = [];
while ($row = $pagesResult->fetch_assoc()) {
    $allPages[] = $row;
}
$pagesStmt->close();
?>

<div class="sa-page__body">
    <div class="container">
        <!-- Page Header -->
        <div class="sa-page-header mb-4">
            <h1 class="sa-page-title">
                <i class="bi bi-layout-text-window"></i>
                <?= $isEdit ? 'Edit Page' : 'Add New Page' ?>
            </h1>
        </div>

        <div class="row">
            <div class="col-lg-8">
                <div class="card">
                    <div class="card-body">
                        
                        <?php if ($errorMessage): ?>
                            <div class="alert alert-danger alert-dismissible fade show" role="alert">
                                <i class="bi bi-exclamation-circle"></i>
                                <?= htmlspecialchars($errorMessage) ?>
                                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                            </div>
                        <?php endif; ?>

                        <form method="post">
                            <?= csrfTokenField() ?>

                            <!-- Page Name -->
                            <div class="mb-3">
                                <label for="pageName" class="form-label">Page Name <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    id="pageName"
                                    name="page_name"
                                    class="form-control"
                                    value="<?= htmlspecialchars($pageData['page_name']) ?>"
                                    required
                                    placeholder="e.g., Customer Orders"
                                >
                            </div>

                            <!-- Page Code -->
                            <div class="mb-3">
                                <label for="pageCode" class="form-label">Page Code <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    id="pageCode"
                                    name="page_code"
                                    class="form-control"
                                    value="<?= htmlspecialchars($pageData['page_code']) ?>"
                                    required
                                    placeholder="e.g., CUSTOMER_ORDERS"
                                    onchange="this.value = this.value.toUpperCase();"
                                >
                                <small class="text-muted">Used internally for permission checks</small>
                            </div>

                            <!-- Page URL -->
                            <div class="mb-3">
                                <label for="pageUrl" class="form-label">Page URL</label>
                                <input
                                    type="text"
                                    id="pageUrl"
                                    name="page_url"
                                    class="form-control"
                                    value="<?= htmlspecialchars($pageData['page_url'] ?? '') ?>"
                                    placeholder="e.g., customer_orders.php"
                                >
                                <small class="text-muted">Filename or path (no ../ or external URLs)</small>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <!-- Icon Class -->
                                    <div class="mb-3">
                                        <label for="iconClass" class="form-label">Icon Class</label>
                                        <input
                                            type="text"
                                            id="iconClass"
                                            name="icon_class"
                                            class="form-control"
                                            value="<?= htmlspecialchars($pageData['icon_class'] ?? '') ?>"
                                            placeholder="e.g., bi bi-cart"
                                        >
                                        <small class="text-muted">Bootstrap Icon class</small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <!-- Menu Group -->
                                    <div class="mb-3">
                                        <label for="menuGroup" class="form-label">Menu Group</label>
                                        <input
                                            type="text"
                                            id="menuGroup"
                                            name="menu_group"
                                            class="form-control"
                                            value="<?= htmlspecialchars($pageData['menu_group'] ?? 'Main') ?>"
                                            placeholder="e.g., Sales"
                                        >
                                    </div>
                                </div>
                            </div>

                            <!-- Description -->
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea
                                    id="description"
                                    name="description"
                                    class="form-control"
                                    rows="3"
                                    placeholder="Page description..."
                                ><?= htmlspecialchars($pageData['description'] ?? '') ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <!-- Display Order -->
                                    <div class="mb-3">
                                        <label for="displayOrder" class="form-label">Display Order</label>
                                        <input
                                            type="number"
                                            id="displayOrder"
                                            name="display_order"
                                            class="form-control"
                                            value="<?= (int)$pageData['display_order'] ?>"
                                            min="0"
                                        >
                                        <small class="text-muted">Sort order in menu</small>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <!-- Status -->
                                    <div class="mb-3">
                                        <label for="status" class="form-label">Status</label>
                                        <select id="status" name="status" class="form-select" required>
                                            <option value="active" <?= $pageData['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                            <option value="inactive" <?= $pageData['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Show in Menu -->
                            <div class="mb-3 form-check">
                                <input
                                    type="checkbox"
                                    id="showInMenu"
                                    name="show_in_menu"
                                    class="form-check-input"
                                    <?= $pageData['show_in_menu'] ? 'checked' : '' ?>
                                >
                                <label class="form-check-label" for="showInMenu">
                                    Show in menu
                                </label>
                                <small class="d-block text-muted">Uncheck to hide from sidebar</small>
                            </div>

                            <!-- Action Buttons -->
                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i>
                                    <?= $isEdit ? 'Update Page' : 'Create Page' ?>
                                </button>
                                <a href="page_list.php" class="btn btn-outline-secondary">
                                    <i class="bi bi-x"></i>
                                    Cancel
                                </a>
                            </div>

                        </form>

                    </div>
                </div>
            </div>

            <!-- Help Panel -->
            <div class="col-lg-4">
                <div class="card">
                    <div class="card-header">
                        <h5 class="card-title mb-0">
                            <i class="bi bi-info-circle"></i>
                            Help
                        </h5>
                    </div>
                    <div class="card-body small text-muted">
                        <h6 class="text-dark">Page Code</h6>
                        <p>Used in permission checks. Use uppercase and underscores.</p>

                        <h6 class="text-dark mt-3">Menu Settings</h6>
                        <p>Configure how this page appears in the sidebar menu.</p>

                        <h6 class="text-dark mt-3">Display Order</h6>
                        <p>Pages with lower numbers appear first in their group.</p>

                        <div class="alert alert-info mb-0 mt-3">
                            <small>
                                <strong>Note:</strong> New pages automatically get full Admin permissions.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include('include/footer.php'); ?>
