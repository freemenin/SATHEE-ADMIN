-- ============================================================================
-- SATHEE CRM - Role and Page Permission Management System
-- Migration v4 (additive, idempotent - safe to run more than once)
--
-- Run AFTER database_migration_permissions.sql, _v2.sql and _v3.sql have
-- already been applied.
--
-- Adds the "Tier A" pages migrated from app.mysathee.com that only depend on
-- tables already live in this project (orders, customers, distributors,
-- products, users) plus the two new columns sub_distributor_list.php needs.
-- ============================================================================

-- Step 1: Register the new pages
-- ============================================================================
INSERT INTO system_pages (
    parent_id, page_name, page_code, page_url, icon_class, menu_group,
    description, display_order, show_in_menu, status
) VALUES
(NULL, 'Sub-Distributors', 'SUB_DISTRIBUTORS', 'sub_distributor_list.php', 'bi bi-diagram-3', 'Distribution', 'Assign sub-distributors under a main distributor', 36, 1, 'active'),
(NULL, 'Order Time Management', 'TIME_MANAGEMENT', 'time_manage.php', 'bi bi-clock-history', 'Distribution', 'Control when distributors can view orders', 37, 1, 'active'),
(NULL, 'Order & Delivery Reports', 'REPORT_ORDERS', 'report_orders.php', 'bi bi-truck', 'Reports', 'Order and delivery performance report', 74, 1, 'active'),
(NULL, 'Distributor Delivered Report', 'REPORT_DISTRIBUTOR_DELIVERED', 'report_distributor_delivered.php', 'bi bi-check2-circle', 'Reports', 'Delivered order counts by distributor', 75, 1, 'active'),
(NULL, 'Distributor Performance Report', 'REPORT_DISTRIBUTOR_PERFORMANCE', 'report_distributor_performance.php', 'bi bi-speedometer', 'Reports', 'Distributor delivery rate and speed report', 76, 1, 'active')
ON DUPLICATE KEY UPDATE status = 'active';

-- Step 2: Grant the Administrator role full permission on these new pages
-- ============================================================================
INSERT INTO role_page_permissions (
    role_id, page_id, can_view, can_add, can_edit, can_delete, can_export, can_approve, created_by
)
SELECT
    r.role_id, p.page_id, 1, 1, 1, 1, 1, 1, r.role_id
FROM roles r
CROSS JOIN system_pages p
WHERE r.role_code = 'ADMIN'
  AND r.status = 'active'
  AND p.page_code IN (
      'SUB_DISTRIBUTORS', 'TIME_MANAGEMENT',
      'REPORT_ORDERS', 'REPORT_DISTRIBUTOR_DELIVERED', 'REPORT_DISTRIBUTOR_PERFORMANCE'
  )
ON DUPLICATE KEY UPDATE
    can_view = 1, can_add = 1, can_edit = 1, can_delete = 1, can_export = 1, can_approve = 1;

-- Step 3: Add the sub-distributor hierarchy columns sub_distributor_list.php
-- needs on the distributors table. Guarded so it's safe to re-run.
-- ============================================================================
DELIMITER $$

DROP PROCEDURE IF EXISTS sathee_add_distributor_hierarchy_columns $$
CREATE PROCEDURE sathee_add_distributor_hierarchy_columns()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'distributors'
          AND COLUMN_NAME = 'parent_distributor_id'
    ) THEN
        ALTER TABLE distributors ADD COLUMN parent_distributor_id INT NULL AFTER distributor_type;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'distributors'
          AND COLUMN_NAME = 'parent_distributor_ids'
    ) THEN
        ALTER TABLE distributors ADD COLUMN parent_distributor_ids JSON NULL AFTER parent_distributor_id;
    END IF;
END $$

DELIMITER ;

CALL sathee_add_distributor_hierarchy_columns();
DROP PROCEDURE IF EXISTS sathee_add_distributor_hierarchy_columns;

-- Note: time_manage.php manages its own columns (order_view_enabled,
-- order_view_start, order_view_end, order_view_message,
-- order_view_updated_at) on distributors via a runtime SHOW COLUMNS /
-- ALTER TABLE guard the first time the page loads, so no migration step is
-- needed for those here.

-- End of Migration v4
-- ============================================================================
