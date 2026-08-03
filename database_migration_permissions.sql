-- ============================================================================
-- SATHEE CRM - Role and Page Permission Management System
-- Database Migration Script
-- ============================================================================

-- Step 1: Create Roles Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS roles (
    role_id INT NOT NULL AUTO_INCREMENT,
    role_name VARCHAR(100) NOT NULL,
    role_code VARCHAR(100) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    is_system_role TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,

    PRIMARY KEY (role_id),
    UNIQUE KEY unique_role_name (role_name),
    UNIQUE KEY unique_role_code (role_code),
    KEY idx_role_status (status)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- Step 2: Create System Pages Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS system_pages (
    page_id INT NOT NULL AUTO_INCREMENT,
    parent_id INT DEFAULT NULL,

    page_name VARCHAR(150) NOT NULL,
    page_code VARCHAR(150) NOT NULL,
    page_url VARCHAR(255) DEFAULT NULL,
    icon_class VARCHAR(150) DEFAULT NULL,
    menu_group VARCHAR(100) DEFAULT NULL,
    description VARCHAR(255) DEFAULT NULL,

    display_order INT NOT NULL DEFAULT 0,
    show_in_menu TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',

    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,

    PRIMARY KEY (page_id),
    UNIQUE KEY unique_page_code (page_code),
    KEY idx_parent_id (parent_id),
    KEY idx_menu_group (menu_group),
    KEY idx_page_status (status),

    CONSTRAINT fk_system_pages_parent
        FOREIGN KEY (parent_id)
        REFERENCES system_pages(page_id)
        ON DELETE SET NULL
        ON UPDATE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- Step 3: Create Role Page Permissions Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS role_page_permissions (
    permission_id INT NOT NULL AUTO_INCREMENT,
    role_id INT NOT NULL,
    page_id INT NOT NULL,

    can_view TINYINT(1) NOT NULL DEFAULT 0,
    can_add TINYINT(1) NOT NULL DEFAULT 0,
    can_edit TINYINT(1) NOT NULL DEFAULT 0,
    can_delete TINYINT(1) NOT NULL DEFAULT 0,
    can_export TINYINT(1) NOT NULL DEFAULT 0,
    can_approve TINYINT(1) NOT NULL DEFAULT 0,

    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,

    PRIMARY KEY (permission_id),
    UNIQUE KEY unique_role_page (role_id, page_id),
    KEY idx_permission_role (role_id),
    KEY idx_permission_page (page_id),

    CONSTRAINT fk_permission_role
        FOREIGN KEY (role_id)
        REFERENCES roles(role_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE,

    CONSTRAINT fk_permission_page
        FOREIGN KEY (page_id)
        REFERENCES system_pages(page_id)
        ON DELETE CASCADE
        ON UPDATE CASCADE
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- Step 4: Create Permission Audit Log Table
-- ============================================================================
CREATE TABLE IF NOT EXISTS permission_audit_logs (
    audit_id INT NOT NULL AUTO_INCREMENT,
    action_type VARCHAR(100) NOT NULL,
    target_type VARCHAR(100) NOT NULL,
    target_id INT DEFAULT NULL,
    old_data LONGTEXT DEFAULT NULL,
    new_data LONGTEXT DEFAULT NULL,
    performed_by INT DEFAULT NULL,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent VARCHAR(500) DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (audit_id),
    KEY idx_audit_action (action_type),
    KEY idx_audit_target (target_type, target_id),
    KEY idx_audit_user (performed_by),
    KEY idx_audit_created_at (created_at)
) ENGINE=InnoDB
DEFAULT CHARSET=utf8mb4
COLLATE=utf8mb4_unicode_ci;

-- Step 5: Update Users Table
-- ============================================================================
-- Check if role_id column exists, if not add it
ALTER TABLE users
ADD COLUMN role_id INT DEFAULT NULL AFTER password;

-- Add index if not exists
ALTER TABLE users
ADD KEY idx_users_role_id (role_id);

-- Add foreign key if not exists (using a helper)
-- This will be added after migration completes

-- Step 6: Insert Administrator Role
-- ============================================================================
INSERT INTO roles (role_name, role_code, description, is_system_role, status)
VALUES ('Administrator', 'ADMIN', 'Full system administrator access', 1, 'active')
ON DUPLICATE KEY UPDATE status = 'active';

-- Step 7: Insert Initial Pages
-- ============================================================================
INSERT INTO system_pages (
    parent_id, page_name, page_code, page_url, icon_class, menu_group, 
    description, display_order, show_in_menu, status
) VALUES
(NULL, 'Dashboard', 'DASHBOARD', 'dashboard.php', 'bi bi-speedometer2', 'Main', 'CRM dashboard', 1, 1, 'active'),
(NULL, 'Orders', 'ORDERS', 'order_list.php', 'bi bi-cart', 'Sales', 'Order management', 10, 1, 'active'),
(NULL, 'Pending Orders', 'PENDING_ORDERS', 'pending_order_list.php', 'bi bi-hourglass-split', 'Sales', 'Pending orders', 11, 1, 'active'),
(NULL, 'Customers', 'CUSTOMERS', 'customer_list.php', 'bi bi-people', 'Sales', 'Customer management', 20, 1, 'active'),
(NULL, 'Distributors', 'DISTRIBUTORS', 'distributor_list.php', 'bi bi-shop', 'Distribution', 'Distributor management', 30, 1, 'active'),
(NULL, 'Products', 'PRODUCTS', 'product_list.php', 'bi bi-box-seam', 'Inventory', 'Product management', 40, 1, 'active'),
(NULL, 'Inventory', 'INVENTORY', 'inventory_list.php', 'bi bi-stack', 'Inventory', 'Inventory management', 50, 1, 'active'),
(NULL, 'Raw Materials', 'RAW_MATERIALS', 'raw_material_manage.php', 'bi bi-gear', 'Inventory', 'Raw material management', 55, 1, 'active'),
(NULL, 'Batches', 'BATCHES', 'batch_list.php', 'bi bi-boxes', 'Production', 'Batch management', 65, 1, 'active'),
(NULL, 'Reports', 'REPORTS', 'batch_settlement_report.php', 'bi bi-bar-chart', 'Reports', 'Business reports', 70, 1, 'active'),
(NULL, 'Users', 'USERS', 'user_list.php', 'bi bi-person-gear', 'Administration', 'User management', 80, 1, 'active'),
(NULL, 'Roles', 'ROLES', 'role_list.php', 'bi bi-person-badge', 'Administration', 'Role management', 90, 1, 'active'),
(NULL, 'Pages', 'PAGES', 'page_list.php', 'bi bi-layout-text-window', 'Administration', 'Manage system pages', 100, 1, 'active'),
(NULL, 'Permissions', 'ROLE_PERMISSIONS', 'role_permissions.php', 'bi bi-shield-lock', 'Administration', 'Assign pages to roles', 110, 1, 'active')
ON DUPLICATE KEY UPDATE status = 'active';

-- Step 8: Give Administrator Full Permissions
-- ============================================================================
INSERT INTO role_page_permissions (
    role_id, page_id, can_view, can_add, can_edit, can_delete, can_export, can_approve, created_by
)
SELECT
    r.role_id, p.page_id, 1, 1, 1, 1, 1, 1, r.role_id
FROM roles r
CROSS JOIN system_pages p
WHERE r.role_code = 'ADMIN' AND r.status = 'active' AND p.status = 'active'
ON DUPLICATE KEY UPDATE
    can_view = 1, can_add = 1, can_edit = 1, can_delete = 1, can_export = 1, can_approve = 1;

-- Step 9: Migrate Existing Users to Roles
-- ============================================================================
-- First, get or create the Administrator role
SET @admin_role_id = (SELECT role_id FROM roles WHERE role_code = 'ADMIN' LIMIT 1);

-- Update existing admin users
UPDATE users SET role_id = @admin_role_id WHERE role = 'admin' AND role_id IS NULL;

-- Create other roles if needed for sales and production.
-- INSERT IGNORE relies on the unique keys already on roles.role_name /
-- roles.role_code to silently skip any of these that already exist, so this
-- is safe to re-run.
INSERT IGNORE INTO roles (role_name, role_code, description, status) VALUES
('Sales', 'SALES', 'Sales user role', 'active'),
('Production', 'PRODUCTION', 'Production user role', 'active'),
('Purchase', 'PURCHASE', 'Purchase user role', 'active');

-- Update sales users
UPDATE users SET role_id = (SELECT role_id FROM roles WHERE role_code = 'SALES' LIMIT 1) 
WHERE role = 'sales' AND role_id IS NULL;

-- Update production users
UPDATE users SET role_id = (SELECT role_id FROM roles WHERE role_code = 'PRODUCTION' LIMIT 1) 
WHERE role = 'production' AND role_id IS NULL;

-- Update purchase users
UPDATE users SET role_id = (SELECT role_id FROM roles WHERE role_code = 'PURCHASE' LIMIT 1) 
WHERE role = 'purchase' AND role_id IS NULL;

-- Step 10: Assign default permissions to newly created roles
-- ============================================================================
-- Sales: Dashboard, Orders, Customers, Products, Reports (view only)
INSERT INTO role_page_permissions (role_id, page_id, can_view, can_add, can_edit, can_delete, can_export, created_by)
SELECT r.role_id, p.page_id, 
    CASE WHEN p.page_code IN ('DASHBOARD', 'ORDERS', 'CUSTOMERS', 'PRODUCTS', 'REPORTS') THEN 1 ELSE 0 END,
    CASE WHEN p.page_code IN ('ORDERS', 'CUSTOMERS') THEN 1 ELSE 0 END,
    CASE WHEN p.page_code IN ('ORDERS', 'CUSTOMERS') THEN 1 ELSE 0 END,
    0, 1, r.role_id
FROM roles r
CROSS JOIN system_pages p
WHERE r.role_code = 'SALES' AND r.status = 'active' AND p.status = 'active'
ON DUPLICATE KEY UPDATE can_view = VALUES(can_view), can_add = VALUES(can_add);

-- End of Migration
-- ============================================================================
