-- Per-reseller percentage discounts. Safe to run once on an existing database.
SET @discount_column_exists := (
  SELECT COUNT(*)
  FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'resellers'
    AND COLUMN_NAME = 'discount_percent'
);
SET @discount_sql := IF(
  @discount_column_exists = 0,
  'ALTER TABLE resellers ADD COLUMN discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0',
  'SELECT 1'
);
PREPARE discount_stmt FROM @discount_sql;
EXECUTE discount_stmt;
DEALLOCATE PREPARE discount_stmt;
