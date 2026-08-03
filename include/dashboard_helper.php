<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permission_helper.php';

function dashboardTableExists(string $table): bool
{
    global $mysqli;

    static $cache = [];
    $table = trim($table);
    if ($table === '') {
        return false;
    }
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS cnt
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    if (!$stmt) {
        return $cache[$table] = false;
    }
    $stmt->bind_param('s', $table);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$table] = ((int)($row['cnt'] ?? 0) > 0);
}

function dashboardColumnExists(string $table, string $column): bool
{
    global $mysqli;

    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    if (!dashboardTableExists($table)) {
        return $cache[$key] = false;
    }

    $stmt = $mysqli->prepare("
        SELECT COUNT(*) AS cnt
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    if (!$stmt) {
        return $cache[$key] = false;
    }
    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    return $cache[$key] = ((int)($row['cnt'] ?? 0) > 0);
}

function dashboardFirstExistingColumn(string $table, array $columns): ?string
{
    foreach ($columns as $column) {
        if (dashboardColumnExists($table, $column)) {
            return $column;
        }
    }
    return null;
}

function getCurrentUserRole(): ?array
{
    global $mysqli;

    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId <= 0 || !dashboardTableExists('users') || !dashboardTableExists('roles')) {
        return null;
    }

    $userNameCol = dashboardFirstExistingColumn('users', ['name', 'username', 'full_name']);
    $userStatusCol = dashboardColumnExists('users', 'status') ? 'u.status' : "'active'";
    $departmentCol = dashboardColumnExists('roles', 'department_code') ? 'r.department_code' : 'NULL';
    $userNameSelect = $userNameCol ? "u.`$userNameCol`" : "''";

    $stmt = $mysqli->prepare("
        SELECT
            u.user_id,
            $userNameSelect AS name,
            u.role_id,
            $userStatusCol AS user_status,
            r.role_name,
            r.role_code,
            $departmentCol AS department_code,
            r.status AS role_status
        FROM users u
        INNER JOIN roles r ON r.role_id = u.role_id
        WHERE u.user_id = ?
        LIMIT 1
    ");
    if (!$stmt) {
        error_log('Dashboard Helper: getCurrentUserRole prepare failed - ' . $mysqli->error);
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        return null;
    }

    if (empty($row['department_code'])) {
        $row['department_code'] = inferDepartmentCode((string)($row['role_code'] ?? ''));
    }

    return $row;
}

function inferDepartmentCode(string $roleCode): string
{
    $roleCode = strtoupper(trim($roleCode));
    if ($roleCode === 'ADMIN' || strpos($roleCode, 'ADMIN') !== false) {
        return 'ADMIN';
    }
    if (strpos($roleCode, 'SALE') !== false || strpos($roleCode, 'ORDER') !== false) {
        return 'SALES';
    }
    if (strpos($roleCode, 'PRODUCTION') !== false || strpos($roleCode, 'BATCH') !== false) {
        return 'PRODUCTION';
    }
    if (strpos($roleCode, 'PURCHASE') !== false || strpos($roleCode, 'RAW_MATERIAL') !== false) {
        return 'PURCHASE';
    }
    return 'GENERAL';
}

function isAdministrator(): bool
{
    $role = getCurrentUserRole();
    return strtoupper((string)($role['role_code'] ?? '')) === 'ADMIN'
        || strtoupper((string)($role['department_code'] ?? '')) === 'ADMIN';
}

function canViewFinancialAmounts(): bool
{
    return isAdministrator();
}

function dashboardDefaultWidgetsForRole(): array
{
    $role = getCurrentUserRole();
    $department = strtoupper((string)($role['department_code'] ?? 'GENERAL'));
    $isAdmin = isAdministrator();

    $definitions = [
        ['WELCOME_PANEL', 'GENERAL', null, 'notice', 'Welcome', 1, 0, 0],
        ['SALES_TOTAL_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Total Orders', 10, 0, 0],
        ['SALES_TODAY_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Orders Today', 20, 0, 0],
        ['SALES_UNASSIGNED_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Not Assigned', 30, 0, 0],
        ['SALES_CHANGE_DISTRIBUTOR', 'SALES', 'ORDERS', 'kpi', 'Change Distributor', 40, 0, 0],
        ['SALES_CANCELLED_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Cancelled Orders', 50, 0, 0],
        ['SALES_DELIVERED_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Delivered Orders', 60, 0, 0],
        ['SALES_ORDER_TREND', 'SALES', 'ORDERS', 'chart', 'Order Trend', 70, 0, 0],
        ['SALES_RECENT_ORDERS', 'SALES', 'ORDERS', 'table', 'Recent Orders', 80, 0, 0],
        ['PRODUCTION_TOTAL_PRODUCTS', 'PRODUCTION', 'PRODUCTS', 'kpi', 'Active Products', 100, 0, 0],
        ['PRODUCTION_TOTAL_BATCHES', 'PRODUCTION', 'BATCHES', 'kpi', 'Total Batches', 110, 0, 0],
        ['PRODUCTION_TODAY_BATCHES', 'PRODUCTION', 'BATCHES', 'kpi', 'Batches Today', 120, 0, 0],
        ['PRODUCTION_MONTH_QTY', 'PRODUCTION', 'BATCHES', 'kpi', 'Monthly Production', 130, 0, 0],
        ['PRODUCTION_TREND', 'PRODUCTION', 'BATCHES', 'chart', 'Production Trend', 140, 0, 0],
        ['PRODUCTION_RECENT_BATCHES', 'PRODUCTION', 'BATCHES', 'table', 'Recent Production Batches', 150, 0, 0],
        ['PURCHASE_TOTAL_RAW_MATERIALS', 'PURCHASE', 'RAW_MATERIALS', 'kpi', 'Raw Materials', 200, 0, 0],
        ['PURCHASE_LOW_STOCK', 'PURCHASE', 'RAW_MATERIALS', 'kpi', 'Low Stock', 210, 0, 0],
        ['PURCHASE_OUT_OF_STOCK', 'PURCHASE', 'RAW_MATERIALS', 'kpi', 'Out of Stock', 220, 0, 0],
        ['PURCHASE_MONTH_USAGE', 'PURCHASE', 'RAW_MATERIALS', 'kpi', 'Monthly Material Usage', 230, 0, 0],
        ['PURCHASE_RAW_MATERIAL_TREND', 'PURCHASE', 'RAW_MATERIALS', 'chart', 'Raw Material Consumption', 240, 0, 0],
        ['PURCHASE_RECENT_USAGE', 'PURCHASE', 'RAW_MATERIALS', 'table', 'Recent Raw Material Usage', 250, 0, 0],
        ['ADMIN_TOTAL_REVENUE', 'ADMIN', 'ORDERS', 'kpi', 'Total Revenue', 300, 1, 1],
        ['ADMIN_MONTHLY_REVENUE', 'ADMIN', 'ORDERS', 'kpi', 'Monthly Revenue', 310, 1, 1],
    ];

    $widgets = [];
    foreach ($definitions as $definition) {
        [$code, $widgetDepartment, $pageCode, $type, $title, $order, $containsAmount, $adminOnly] = $definition;
        if (!$isAdmin && ((int)$containsAmount === 1 || (int)$adminOnly === 1)) {
            continue;
        }
        if (!$isAdmin && !in_array($widgetDepartment, ['GENERAL', $department], true)) {
            continue;
        }
        if ($pageCode && !$isAdmin && !hasPermission($pageCode, 'view')) {
            continue;
        }
        $widgets[] = [
            'widget_id' => 0,
            'widget_name' => $title,
            'widget_code' => $code,
            'department_code' => $widgetDepartment,
            'related_page_code' => $pageCode,
            'widget_type' => $type,
            'title' => $title,
            'subtitle' => null,
            'icon_class' => null,
            'bootstrap_class' => null,
            'contains_amount' => $containsAmount,
            'admin_only' => $adminOnly,
            'final_display_order' => $order,
        ];
    }

    return $widgets;
}

function getAllowedDashboardWidgets(): array
{
    global $mysqli;

    $roleId = currentUserRoleId();
    if ($roleId <= 0) {
        return [];
    }

    if (!dashboardTableExists('dashboard_widgets') || !dashboardTableExists('role_dashboard_widgets')) {
        return dashboardDefaultWidgetsForRole();
    }

    $stmt = $mysqli->prepare("
        SELECT
            w.widget_id,
            w.widget_name,
            w.widget_code,
            w.department_code,
            w.related_page_code,
            w.widget_type,
            w.title,
            w.subtitle,
            w.icon_class,
            w.bootstrap_class,
            w.contains_amount,
            w.admin_only,
            COALESCE(rdw.display_order, w.display_order) AS final_display_order
        FROM role_dashboard_widgets rdw
        INNER JOIN dashboard_widgets w ON w.widget_id = rdw.widget_id
        INNER JOIN roles r ON r.role_id = rdw.role_id
        WHERE rdw.role_id = ?
          AND rdw.can_view = 1
          AND w.status = 'active'
          AND r.status = 'active'
        ORDER BY final_display_order ASC, w.widget_id ASC
    ");
    if (!$stmt) {
        error_log('Dashboard Helper: widget query prepare failed - ' . $mysqli->error);
        return dashboardDefaultWidgetsForRole();
    }

    $stmt->bind_param('i', $roleId);
    $stmt->execute();
    $result = $stmt->get_result();
    $widgets = [];
    while ($row = $result->fetch_assoc()) {
        if ((int)$row['contains_amount'] === 1 && !canViewFinancialAmounts()) {
            continue;
        }
        if ((int)$row['admin_only'] === 1 && !isAdministrator()) {
            continue;
        }
        if (!empty($row['related_page_code']) && !isAdministrator() && !hasPermission($row['related_page_code'], 'view')) {
            continue;
        }
        $widgets[] = $row;
    }
    $stmt->close();

    return $widgets ?: dashboardDefaultWidgetsForRole();
}

function hasDashboardWidget(string $widgetCode): bool
{
    static $widgetMap = null;

    if ($widgetMap === null) {
        $widgetMap = [];
        foreach (getAllowedDashboardWidgets() as $widget) {
            $widgetMap[strtoupper((string)$widget['widget_code'])] = true;
        }
    }

    return isset($widgetMap[strtoupper(trim($widgetCode))]);
}

function dashboardPermittedShortcuts(array $preferredCodes = []): array
{
    $pages = getAllowedMenuPages();
    $preferredCodes = array_map('strtoupper', $preferredCodes);
    $shortcuts = [];

    foreach ($pages as $page) {
        $pageCode = strtoupper((string)($page['page_code'] ?? ''));
        if ($preferredCodes && !in_array($pageCode, $preferredCodes, true)) {
            continue;
        }
        if (empty($page['page_url'])) {
            continue;
        }
        $shortcuts[] = $page;
    }

    return $shortcuts;
}

function dashboardJson($value): string
{
    return json_encode($value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}

