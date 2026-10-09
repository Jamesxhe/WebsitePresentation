<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
session_start();

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require __DIR__ . '/db.php';
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $marketView = ($_GET['view'] ?? '') === 'market';

    if ($marketView && $method === 'GET') {
        // Customers can read the public catalog; stock movements remain admin-only.
        $products = $pdo->query('SELECT id, name, sku, category, image, price, quantity, reorder_level AS reorderLevel FROM inventory_products ORDER BY name')->fetchAll();
        foreach ($products as &$product) {
            $product['price'] = (float)$product['price'];
            $product['quantity'] = (int)$product['quantity'];
            $product['reorderLevel'] = (int)$product['reorderLevel'];
        }
        unset($product);
        respond(200, ['products' => $products]);
    }

    if (empty($_SESSION['user']['id'])) respond(401, ['error' => 'Sign in to access inventory.']);
    $roleQuery = $pdo->prepare('SELECT role FROM users WHERE id = ? LIMIT 1');
    $roleQuery->execute([(int)$_SESSION['user']['id']]);
    if ($roleQuery->fetchColumn() !== 'admin') respond(403, ['error' => 'Admin access is required.']);

    if ($method === 'GET') {
        $products = $pdo->query('SELECT id, name, sku, category, image, price, quantity, reorder_level AS reorderLevel FROM inventory_products ORDER BY name')->fetchAll();
        foreach ($products as &$product) {
            $product['price'] = (float)$product['price'];
            $product['quantity'] = (int)$product['quantity'];
            $product['reorderLevel'] = (int)$product['reorderLevel'];
        }
        unset($product);
        $movements = $pdo->query('SELECT id, product_id AS productId, type, quantity, note, movement_date AS date FROM inventory_movements ORDER BY movement_date DESC')->fetchAll();
        foreach ($movements as &$movement) $movement['quantity'] = (int)$movement['quantity'];
        unset($movement);
        $orders = $pdo->query('SELECT id, customer_name AS customerName, customer_email AS customerEmail, delivery_address AS deliveryAddress, total, status, payment_method AS paymentMethod, created_at AS createdAt FROM customer_orders ORDER BY created_at DESC, id DESC')->fetchAll();
        $orderItems = $pdo->query('SELECT order_id AS orderId, product_name AS productName, unit_price AS unitPrice, quantity FROM customer_order_items ORDER BY id')->fetchAll();
        $itemsByOrder = [];
        foreach ($orderItems as $item) {
            $key = (string)$item['orderId'];
            $itemsByOrder[$key][] = ['productName' => $item['productName'], 'unitPrice' => (float)$item['unitPrice'], 'quantity' => (int)$item['quantity']];
        }
        foreach ($orders as &$order) {
            $order['id'] = (int)$order['id'];
            $order['total'] = (float)$order['total'];
            $order['items'] = $itemsByOrder[(string)$order['id']] ?? [];
        }
        unset($order);
        respond(200, ['products' => $products, 'movements' => $movements, 'orders' => $orders]);
    }

    if ($method !== 'PUT' && $method !== 'POST') {
        header('Allow: GET, POST, PUT');
        respond(405, ['error' => 'Method not allowed.']);
    }
    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input) || !isset($input['products'], $input['movements']) || !is_array($input['products']) || !is_array($input['movements'])) {
        respond(400, ['error' => 'Expected products and movements arrays.']);
    }
    foreach ($input['products'] as $product) {
        if (!is_array($product) || !isset($product['id'], $product['name']) || !is_string($product['id']) || !is_string($product['name']) || trim($product['name']) === '') {
            respond(400, ['error' => 'Each product needs a valid id and name.']);
        }
    }

    $pdo->beginTransaction();
    $pdo->exec('DELETE FROM inventory_movements');
    $pdo->exec('DELETE FROM inventory_products');
    $saveProduct = $pdo->prepare('INSERT INTO inventory_products (id, name, sku, category, price, quantity, reorder_level, image) VALUES (:id, :name, :sku, :category, :price, :quantity, :reorder_level, :image)');
    foreach ($input['products'] as $product) {
        $saveProduct->execute([
            ':id' => $product['id'], ':name' => trim($product['name']), ':sku' => (string)($product['sku'] ?? ''),
            ':category' => (string)($product['category'] ?? ''), ':price' => max(0, (float)($product['price'] ?? 0)),
            ':quantity' => max(0, (int)($product['quantity'] ?? 0)), ':reorder_level' => max(0, (int)($product['reorderLevel'] ?? 0)),
            ':image' => (string)($product['image'] ?? '')
        ]);
    }
    $saveMovement = $pdo->prepare('INSERT INTO inventory_movements (id, product_id, type, quantity, note, movement_date) VALUES (:id, :product_id, :type, :quantity, :note, :movement_date)');
    foreach ($input['movements'] as $movement) {
        if (!is_array($movement) || !isset($movement['id'], $movement['productId']) || !in_array($movement['type'] ?? '', ['in', 'out'], true)) continue;
        $saveMovement->execute([
            ':id' => (string)$movement['id'], ':product_id' => (string)$movement['productId'], ':type' => $movement['type'],
            ':quantity' => max(0, (int)($movement['quantity'] ?? 0)), ':note' => (string)($movement['note'] ?? ''),
            ':movement_date' => (string)($movement['date'] ?? date(DATE_ATOM))
        ]);
    }
    $pdo->commit();
    respond(200, ['ok' => true]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Stockroom API error: ' . $error->getMessage());
    respond(500, ['error' => 'Could not connect to or save inventory in MySQL. Check the database name, MySQL credentials, and that PDO MySQL is enabled.']);
}
