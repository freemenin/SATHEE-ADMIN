-- ============================================================================
-- SATHEE CRM - Role and Page Permission Management System
-- Migration v3 (additive, idempotent - safe to run more than once)
--
-- Run AFTER database_migration_permissions.sql and
-- database_migration_permissions_v2.sql have already been applied.
--
-- Fixes: dashboard_widget_list.php / dashboard_widget_form.php call
-- requirePermission('DASHBOARD_WIDGETS', ...), but no system_pages row for
-- that code was ever inserted, so hasPermission() always returns false and
-- the feature is unreachable by every role, including Administrator.
-- ============================================================================

INSERT INTO system_pages (
    parent_id, page_name, page_code, page_url, icon_class, menu_group,
    description, display_order, show_in_menu, status
) VALUES
(NULL, 'Dashboard Widgets', 'DASHBOARD_WIDGETS', 'dashboard_widget_list.php', 'bi bi-grid-1x2', 'Administration', 'Manage role-based dashboard widgets', 105, 1, 'active')
ON DUPLICATE KEY UPDATE status = 'active';

-- Grant the Administrator role full permission on this page (same pattern as
-- Step 8 of the original migration and Step 3 of v2).
INSERT INTO role_page_permissions (
    role_id, page_id, can_view, can_add, can_edit, can_delete, can_export, can_approve, created_by
)
SELECT
    r.role_id, p.page_id, 1, 1, 1, 1, 1, 1, r.role_id
FROM roles r
CROSS JOIN system_pages p
WHERE r.role_code = 'ADMIN'
  AND r.status = 'active'
  AND p.page_code = 'DASHBOARD_WIDGETS'
ON DUPLICATE KEY UPDATE
    can_view = 1, can_add = 1, can_edit = 1, can_delete = 1, can_export = 1, can_approve = 1;

-- End of Migration v3
-- ============================================================================
