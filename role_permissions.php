<?php
require_once 'include/require_permission.php';
requirePermission('ROLE_PERMISSIONS', 'view');

require_once 'include/csrf_helper.php';
include('include/header.php');

// All active roles, for the role picker
$rolesStmt = $mysqli->query("
    SELECT role_id, role_name, role_code, is_system_role, status
    FROM roles
    ORDER BY is_system_role DESC, role_name ASC
");
$allRoles = [];
while ($row = $rolesStmt->fetch_assoc()) {
    $allRoles[] = $row;
}

$selectedRoleId = (int)($_GET['role_id'] ?? 0);
$selectedRole = null;
foreach ($allRoles as $r) {
    if ((int)$r['role_id'] === $selectedRoleId) {
        $selectedRole = $r;
        break;
    }
}

$pagesByGroup = [];
$existingPermissions = [];

if ($selectedRole) {
    // All active pages, grouped for display
    $pagesStmt = $mysqli->query("
        SELECT page_id, page_name, page_code, menu_group, display_order
        FROM system_pages
        WHERE status = 'active'
        ORDER BY menu_group ASC, display_order ASC, page_name ASC
    ");
    while ($row = $pagesStmt->fetch_assoc()) {
        $group = trim($row['menu_group'] ?? '') ?: 'Main';
        $pagesByGroup[$group][] = $row;
    }

    // Existing permissions for this role
    $permStmt = $mysqli->prepare("
        SELECT page_id, can_view, can_add, can_edit, can_delete, can_export, can_approve
        FROM role_page_permissions
        WHERE role_id = ?
    ");
    $permStmt->bind_param("i", $selectedRoleId);
    $permStmt->execute();
    $permResult = $permStmt->get_result();
    while ($row = $permResult->fetch_assoc()) {
        $existingPermissions[(int)$row['page_id']] = $row;
    }
    $permStmt->close();
}

$actions = [
    'view'    => ['label' => 'View',    'column' => 'can_view'],
    'add'     => ['label' => 'Add',     'column' => 'can_add'],
    'edit'    => ['label' => 'Edit',    'column' => 'can_edit'],
    'delete'  => ['label' => 'Delete',  'column' => 'can_delete'],
    'export'  => ['label' => 'Export',  'column' => 'can_export'],
    'approve' => ['label' => 'Approve', 'column' => 'can_approve'],
];
?>

<div class="sa-page__body">
    <div class="container-fluid">
        <!-- Page Header -->
        <div class="sa-page-header row align-items-center mb-4">
            <div class="col">
                <h1 class="sa-page-title">
                    <i class="bi bi-shield-lock"></i>
                    Role Permissions
                </h1>
                <p class="sa-page-description">
                    Choose exactly which pages and actions each role is allowed to use
                </p>
            </div>
        </div>

        <!-- Role Picker -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="get" class="d-flex gap-2 align-items-center flex-wrap">
                    <label for="roleSelect" class="form-label mb-0 me-2"><strong>Role:</strong></label>
                    <div style="min-width: 280px;">
                        <select id="roleSelect" name="role_id" class="form-select" onchange="this.form.submit()">
                            <option value="">Select a role...</option>
                            <?php foreach ($allRoles as $r): ?>
                                <option value="<?= (int)$r['role_id'] ?>" <?= $selectedRoleId === (int)$r['role_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($r['role_name']) ?> (<?= htmlspecialchars($r['role_code']) ?>)
                                    <?= $r['status'] !== 'active' ? ' - inactive' : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <noscript><button type="submit" class="btn btn-outline-primary">Load</button></noscript>
                </form>
            </div>
        </div>

        <?php if (!$selectedRole): ?>
            <div class="card">
                <div class="card-body text-center text-muted py-5">
                    <i class="bi bi-arrow-up-circle" style="font-size: 32px;"></i>
                    <p class="mt-3">Select a role above to view or edit its page permissions.</p>
                </div>
            </div>
        <?php else: ?>

            <?php if ($selectedRole['is_system_role'] && $selectedRole['role_code'] === 'ADMIN'): ?>
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    <strong><?= htmlspecialchars($selectedRole['role_name']) ?></strong> is the built-in Administrator role.
                    To prevent accidental lockout, <code>Roles</code>, <code>Permissions</code> and <code>Pages</code> view access
                    cannot be removed from the last active Administrator role.
                </div>
            <?php endif; ?>

            <form method="post" action="role_permissions_save.php">
                <?= csrfTokenField() ?>
                <input type="hidden" name="role_id" value="<?= (int)$selectedRoleId ?>">

                <?php foreach ($pagesByGroup as $groupName => $groupPages): ?>
                    <div class="card mb-3">
                        <div class="card-header">
                            <strong><?= htmlspecialchars($groupName) ?></strong>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Page</th>
                                        <?php foreach ($actions as $actionKey => $actionMeta): ?>
                                            <th style="text-align:center; width:80px;"><?= $actionMeta['label'] ?></th>
                                        <?php endforeach; ?>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($groupPages as $pageRow):
                                        $pageId = (int)$pageRow['page_id'];
                                        $existing = $existingPermissions[$pageId] ?? [];
                                    ?>
                                        <tr>
                                            <td>
                                                <?= htmlspecialchars($pageRow['page_name']) ?>
                                                <br><code class="small text-muted"><?= htmlspecialchars($pageRow['page_code']) ?></code>
                                            </td>
                                            <?php foreach ($actions as $actionKey => $actionMeta):
                                                $checked = (int)($existing[$actionMeta['column']] ?? 0) === 1;
                                            ?>
                                                <td style="text-align:center;">
                                                    <input
                                                        type="checkbox"
                                                        class="form-check-input"
                                                        name="perm[<?= $pageId ?>][<?= $actionKey ?>]"
                                                        value="1"
                                                        <?= $checked ? 'checked' : '' ?>
                                                    >
                                                </td>
                                            <?php endforeach; ?>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="d-flex gap-2 mb-4">
                    <button type="submit" class="btn btn-primary">
                        <i class="bi bi-check-circle"></i>
                        Save Permissions
                    </button>
                    <a href="role_list.php" class="btn btn-outline-secondary">
                        <i class="bi bi-x"></i>
                        Back to Roles
                    </a>
                </div>
            </form>

        <?php endif; ?>

    </div>
</div>

<?php include('include/footer.php'); ?>
