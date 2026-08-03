<?php
require_once 'include/require_permission.php';

$isEdit = isset($_GET['id']);

// department_code was added by a later, optional migration (role_based_dashboard.sql).
// Detect it so this form still works whether or not that migration has been run.
$hasDepartmentColumn = false;
$columnCheck = $mysqli->query("
    SELECT COUNT(*) AS cnt
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'roles'
      AND COLUMN_NAME = 'department_code'
");
if ($columnCheck) {
    $columnRow = $columnCheck->fetch_assoc();
    $hasDepartmentColumn = (int)($columnRow['cnt'] ?? 0) > 0;
}

if ($isEdit) {
    requirePermission('ROLES', 'edit');
    $roleId = (int)$_GET['id'];

    $stmt = $mysqli->prepare("SELECT * FROM roles WHERE role_id = ? LIMIT 1");
    $stmt->bind_param("i", $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $roleData = $result->fetch_assoc();
    $stmt->close();

    if (!$roleData) {
        header('Location: role_list.php');
        exit;
    }
} else {
    requirePermission('ROLES', 'add');
    $roleId = null;
    $roleData = [
        'role_id' => null,
        'role_name' => '',
        'role_code' => '',
        'description' => '',
        'department_code' => '',
        'is_system_role' => 0,
        'status' => 'active'
    ];
}

require_once 'include/csrf_helper.php';
include('include/header.php');

$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $roleName = trim($_POST['role_name'] ?? '');
    $roleCode = strtoupper(trim($_POST['role_code'] ?? ''));
    $description = trim($_POST['description'] ?? '');
    $departmentCode = strtoupper(trim($_POST['department_code'] ?? ''));
    $status = $_POST['status'] ?? 'active';

    if (empty($_POST['csrf_token']) || !verifyCsrfToken($_POST['csrf_token'])) {
        $errorMessage = 'Invalid request token.';
    } else if ($roleName === '') {
        $errorMessage = 'Role name is required.';
    } else if ($roleCode === '') {
        $errorMessage = 'Role code is required.';
    } else if (!isValidRoleCode($roleCode)) {
        $errorMessage = 'Role code must contain only uppercase letters, numbers, and underscores.';
    } else if (!in_array($status, ['active', 'inactive'])) {
        $errorMessage = 'Invalid status.';
    } else if ($isEdit && $status === 'inactive' && isLastAdminRole($roleId)) {
        $errorMessage = 'Cannot deactivate the last active Administrator role.';
    } else {
        // Check for duplicate name/code
        if ($isEdit) {
            $dupStmt = $mysqli->prepare("SELECT role_id FROM roles WHERE (role_name = ? OR role_code = ?) AND role_id != ? LIMIT 1");
            $dupStmt->bind_param("ssi", $roleName, $roleCode, $roleId);
        } else {
            $dupStmt = $mysqli->prepare("SELECT role_id FROM roles WHERE role_name = ? OR role_code = ? LIMIT 1");
            $dupStmt->bind_param("ss", $roleName, $roleCode);
        }

        $dupStmt->execute();
        $dupResult = $dupStmt->get_result();
        $dupStmt->close();

        if ($dupResult->num_rows > 0) {
            $errorMessage = 'A role with this name or code already exists.';
        } else if ($isEdit) {
            $userId = (int)($_SESSION['user_id'] ?? 0);

            if ($hasDepartmentColumn) {
                $updateStmt = $mysqli->prepare("
                    UPDATE roles
                    SET role_name = ?, role_code = ?, description = ?, department_code = ?,
                        status = ?, updated_by = ?, updated_at = NOW()
                    WHERE role_id = ?
                ");
                $updateStmt->bind_param(
                    "sssssii",
                    $roleName, $roleCode, $description, $departmentCode,
                    $status, $userId, $roleId
                );
            } else {
                $updateStmt = $mysqli->prepare("
                    UPDATE roles
                    SET role_name = ?, role_code = ?, description = ?,
                        status = ?, updated_by = ?, updated_at = NOW()
                    WHERE role_id = ?
                ");
                $updateStmt->bind_param(
                    "ssssii",
                    $roleName, $roleCode, $description,
                    $status, $userId, $roleId
                );
            }

            if ($updateStmt->execute()) {
                logPermissionAudit('ROLE_UPDATED', 'ROLE', $roleId);
                $updateStmt->close();

                $_SESSION['toast'] = [
                    'type' => 'success',
                    'message' => 'Role updated successfully.'
                ];
                header('Location: role_list.php');
                exit;
            } else {
                $errorMessage = 'Failed to update role.';
                $updateStmt->close();
            }
        } else {
            $userId = (int)($_SESSION['user_id'] ?? 0);

            if ($hasDepartmentColumn) {
                $insertStmt = $mysqli->prepare("
                    INSERT INTO roles (role_name, role_code, description, department_code, is_system_role, status, created_by)
                    VALUES (?, ?, ?, ?, 0, ?, ?)
                ");
                $insertStmt->bind_param(
                    "ssssi",
                    $roleName, $roleCode, $description, $departmentCode, $status, $userId
                );
            } else {
                $insertStmt = $mysqli->prepare("
                    INSERT INTO roles (role_name, role_code, description, is_system_role, status, created_by)
                    VALUES (?, ?, ?, 0, ?, ?)
                ");
                $insertStmt->bind_param(
                    "sssi",
                    $roleName, $roleCode, $description, $status, $userId
                );
            }

            if ($insertStmt->execute()) {
                $newRoleId = $mysqli->insert_id;
                logPermissionAudit('ROLE_CREATED', 'ROLE', $newRoleId);
                $insertStmt->close();

                $_SESSION['toast'] = [
                    'type' => 'success',
                    'message' => 'Role created successfully. Now assign page permissions to it.'
                ];
                header('Location: role_permissions.php?role_id=' . $newRoleId);
                exit;
            } else {
                $errorMessage = 'Failed to create role.';
                $insertStmt->close();
            }
        }
    }
}
?>

<div class="sa-page__body">
    <div class="container">
        <!-- Page Header -->
        <div class="sa-page-header mb-4">
            <h1 class="sa-page-title">
                <i class="bi bi-person-badge"></i>
                <?= $isEdit ? 'Edit Role' : 'Add New Role' ?>
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

                        <?php if ($isEdit && $roleData['is_system_role']): ?>
                            <div class="alert alert-info">
                                <i class="bi bi-shield-lock"></i>
                                This is a built-in system role. Its code cannot be changed and it cannot be deleted or deactivated.
                            </div>
                        <?php endif; ?>

                        <form method="post">
                            <?= csrfTokenField() ?>

                            <!-- Role Name -->
                            <div class="mb-3">
                                <label for="roleName" class="form-label">Role Name <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    id="roleName"
                                    name="role_name"
                                    class="form-control"
                                    value="<?= htmlspecialchars($roleData['role_name']) ?>"
                                    required
                                    placeholder="e.g., Warehouse Manager"
                                >
                            </div>

                            <!-- Role Code -->
                            <div class="mb-3">
                                <label for="roleCode" class="form-label">Role Code <span class="text-danger">*</span></label>
                                <input
                                    type="text"
                                    id="roleCode"
                                    name="role_code"
                                    class="form-control"
                                    value="<?= htmlspecialchars($roleData['role_code']) ?>"
                                    required
                                    placeholder="e.g., WAREHOUSE_MANAGER"
                                    onchange="this.value = this.value.toUpperCase();"
                                    <?= ($isEdit && $roleData['is_system_role']) ? 'readonly' : '' ?>
                                >
                                <small class="text-muted">Used internally for permission checks and cannot be changed for system roles</small>
                            </div>

                            <!-- Description -->
                            <div class="mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea
                                    id="description"
                                    name="description"
                                    class="form-control"
                                    rows="3"
                                    placeholder="What this role is for..."
                                ><?= htmlspecialchars($roleData['description'] ?? '') ?></textarea>
                            </div>

                            <?php if ($hasDepartmentColumn): ?>
                                <!-- Department Code -->
                                <div class="mb-3">
                                    <label for="departmentCode" class="form-label">Department Code</label>
                                    <input
                                        type="text"
                                        id="departmentCode"
                                        name="department_code"
                                        class="form-control"
                                        value="<?= htmlspecialchars($roleData['department_code'] ?? '') ?>"
                                        placeholder="e.g., SALES, PRODUCTION, PURCHASE"
                                        onchange="this.value = this.value.toUpperCase();"
                                    >
                                    <small class="text-muted">Groups this role for the role-based dashboard (optional)</small>
                                </div>
                            <?php endif; ?>

                            <!-- Status -->
                            <div class="mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select
                                    id="status"
                                    name="status"
                                    class="form-select"
                                    required
                                    <?= ($isEdit && $roleData['is_system_role']) ? 'disabled' : '' ?>
                                >
                                    <option value="active" <?= $roleData['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                                    <option value="inactive" <?= $roleData['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                                <?php if ($isEdit && $roleData['is_system_role']): ?>
                                    <input type="hidden" name="status" value="<?= htmlspecialchars($roleData['status']) ?>">
                                <?php endif; ?>
                            </div>

                            <!-- Action Buttons -->
                            <div class="d-flex gap-2 mt-4">
                                <button type="submit" class="btn btn-primary">
                                    <i class="bi bi-check-circle"></i>
                                    <?= $isEdit ? 'Update Role' : 'Create Role' ?>
                                </button>
                                <a href="role_list.php" class="btn btn-outline-secondary">
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
                        <h6 class="text-dark">Role Code</h6>
                        <p>Used internally to identify this role. Use uppercase and underscores.</p>

                        <h6 class="text-dark mt-3">Page Permissions</h6>
                        <p>After saving, use the <i class="bi bi-shield-lock"></i> button on the Roles list to choose exactly which pages and actions this role can access.</p>

                        <div class="alert alert-info mb-0 mt-3">
                            <small>
                                <strong>Note:</strong> New roles start with zero page permissions until you assign them.
                            </small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

    </div>
</div>

<?php include('include/footer.php'); ?>
