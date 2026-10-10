-- Minimal base schema (the production base tables are created outside this repo).
CREATE TABLE resellers (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  email VARCHAR(190) NOT NULL,
  token_hash VARCHAR(255) NOT NULL,
  status ENUM('active','inactive') NOT NULL DEFAULT 'active',
  balance_rsd INT NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id), UNIQUE KEY uniq_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE product_prices (
  product_id VARCHAR(64) NOT NULL, product_name VARCHAR(190) NOT NULL, account_type VARCHAR(64) NOT NULL,
  price INT NOT NULL, currency VARCHAR(8) NOT NULL DEFAULT 'RSD', status VARCHAR(16) NOT NULL DEFAULT 'active',
  PRIMARY KEY (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE orders (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, request_id VARCHAR(64) NOT NULL, reseller_id INT UNSIGNED NOT NULL,
  reseller_email VARCHAR(190) NOT NULL, product_id VARCHAR(64) NOT NULL, buyer_email VARCHAR(190) NOT NULL,
  price_rsd INT NOT NULL, created_at DATETIME NOT NULL,
  reseller_paid TINYINT NOT NULL DEFAULT 0, reseller_paid_at DATETIME NULL, PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE wallet_transactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT, reseller_id INT UNSIGNED NOT NULL, amount_rsd INT NOT NULL,
  type VARCHAR(40) NOT NULL DEFAULT 'ORDER', description VARCHAR(255) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
