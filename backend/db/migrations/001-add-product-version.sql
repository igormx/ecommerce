-- Adds the optimistic-lock counter to an existing database (idempotent: safe to re-run).
-- Fresh databases get the column from db/init/01-schema.sql.
--
--   docker compose exec -T db sh -c 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE"' \
--     < backend/db/migrations/001-add-product-version.sql
SET @has_col = (SELECT COUNT(*) FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'version');
SET @ddl = IF(@has_col = 0,
              'ALTER TABLE products ADD COLUMN version INT UNSIGNED NOT NULL DEFAULT 1 AFTER active',
              'DO 0');
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
