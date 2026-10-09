<?php
declare(strict_types=1);

// XAMPP defaults. Update these values if your MySQL account differs.
$dbHost = '127.0.0.1';
$dbName = 'pogiproduct';
$dbUser = 'root';
$dbPassword = '';

$pdo = new PDO(
    "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPassword,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pdo->exec("CREATE TABLE IF NOT EXISTS inventory_products (
    id VARCHAR(100) NOT NULL PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    sku VARCHAR(100) NOT NULL DEFAULT '',
    category VARCHAR(150) NOT NULL DEFAULT '',
    price DECIMAL(12,2) NOT NULL DEFAULT 0,
    quantity INT UNSIGNED NOT NULL DEFAULT 0,
    reorder_level INT UNSIGNED NOT NULL DEFAULT 0,
    image LONGTEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS inventory_movements (
    id VARCHAR(100) NOT NULL PRIMARY KEY,
    product_id VARCHAR(100) NOT NULL,
    type ENUM('in','out') NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    note VARCHAR(500) NOT NULL DEFAULT '',
    movement_date VARCHAR(40) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(254) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL DEFAULT '',
    age TINYINT UNSIGNED NULL,
    sex VARCHAR(30) NOT NULL DEFAULT '',
    address VARCHAR(1000) NOT NULL DEFAULT '',
    location VARCHAR(255) NOT NULL DEFAULT '',
    terms_accepted_at DATETIME NULL,
    role ENUM('customer','admin') NOT NULL DEFAULT 'customer',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
foreach (['full_name' => "VARCHAR(150) NOT NULL DEFAULT ''", 'age' => 'TINYINT UNSIGNED NULL', 'sex' => "VARCHAR(30) NOT NULL DEFAULT ''", 'address' => "VARCHAR(1000) NOT NULL DEFAULT ''", 'location' => "VARCHAR(255) NOT NULL DEFAULT ''", 'terms_accepted_at' => 'DATETIME NULL'] as $column => $definition) {
    $columnCheck = $pdo->prepare('SHOW COLUMNS FROM users LIKE ?');
    $columnCheck->execute([$column]);
    if (!$columnCheck->fetch()) $pdo->exec("ALTER TABLE users ADD COLUMN {$column} {$definition}");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS customer_orders (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    customer_name VARCHAR(150) NOT NULL,
    customer_email VARCHAR(254) NOT NULL,
    delivery_address VARCHAR(1000) NOT NULL,
    total DECIMAL(12,2) NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending',
    payment_method VARCHAR(40) NOT NULL DEFAULT 'cash_on_delivery',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_customer_orders_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
$pdo->exec("CREATE TABLE IF NOT EXISTS customer_order_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT UNSIGNED NOT NULL,
    product_id VARCHAR(100) NOT NULL,
    product_name VARCHAR(255) NOT NULL,
    unit_price DECIMAL(12,2) NOT NULL,
    quantity INT UNSIGNED NOT NULL,
    INDEX idx_customer_order_items_order (order_id),
    CONSTRAINT fk_customer_order_items_order FOREIGN KEY (order_id) REFERENCES customer_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
