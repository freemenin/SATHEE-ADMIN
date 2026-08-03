<?php

/**
 * SATHEE CRM - Permission Guard
 * Use this at the top of every protected page
 * 
 * Example:
 * <?php
 * require_once 'include/require_permission.php';
 * requirePermission('ORDERS', 'view');
 * ?>
 */

require_once __DIR__ . '/require_login.php';
require_once __DIR__ . '/permission_helper.php';

/**
 * Require a specific permission or deny access
 * @param string $pageCode The page code to check
 * @param string $action The action to check (view, add, edit, delete, export, approve)
 * @throws Exception on denied access
 */
function requirePermission(string $pageCode, string $action = 'view'): void
{
    if (!hasPermission($pageCode, $action)) {
        http_response_code(403);
        header('Location: access_denied.php');
        exit;
    }
}

/**
 * Require a specific permission for an AJAX/JSON endpoint, or deny with a
 * JSON 403 response instead of redirecting (redirecting would hand the
 * caller an HTML page where it expects JSON).
 * @param string $pageCode The page code to check
 * @param string $action The action to check (view, add, edit, delete, export, approve)
 */
function requirePermissionAjax(string $pageCode, string $action = 'view'): void
{
    if (!hasPermission($pageCode, $action)) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'You do not have permission to perform this action. Please contact your administrator.',
        ]);
        exit;
    }
}

?>
