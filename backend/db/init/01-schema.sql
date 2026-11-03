SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS products (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sku         VARCHAR(32)  NOT NULL,
  name        VARCHAR(255) NOT NULL,
  description TEXT         NULL,
  category    VARCHAR(100) NULL,
  price       DECIMAL(10,2) UNSIGNED NOT NULL,
  stock       INT UNSIGNED NOT NULL DEFAULT 0,
  weight_kg   DECIMAL(8,3) UNSIGNED NULL,
  active      TINYINT(1) NOT NULL DEFAULT 1,
  version     INT UNSIGNED NOT NULL DEFAULT 1,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_products_sku (sku),
  KEY idx_products_category (category),
  KEY idx_products_price (price),
  KEY idx_products_active (active),
  FULLTEXT KEY ft_products_search (name, description, category)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS orders (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  status        ENUM('PAID', 'FAILED') NOT NULL,
  total_cents   BIGINT UNSIGNED NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_orders_status (status),
  KEY idx_orders_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS order_items (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  order_id          INT UNSIGNED NOT NULL,
  product_id        INT UNSIGNED NOT NULL,
  product_name      VARCHAR(255) NOT NULL,
  sku               VARCHAR(32)  NOT NULL,
  quantity          INT UNSIGNED NOT NULL,
  unit_price_cents  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (id),
  KEY idx_order_items_order_id (order_id),
  KEY idx_order_items_product_id (product_id),
  CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders (id),
  CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
