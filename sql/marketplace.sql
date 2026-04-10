CREATE TABLE IF NOT EXISTS marketplace_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    owner_id INT NOT NULL, account_id INT NOT NULL,
    owner_name VARCHAR(24) NOT NULL,
    item_vnum INT NOT NULL, item_name VARCHAR(64) NOT NULL,
    item_count INT NOT NULL DEFAULT 1,
    socket0 BIGINT DEFAULT 0, socket1 BIGINT DEFAULT 0, socket2 BIGINT DEFAULT 0,
    attrtype0 TINYINT DEFAULT 0, attrvalue0 SMALLINT DEFAULT 0,
    attrtype1 TINYINT DEFAULT 0, attrvalue1 SMALLINT DEFAULT 0,
    attrtype2 TINYINT DEFAULT 0, attrvalue2 SMALLINT DEFAULT 0,
    attrtype3 TINYINT DEFAULT 0, attrvalue3 SMALLINT DEFAULT 0,
    attrtype4 TINYINT DEFAULT 0, attrvalue4 SMALLINT DEFAULT 0,
    attrtype5 TINYINT DEFAULT 0, attrvalue5 SMALLINT DEFAULT 0,
    attrtype6 TINYINT DEFAULT 0, attrvalue6 SMALLINT DEFAULT 0,
    price DECIMAL(10,2) DEFAULT NULL,
    status ENUM('inventory','listed','sold','withdrawn') NOT NULL DEFAULT 'inventory',
    buyer_id INT DEFAULT NULL, promoted_until DATETIME DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    sold_at DATETIME DEFAULT NULL,
    INDEX idx_owner (owner_name, status),
    INDEX idx_status (status, created_at),
    INDEX idx_account (account_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_balance (
    account_id INT PRIMARY KEY,
    balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    total_sales INT NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_id INT NOT NULL, seller_id INT NOT NULL, buyer_id INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    seller_id INT NOT NULL, buyer_id INT NOT NULL,
    buyer_name VARCHAR(24) NOT NULL, rating TINYINT NOT NULL, comment TEXT,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_seller (seller_id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_charges (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL, amount DECIMAL(10,2) NOT NULL,
    method ENUM('stripe','paypal','bank_transfer') NOT NULL,
    status ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
    stripe_session_id VARCHAR(255) DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS marketplace_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    account_id INT NOT NULL, message TEXT NOT NULL,
    is_read TINYINT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_account (account_id, is_read)
) ENGINE=InnoDB;
