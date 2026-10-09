<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
session_set_cookie_params(['httponly' => true, 'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'samesite' => 'Lax', 'path' => '/']);
session_start();

function checkoutRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        header('Allow: POST');
        checkoutRespond(405, ['error' => 'Method not allowed.']);
    }
    if (empty($_SESSION['user']['id'])) checkoutRespond(401, ['error' => 'Sign in with a customer account to place your order.']);

    require __DIR__ . '/db.php';
    $userQuery = $pdo->prepare('SELECT id, email, role FROM users WHERE id = ? LIMIT 1');
    $userQuery->execute([(int)$_SESSION['user']['id']]);
    $user = $userQuery->fetch();
    if (!$user || $user['role'] !== 'customer') checkoutRespond(403, ['error' => 'Checkout is available to customer accounts.']);

    $input = json_decode(file_get_contents('php://input') ?: '', true);
    $name = trim((string)($input['name'] ?? ''));
    $address = trim((string)($input['address'] ?? ''));
    $items = $input['items'] ?? null;
    if ($name === '' || strlen($name) > 150 || $address === '' || strlen($address) > 1000) {
        checkoutRespond(400, ['error' => 'Enter your name and a valid delivery address.']);
    }
    if (!is_array($items) || count($items) < 1 || count($items) > 50) {
        checkoutRespond(400, ['error' => 'Your cart must contain between 1 and 50 products.']);
    }

    $requested = [];
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['id'], $item['quantity']) || !is_string($item['id'])) {
            checkoutRespond(400, ['error' => 'The cart contains an invalid product.']);
        }
        $quantity = filter_var($item['quantity'], FILTER_VALIDATE_INT);
        if ($quantity === false || $quantity < 1 || $quantity > 1000 || isset($requested[$item['id']])) {
            checkoutRespond(400, ['error' => 'Cart quantities must be between 1 and 1000, with each product listed once.']);
        }
        $requested[$item['id']] = $quantity;
    }

    $pdo->beginTransaction();
    $findProduct = $pdo->prepare('SELECT id, name, price, quantity FROM inventory_products WHERE id = ? FOR UPDATE');
    $products = [];
    $totalCents = 0;
    foreach ($requested as $productId => $quantity) {
        $findProduct->execute([$productId]);
        $product = $findProduct->fetch();
        if (!$product) {
            $pdo->rollBack();
            checkoutRespond(409, ['error' => 'A product in your cart is no longer available. Refresh the market and try again.']);
        }
        if ((int)$product['quantity'] < $quantity) {
            $pdo->rollBack();
            checkoutRespond(409, ['error' => 'There is not enough stock for ' . $product['name'] . '. Refresh the market and adjust your cart.']);
        }
        $product['price_cents'] = (int)round((float)$product['price'] * 100);
        $product['order_quantity'] = $quantity;
        $totalCents += $product['price_cents'] * $quantity;
        $products[] = $product;
    }

    $createOrder = $pdo->prepare("INSERT INTO customer_orders (user_id, customer_name, customer_email, delivery_address, total, status, payment_method) VALUES (?, ?, ?, ?, ?, 'pending', 'cash_on_delivery')");
    $createOrder->execute([(int)$user['id'], $name, $user['email'], $address, number_format($totalCents / 100, 2, '.', '')]);
    $orderId = (int)$pdo->lastInsertId();
    $addItem = $pdo->prepare('INSERT INTO customer_order_items (order_id, product_id, product_name, unit_price, quantity) VALUES (?, ?, ?, ?, ?)');
    $reduceStock = $pdo->prepare('UPDATE inventory_products SET quantity = quantity - ? WHERE id = ? AND quantity >= ?');
    $addMovement = $pdo->prepare('INSERT INTO inventory_movements (id, product_id, type, quantity, note, movement_date) VALUES (?, ?, \'out\', ?, ?, ?)');
    foreach ($products as $index => $product) {
        $quantity = (int)$product['order_quantity'];
        $addItem->execute([$orderId, $product['id'], $product['name'], number_format($product['price_cents'] / 100, 2, '.', ''), $quantity]);
        $reduceStock->execute([$quantity, $product['id'], $quantity]);
        if ($reduceStock->rowCount() !== 1) throw new RuntimeException('Stock changed during checkout.');
        $addMovement->execute(['order-' . $orderId . '-' . ($index + 1), $product['id'], $quantity, 'Customer order #' . $orderId, date(DATE_ATOM)]);
    }
    $pdo->commit();
    checkoutRespond(201, ['ok' => true, 'orderId' => $orderId, 'total' => number_format($totalCents / 100, 2, '.', '')]);
} catch (Throwable $error) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    error_log('Stockroom checkout error: ' . $error->getMessage());
    checkoutRespond(500, ['error' => 'Could not place the order. Please try again.']);
}
