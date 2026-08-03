-- SATHEE CRM - Role Based Dashboard
-- Additive migration. Safe to re-run on MySQL 8+.

DELIMITER $$

DROP PROCEDURE IF EXISTS sathee_add_roles_department_code $$
CREATE PROCEDURE sathee_add_roles_department_code()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'roles'
          AND COLUMN_NAME = 'department_code'
    ) THEN
        ALTER TABLE roles ADD COLUMN department_code VARCHAR(100) DEFAULT NULL AFTER role_code;
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'roles'
          AND INDEX_NAME = 'idx_roles_department_code'
    ) THEN
        ALTER TABLE roles ADD KEY idx_roles_department_code (department_code);
    END IF;
END $$

DELIMITER ;

CALL sathee_add_roles_department_code();
DROP PROCEDURE IF EXISTS sathee_add_roles_department_code;

UPDATE roles SET department_code = 'ADMIN'
WHERE role_code = 'ADMIN' AND (department_code IS NULL OR department_code = '');

UPDATE roles SET department_code = 'SALES'
WHERE (department_code IS NULL OR department_code = '')
  AND (
      role_code IN ('SALES', 'SALES_PERSON', 'SALES_MANAGER', 'ORDER_OPERATOR')
      OR role_code LIKE '%SALES%'
      OR role_code LIKE '%ORDER%'
  );

UPDATE roles SET department_code = 'PRODUCTION'
WHERE (department_code IS NULL OR department_code = '')
  AND (
      role_code IN ('PRODUCTION', 'PRODUCTION_MANAGER', 'BATCH_OPERATOR')
      OR role_code LIKE '%PRODUCTION%'
      OR role_code LIKE '%BATCH%'
  );

UPDATE roles SET department_code = 'PURCHASE'
WHERE (department_code IS NULL OR department_code = '')
  AND (
      role_code IN ('PURCHASE', 'PURCHASE_MANAGER', 'RAW_MATERIAL_OPERATOR')
      OR role_code LIKE '%PURCHASE%'
      OR role_code LIKE '%RAW_MATERIAL%'
  );

UPDATE roles SET department_code = 'GENERAL'
WHERE department_code IS NULL OR department_code = '';

