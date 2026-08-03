-- ============================================================================
-- SATHEE CRM - Role and Page Permission Management System
-- Migration v5 (additive, idempotent - safe to run more than once)
--
-- Adds orders.created_by so every order remembers which logged-in user
-- created it. Used to scope the Sales dashboard to "my orders" instead of
-- company-wide totals for non-admin sales users.
-- ============================================================================

DELIMITER $$

DROP PROCEDURE IF EXISTS sathee_add_orders_created_by $$
CREATE PROCEDURE sathee_add_orders_created_by()
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'orders'
          AND COLUMN_NAME = 'created_by'
    ) THEN
        ALTER TABLE orders ADD COLUMN created_by INT NULL AFTER order_notes;
        ALTER TABLE orders ADD KEY idx_orders_created_by (created_by);
    END IF;
END $$

DELIMITER ;

CALL sathee_add_orders_created_by();
DROP PROCEDURE IF EXISTS sathee_add_orders_created_by;

-- End of Migration v5
-- ============================================================================
