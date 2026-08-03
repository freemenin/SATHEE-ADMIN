<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';

// ============================================================================
// Permission Helper Functions
// ============================================================================

/**
 * Get the current user's role ID from database if not in session
 * @return int User's role ID or 0 if not found
 */
function currentUserRoleId(): int
{
    global $mysqli;

    $userId = (int)($_SESSION['user_id'] ?? 0);

    if ($userId <= 0) {
        return 0;
    }

    // Return cached role_id if available
    if (!empty($_SESSION['role_id'])) {
        return (int)$_SESSION['role_id'];
    }

    // Fetch from database
    $stmt = $mysqli->prepare("
        SELECT role_id
        FROM users
        WHERE user_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        error_log("Permission Helper: Failed to prepare statement - " . $mysqli->error);
        return 0;
    }

    $stmt->bind_param("i", $userId);
    $stmt->execute();

    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    $roleId = (int)($user['role_id'] ?? 0);

    // Cache in session for future requests
    if ($roleId > 0) {
        $_SESSION['role_id'] = $roleId;
    }

    return $roleId;
}

/**
 * Get the complete permission record for a page
 * @param string $pageCode The page code to check
 * @return array|null Permission array or null if not found
 */
function getPagePermission(string $pageCode): ?array
{
    global $mysqli;

    $roleId = currentUserRoleId();
    $pageCode = strtoupper(trim($pageCode));

    if ($roleId <= 0 || $pageCode === '') {
        return null;
    }

    $stmt = $mysqli->prepare("
        SELECT
            rpp.can_view,
            rpp.can_add,
            rpp.can_edit,
            rpp.can_delete,
            rpp.can_export,
            rpp.can_approve
        FROM role_page_permissions rpp
        INNER JOIN roles r ON r.role_id = rpp.role_id
        INNER JOIN system_pages p ON p.page_id = rpp.page_id
        WHERE rpp.role_id = ?
          AND p.page_code = ?
          AND r.status = 'active'
          AND p.status = 'active'
        LIMIT 1
    ");

    if (!$stmt) {
        error_log("Permission Helper: Failed to prepare permission query - " . $mysqli->error);
        return null;
    }

    $stmt->bind_param("is", $roleId, $pageCode);
    $stmt->execute();

    $result = $stmt->get_result();
    $permission = $result->fetch_assoc();
    $stmt->close();

    return $permission ?: null;
}

/**
 * Check an individual action permission
 * @param string $pageCode The page code to check
 * @param string $action The action to check (view, add, edit, delete, export, approve)
 * @return bool True if user has permission
 */
function hasPermission(string $pageCode, string $action = 'view'): bool
{
    $permission = getPagePermission($pageCode);

    if (!$permission) {
        return false;
    }

    $actionMap = [
        'view'    => 'can_view',
        'add'     => 'can_add',
        'create'  => 'can_add',
        'edit'    => 'can_edit',
        'update'  => 'can_edit',
        'delete'  => 'can_delete',
        'export'  => 'can_export',
        'approve' => 'can_approve'
    ];

    $action = strtolower(trim($action));

    if (!isset($actionMap[$action])) {
        return false;
    }

    $column = $actionMap[$action];

    return (int)($permission[$column] ?? 0) === 1;
}

/**
 * Get all menu pages the current user is allowed to see
 * @return array Array of allowed pages grouped by menu
 */
function getAllowedMenuPages(): array
{
    global $mysqli;

    $roleId = currentUserRoleId();

    if ($roleId <= 0) {
        return [];
    }

    $stmt = $mysqli->prepare("
        SELECT
            p.page_id,
            p.parent_id,
            p.page_name,
            p.page_code,
            p.page_url,
            p.icon_class,
            p.menu_group,
            p.display_order
        FROM role_page_permissions rpp
        INNER JOIN system_pages p ON p.page_id = rpp.page_id
        INNER JOIN roles r ON r.role_id = rpp.role_id
        WHERE rpp.role_id = ?
          AND rpp.can_view = 1
          AND p.show_in_menu = 1
          AND p.status = 'active'
          AND r.status = 'active'
        ORDER BY
            p.menu_group ASC,
            p.display_order ASC,
            p.page_name ASC
    ");

    if (!$stmt) {
        error_log("Permission Helper: Failed to prepare menu query - " . $mysqli->error);
        return [];
    }

    $stmt->bind_param("i", $roleId);
    $stmt->execute();

    $result = $stmt->get_result();
    $pages = [];

    while ($row = $result->fetch_assoc()) {
        $pages[] = $row;
    }

    $stmt->close();

    return $pages;
}

/**
 * Get user's role details
 * @param int $roleId Optional role ID, uses current user if not provided
 * @return array|null Role details or null if not found
 */
function getRoleDetails(?int $roleId = null): ?array
{
    global $mysqli;

    if ($roleId === null) {
        $roleId = currentUserRoleId();
    }

    if ($roleId <= 0) {
        return null;
    }

    $hasDepartment = false;
    $columnCheck = $mysqli->query("
        SELECT COUNT(*) AS cnt
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'roles'
          AND COLUMN_NAME = 'department_code'
    ");
    if ($columnCheck) {
        $columnRow = $columnCheck->fetch_assoc();
        $hasDepartment = (int)($columnRow['cnt'] ?? 0) > 0;
    }

    $departmentSelect = $hasDepartment ? 'department_code,' : "NULL AS department_code,";

    $stmt = $mysqli->prepare("
        SELECT role_id, role_name, role_code, $departmentSelect description, is_system_role, status
        FROM roles
        WHERE role_id = ?
        LIMIT 1
    ");

    if (!$stmt) {
        return null;
    }

    $stmt->bind_param("i", $roleId);
    $stmt->execute();

    $result = $stmt->get_result();
    $role = $result->fetch_assoc();
    $stmt->close();

    return $role ?: null;
}

/**
 * Check if current user is admin
 * @return bool True if user is admin
 */
function isUserAdmin(): bool
{
    return hasPermission('ROLES', 'view') && 
           hasPermission('ROLE_PERMISSIONS', 'view') && 
           hasPermission('PAGES', 'view');
}

/**
 * Check if a role is the last active admin role
 * @param int $roleId Role ID to check
 * @return bool True if this is the last active admin role
 */
function isLastAdminRole(int $roleId): bool
{
    global $mysqli;

    // Get the admin role code
    $stmt = $mysqli->prepare("
        SELECT role_code FROM roles WHERE role_id = ? LIMIT 1
    ");
    $stmt->bind_param("i", $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $role = $result->fetch_assoc();
    $stmt->close();

    if (!$role || $role['role_code'] !== 'ADMIN') {
        return false;
    }

    // Check if there are other active admin roles
    $stmt = $mysqli->prepare("
        SELECT COUNT(*) as count FROM roles 
        WHERE role_code = 'ADMIN' AND status = 'active' AND role_id != ?
    ");
    $stmt->bind_param("i", $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return (int)($row['count'] ?? 0) === 0;
}

/**
 * Get current user's role code
 * @return string|null Role code or null if not found
 */
function currentUserRoleCode(): ?string
{
    $role = getRoleDetails();
    return $role ? $role['role_code'] : null;
}

/**
 * Check if a user is the last active Administrator user
 * @param int $userId User ID to check
 * @return bool True if this user is the only active user holding the ADMIN role
 */
function isLastAdminUser(int $userId): bool
{
    global $mysqli;

    if ($userId <= 0) {
        return false;
    }

    $stmt = $mysqli->prepare("
        SELECT u.status, r.role_code
        FROM users u
        INNER JOIN roles r ON r.role_id = u.role_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();

    if (!$user || $user['role_code'] !== 'ADMIN' || $user['status'] !== 'active') {
        return false;
    }

    $stmt = $mysqli->prepare("
        SELECT COUNT(*) as count
        FROM users u
        INNER JOIN roles r ON r.role_id = u.role_id
        WHERE r.role_code = 'ADMIN' AND u.status = 'active' AND u.user_id != ?
    ");
    $stmt->bind_param("i", $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();

    return (int)($row['count'] ?? 0) === 0;
}

/**
 * Get all active roles for dropdowns
 * @return array Array of role_id, role_name, role_code
 */
function getActiveRoles(): array
{
    global $mysqli;

    $roles = [];

    $result = $mysqli->query("
        SELECT role_id, role_name, role_code
        FROM roles
        WHERE status = 'active'
        ORDER BY role_name ASC
    ");

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $roles[] = $row;
        }
    }

    return $roles;
}

/**
 * Log permission audit entry
 * @param string $actionType Type of action
 * @param string $targetType Type of target (ROLE, PAGE, PERMISSION, USER)
 * @param int|null $targetId ID of target
 * @param array|null $oldData Old data for update
 * @param array|null $newData New data for insert/update
 */
function logPermissionAudit(
    string $actionType,
    string $targetType,
    ?int $targetId = null,
    ?array $oldData = null,
    ?array $newData = null
): void {
    global $mysqli;

    $performedBy = (int)($_SESSION['user_id'] ?? 0);
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? null;
    $userAgent = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);

    $oldJson = $oldData !== null ? json_encode($oldData, JSON_UNESCAPED_UNICODE) : null;
    $newJson = $newData !== null ? json_encode($newData, JSON_UNESCAPED_UNICODE) : null;

    $stmt = $mysqli->prepare("
        INSERT INTO permission_audit_logs (
            action_type,
            target_type,
            target_id,
            old_data,
            new_data,
            performed_by,
            ip_address,
            user_agent
        )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
    ");

    if (!$stmt) {
        error_log("Failed to log audit: " . $mysqli->error);
        return;
    }

    $stmt->bind_param(
        "ssississ",
        $actionType,
        $targetType,
        $targetId,
        $oldJson,
        $newJson,
        $performedBy,
        $ipAddress,
        $userAgent
    );

    if (!$stmt->execute()) {
        error_log("Failed to execute audit log: " . $stmt->error);
    }

    $stmt->close();
}

/**
 * Validate page code format
 * @param string $pageCode The page code to validate
 * @return bool True if valid format
 */
function isValidPageCode(string $pageCode): bool
{
    return (bool)preg_match('/^[A-Z0-9_]{3,150}$/', trim(strtoupper($pageCode)));
}

/**
 * Validate role code format
 * @param string $roleCode The role code to validate
 * @return bool True if valid format
 */
function isValidRoleCode(string $roleCode): bool
{
    return (bool)preg_match('/^[A-Z0-9_]{3,100}$/', trim(strtoupper($roleCode)));
}

?>