CREATE TABLE IF NOT EXISTS dashboard_widgets (
    widget_id INT NOT NULL AUTO_INCREMENT,
    widget_name VARCHAR(150) NOT NULL,
    widget_code VARCHAR(150) NOT NULL,
    department_code VARCHAR(100) DEFAULT NULL,
    related_page_code VARCHAR(150) DEFAULT NULL,
    widget_type ENUM('kpi','chart','table','list','shortcut','notice') NOT NULL DEFAULT 'kpi',
    title VARCHAR(200) NOT NULL,
    subtitle VARCHAR(255) DEFAULT NULL,
    icon_class VARCHAR(150) DEFAULT NULL,
    bootstrap_class VARCHAR(100) DEFAULT NULL,
    display_order INT NOT NULL DEFAULT 0,
    contains_amount TINYINT(1) NOT NULL DEFAULT 0,
    admin_only TINYINT(1) NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    PRIMARY KEY (widget_id),
    UNIQUE KEY unique_widget_code (widget_code),
    KEY idx_widget_department (department_code),
    KEY idx_widget_page_code (related_page_code),
    KEY idx_widget_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_dashboard_widgets (
    role_widget_id INT NOT NULL AUTO_INCREMENT,
    role_id INT NOT NULL,
    widget_id INT NOT NULL,
    can_view TINYINT(1) NOT NULL DEFAULT 1,
    display_order INT DEFAULT NULL,
    created_by INT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_by INT DEFAULT NULL,
    updated_at DATETIME DEFAULT NULL,
    PRIMARY KEY (role_widget_id),
    UNIQUE KEY unique_role_widget (role_id, widget_id),
    KEY idx_role_widget_role (role_id),
    KEY idx_role_widget_widget (widget_id),
    CONSTRAINT fk_role_dashboard_role FOREIGN KEY (role_id) REFERENCES roles(role_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_role_dashboard_widget FOREIGN KEY (widget_id) REFERENCES dashboard_widgets(widget_id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO dashboard_widgets
(widget_name, widget_code, department_code, related_page_code, widget_type, title, subtitle, icon_class, bootstrap_class, display_order, contains_amount, admin_only, status)
VALUES
('Welcome Panel', 'WELCOME_PANEL', 'GENERAL', NULL, 'notice', 'Welcome', 'Role-based dashboard overview', 'bi bi-house-door', 'primary', 1, 0, 0, 'active'),
('Total Orders', 'SALES_TOTAL_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Total Orders', 'Total order count', 'bi bi-cart', 'primary', 10, 0, 0, 'active'),
('Today Orders', 'SALES_TODAY_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Orders Today', 'Orders received today', 'bi bi-calendar-day', 'info', 20, 0, 0, 'active'),
('Unassigned Orders', 'SALES_UNASSIGNED_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Not Assigned', 'Orders waiting for distributor assignment', 'bi bi-person-x', 'warning', 30, 0, 0, 'active'),
('Change Distributor Pending', 'SALES_CHANGE_DISTRIBUTOR', 'SALES', 'ORDERS', 'kpi', 'Change Distributor', 'Orders waiting for reassignment', 'bi bi-arrow-repeat', 'danger', 40, 0, 0, 'active'),
('Cancelled Orders', 'SALES_CANCELLED_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Cancelled Orders', 'Cancelled order count', 'bi bi-x-circle', 'danger', 50, 0, 0, 'active'),
('Delivered Orders', 'SALES_DELIVERED_ORDERS', 'SALES', 'ORDERS', 'kpi', 'Delivered Orders', 'Delivered order count', 'bi bi-check-circle', 'success', 60, 0, 0, 'active'),
('Sales Order Trend', 'SALES_ORDER_TREND', 'SALES', 'ORDERS', 'chart', 'Order Trend', 'Daily order count', 'bi bi-graph-up', 'primary', 70, 0, 0, 'active'),
('Recent Orders', 'SALES_RECENT_ORDERS', 'SALES', 'ORDERS', 'table', 'Recent Orders', 'Latest order activity', 'bi bi-clock-history', 'secondary', 80, 0, 0, 'active'),
('Total Products', 'PRODUCTION_TOTAL_PRODUCTS', 'PRODUCTION', 'PRODUCTS', 'kpi', 'Active Products', 'Total active products', 'bi bi-box', 'primary', 100, 0, 0, 'active'),
('Total Batches', 'PRODUCTION_TOTAL_BATCHES', 'PRODUCTION', 'BATCHES', 'kpi', 'Total Batches', 'Total production batches', 'bi bi-collection', 'info', 110, 0, 0, 'active'),
('Today Batches', 'PRODUCTION_TODAY_BATCHES', 'PRODUCTION', 'BATCHES', 'kpi', 'Batches Today', 'Production batches created today', 'bi bi-calendar-check', 'success', 120, 0, 0, 'active'),
('Monthly Production Quantity', 'PRODUCTION_MONTH_QTY', 'PRODUCTION', 'BATCHES', 'kpi', 'Monthly Production', 'Product quantity produced this month', 'bi bi-boxes', 'warning', 130, 0, 0, 'active'),
('Production Trend', 'PRODUCTION_TREND', 'PRODUCTION', 'BATCHES', 'chart', 'Production Trend', 'Daily production quantity', 'bi bi-bar-chart', 'primary', 140, 0, 0, 'active'),
('Recent Batches', 'PRODUCTION_RECENT_BATCHES', 'PRODUCTION', 'BATCHES', 'table', 'Recent Production Batches', 'Latest batch activity', 'bi bi-clock-history', 'secondary', 150, 0, 0, 'active'),
('Total Raw Materials', 'PURCHASE_TOTAL_RAW_MATERIALS', 'PURCHASE', 'RAW_MATERIALS', 'kpi', 'Raw Materials', 'Total active raw materials', 'bi bi-box', 'primary', 200, 0, 0, 'active'),
('Low Stock Raw Materials', 'PURCHASE_LOW_STOCK', 'PURCHASE', 'RAW_MATERIALS', 'kpi', 'Low Stock', 'Raw materials below minimum stock', 'bi bi-exclamation-triangle', 'warning', 210, 0, 0, 'active'),
('Out of Stock Raw Materials', 'PURCHASE_OUT_OF_STOCK', 'PURCHASE', 'RAW_MATERIALS', 'kpi', 'Out of Stock', 'Raw materials with no available stock', 'bi bi-x-octagon', 'danger', 220, 0, 0, 'active'),
('Monthly Raw Material Usage', 'PURCHASE_MONTH_USAGE', 'PURCHASE', 'RAW_MATERIALS', 'kpi', 'Monthly Material Usage', 'Raw material quantity consumed this month', 'bi bi-box-arrow-down', 'info', 230, 0, 0, 'active'),
('Raw Material Trend', 'PURCHASE_RAW_MATERIAL_TREND', 'PURCHASE', 'RAW_MATERIALS', 'chart', 'Raw Material Consumption', 'Daily raw material consumption', 'bi bi-graph-down', 'primary', 240, 0, 0, 'active'),
('Recent Raw Material Usage', 'PURCHASE_RECENT_USAGE', 'PURCHASE', 'RAW_MATERIALS', 'table', 'Recent Raw Material Usage', 'Latest batch material consumption', 'bi bi-clock-history', 'secondary', 250, 0, 0, 'active'),
('Admin Revenue', 'ADMIN_TOTAL_REVENUE', 'ADMIN', 'ORDERS', 'kpi', 'Total Revenue', 'Total order amount', 'bi bi-currency-rupee', 'success', 300, 1, 1, 'active'),
('Admin Monthly Revenue', 'ADMIN_MONTHLY_REVENUE', 'ADMIN', 'ORDERS', 'kpi', 'Monthly Revenue', 'Current month order amount', 'bi bi-cash-stack', 'success', 310, 1, 1, 'active')
ON DUPLICATE KEY UPDATE
    widget_name = VALUES(widget_name),
    department_code = VALUES(department_code),
    related_page_code = VALUES(related_page_code),
    widget_type = VALUES(widget_type),
    title = VALUES(title),
    subtitle = VALUES(subtitle),
    icon_class = VALUES(icon_class),
    bootstrap_class = VALUES(bootstrap_class),
    display_order = VALUES(display_order),
    contains_amount = VALUES(contains_amount),
    admin_only = VALUES(admin_only),
    status = VALUES(status);

INSERT INTO role_dashboard_widgets (role_id, widget_id, can_view, display_order)
SELECT r.role_id, w.widget_id, 1, w.display_order
FROM roles r
CROSS JOIN dashboard_widgets w
WHERE r.role_code = 'ADMIN' AND r.status = 'active' AND w.status = 'active'
ON DUPLICATE KEY UPDATE can_view = 1, display_order = VALUES(display_order);

INSERT INTO role_dashboard_widgets (role_id, widget_id, can_view, display_order)
SELECT r.role_id, w.widget_id, 1, w.display_order
FROM roles r
INNER JOIN dashboard_widgets w ON w.department_code IN ('GENERAL', r.department_code)
WHERE r.department_code IN ('SALES', 'PRODUCTION', 'PURCHASE', 'GENERAL')
  AND r.status = 'active'
  AND w.status = 'active'
  AND w.contains_amount = 0
  AND w.admin_only = 0
ON DUPLICATE KEY UPDATE can_view = 1, display_order = VALUES(display_order);

INSERT INTO system_pages
(parent_id, page_name, page_code, page_url, icon_class, menu_group, description, display_order, show_in_menu, status)
VALUES
(NULL, 'Dashboard Widgets', 'DASHBOARD_WIDGETS', 'dashboard_widget_list.php', 'bi bi-grid-3x3-gap', 'Administration', 'Manage dashboard widget definitions', 120, 1, 'active'),
(NULL, 'Role Dashboard Widgets', 'ROLE_DASHBOARD_WIDGETS', 'role_dashboard_widgets.php', 'bi bi-sliders', 'Administration', 'Assign dashboard widgets to roles', 121, 1, 'active')
ON DUPLICATE KEY UPDATE status = 'active', page_url = VALUES(page_url), show_in_menu = 1;

INSERT INTO role_page_permissions
(role_id, page_id, can_view, can_add, can_edit, can_delete, can_export, can_approve, created_by)
SELECT r.role_id, p.page_id, 1, 1, 1, 1, 1, 1, r.role_id
FROM roles r
CROSS JOIN system_pages p
WHERE r.role_code = 'ADMIN'
  AND r.status = 'active'
  AND p.page_code IN ('DASHBOARD_WIDGETS', 'ROLE_DASHBOARD_WIDGETS')
ON DUPLICATE KEY UPDATE
    can_view = 1,
    can_add = 1,
    can_edit = 1,
    can_delete = 1,
    can_export = 1,
    can_approve = 1;
