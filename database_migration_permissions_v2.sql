-- ============================================================================
-- SATHEE CRM - Role and Page Permission Management System
-- Migration v2 (additive, idempotent - safe to run more than once)
--
-- Run AFTER database_migration_permissions.sql has already been applied.
-- This file only ADDS things: it does not modify or drop anything from the
-- original migration.
-- ============================================================================

-- Step 1: Add the users.role_id -> roles.role_id foreign key, if it isn't
-- already there. Wrapped in a guarded procedure because plain
-- "ALTER TABLE ... ADD CONSTRAINT" is not idempotent - running it twice would
-- error out on the second run.
-- ============================================================================
DELIMITER $$

DROP PROCEDURE IF EXISTS sathee_add_fk_users_role $$
CREATE PROCEDURE sathee_add_fk_users_role()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
        WHERE CONSTRAINT_SCHEMA = DATABASE()
          AND TABLE_NAME = 'users'
          AND CONSTRAINT_NAME = 'fk_users_role'
    ) THEN
        ALTER TABLE users
            ADD CONSTRAINT fk_users_role
            FOREIGN KEY (role_id) REFERENCES roles(role_id)
            ON DELETE SET NULL
            ON UPDATE CASCADE;
    END IF;
END $$

DELIMITER ;

CALL sathee_add_fk_users_role();
DROP PROCEDURE IF EXISTS sathee_add_fk_users_role;

-- Step 2: Add a handful of pages that exist today only as hardcoded sidebar
-- links (with real files behind them) so the new dynamic sidebar has
-- something to show for them. Safe to re-run (ON DUPLICATE KEY UPDATE).
-- ============================================================================
INSERT INTO system_pages (
    parent_id, page_name, page_code, page_url, icon_class, menu_group,
    description, display_order, show_in_menu, status
) VALUES
(NULL, 'Purchase Requests', 'PURCHASE_REQUESTS', 'purchase_requests.php', 'bi bi-clipboard-check', 'Distribution', 'Distributor purchase requests', 35, 1, 'active'),
(NULL, 'Material Purchase', 'RAW_MATERIAL_PURCHASE', 'raw_material_purchase_list.php', 'bi bi-truck', 'Inventory', 'Raw material purchase list', 57, 1, 'active'),
(NULL, 'Product Report', 'PRODUCT_REPORT', 'product_report.php', 'bi bi-graph-up', 'Reports', 'Product performance report', 71, 1, 'active'),
(NULL, 'Distributor Report', 'DISTRIBUTOR_REPORT', 'distributor_report.php', 'bi bi-bar-chart-line', 'Reports', 'Distributor sale & income report', 72, 1, 'active'),
(NULL, 'Material Report', 'RAW_MATERIAL_REPORT', 'raw_material_report.php', 'bi bi-clipboard-data', 'Reports', 'Material utilization report', 73, 1, 'active')
ON DUPLICATE KEY UPDATE status = 'active';

-- Step 3: Grant the Administrator role full permission on these new pages
-- (same pattern as Step 8 of the original migration, scoped to just the new
-- page codes so it doesn't touch anything else).
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
      'PURCHASE_REQUESTS', 'RAW_MATERIAL_PURCHASE',
      'PRODUCT_REPORT', 'DISTRIBUTOR_REPORT', 'RAW_MATERIAL_REPORT'
  )
ON DUPLICATE KEY UPDATE
    can_view = 1, can_add = 1, can_edit = 1, can_delete = 1, can_export = 1, can_approve = 1;

-- End of Migration v2
-- ============================================================================
