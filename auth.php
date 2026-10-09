<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/'
]);
session_start();

function authRespond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    require __DIR__ . '/db.php';
    $action = (string)($_GET['action'] ?? 'me');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    if ($action === 'me' && $method === 'GET') {
        if (empty($_SESSION['user'])) authRespond(200, ['authenticated' => false]);
        authRespond(200, ['authenticated' => true, 'user' => $_SESSION['user']]);
    }

    if ($action === 'account') {
        if (empty($_SESSION['user']['id'])) authRespond(401, ['error' => 'Sign in to view your account.']);
        if (($_SESSION['user']['role'] ?? '') !== 'customer') authRespond(403, ['error' => 'Customer account access is required.']);
        $userId = (int)$_SESSION['user']['id'];
        if ($method === 'GET') {
            $profileQuery = $pdo->prepare('SELECT id, email, full_name AS fullName, age, sex, address, location, terms_accepted_at AS termsAcceptedAt, created_at AS createdAt FROM users WHERE id = ? AND role = \'customer\' LIMIT 1');
            $profileQuery->execute([$userId]);
            $profile = $profileQuery->fetch();
            if (!$profile) authRespond(404, ['error' => 'Customer account not found.']);
            $ordersQuery = $pdo->prepare('SELECT id, customer_name AS customerName, total, status, payment_method AS paymentMethod, created_at AS createdAt FROM customer_orders WHERE user_id = ? ORDER BY created_at DESC, id DESC');
            $ordersQuery->execute([$userId]);
            $orders = $ordersQuery->fetchAll();
            $itemsQuery = $pdo->prepare('SELECT order_id AS orderId, product_name AS productName, unit_price AS unitPrice, quantity FROM customer_order_items WHERE order_id IN (SELECT id FROM customer_orders WHERE user_id = ?) ORDER BY id');
            $itemsQuery->execute([$userId]);
            $itemsByOrder = [];
            foreach ($itemsQuery->fetchAll() as $item) $itemsByOrder[(string)$item['orderId']][] = ['productName' => $item['productName'], 'unitPrice' => (float)$item['unitPrice'], 'quantity' => (int)$item['quantity']];
            foreach ($orders as &$order) {
                $order['id'] = (int)$order['id'];
                $order['total'] = (float)$order['total'];
                $order['items'] = $itemsByOrder[(string)$order['id']] ?? [];
            }
            unset($order);
            authRespond(200, ['profile' => ['accountNumber' => 'MH-' . str_pad((string)$profile['id'], 6, '0', STR_PAD_LEFT), 'email' => $profile['email'], 'fullName' => $profile['fullName'], 'age' => $profile['age'] === null ? null : (int)$profile['age'], 'sex' => $profile['sex'], 'address' => $profile['address'], 'location' => $profile['location'], 'termsAcceptedAt' => $profile['termsAcceptedAt'], 'createdAt' => $profile['createdAt']], 'orders' => $orders]);
        }
        if ($method === 'POST') {
            $input = json_decode(file_get_contents('php://input') ?: '', true);
            if (!is_array($input)) authRespond(400, ['error' => 'Enter valid account information.']);
            $fullName = trim((string)($input['fullName'] ?? ''));
            $age = ($input['age'] ?? '') === '' ? null : filter_var($input['age'], FILTER_VALIDATE_INT);
            $sex = trim((string)($input['sex'] ?? ''));
            $address = trim((string)($input['address'] ?? ''));
            $location = trim((string)($input['location'] ?? ''));
            if (strlen($fullName) > 150 || ($age !== null && ($age === false || $age < 1 || $age > 120)) || strlen($address) > 1000 || strlen($location) > 255 || !in_array($sex, ['', 'Female', 'Male', 'Other', 'Prefer not to say'], true)) authRespond(400, ['error' => 'Check the name, age, sex, address, or location and try again.']);
            $saveProfile = $pdo->prepare('UPDATE users SET full_name = ?, age = ?, sex = ?, address = ?, location = ? WHERE id = ? AND role = \'customer\'');
            $saveProfile->execute([$fullName, $age, $sex, $address, $location, $userId]);
            authRespond(200, ['ok' => true]);
        }
        header('Allow: GET, POST');
        authRespond(405, ['error' => 'Method not allowed.']);
    }

    if ($action === 'logout' && $method === 'POST') {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], '', $params['secure'], $params['httponly']);
        }
        session_destroy();
        authRespond(200, ['ok' => true]);
    }

    if (!in_array($action, ['login', 'admin-login', 'register'], true) || $method !== 'POST') {
        authRespond(405, ['error' => 'Unsupported authentication request.']);
    }
    $input = json_decode(file_get_contents('php://input') ?: '', true);
    $email = strtolower(trim((string)($input['email'] ?? '')));
    $password = (string)($input['password'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        authRespond(400, ['error' => 'Enter a valid email address.']);
    }

    if ($action === 'register') {
        if (strlen($password) < 8) authRespond(400, ['error' => 'Password must be at least 8 characters.']);
        $fullName = trim((string)($input['fullName'] ?? ''));
        $age = filter_var($input['age'] ?? null, FILTER_VALIDATE_INT);
        $sex = trim((string)($input['sex'] ?? ''));
        $address = trim((string)($input['address'] ?? ''));
        $termsAccepted = ($input['termsAccepted'] ?? false) === true;
        if ($fullName === '' || strlen($fullName) > 150 || $age === false || $age < 1 || $age > 120 || !in_array($sex, ['Female', 'Male', 'Other', 'Prefer not to say'], true) || $address === '' || strlen($address) > 1000 || !$termsAccepted) {
            authRespond(400, ['error' => 'Enter your name, valid age, sex, address, and accept the terms to create an account.']);
        }
        $insert = $pdo->prepare("INSERT INTO users (email, password_hash, full_name, age, sex, address, terms_accepted_at, role) VALUES (?, ?, ?, ?, ?, ?, NOW(), 'customer')");
        try {
            $insert->execute([$email, password_hash($password, PASSWORD_DEFAULT), $fullName, $age, $sex, $address]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') authRespond(409, ['error' => 'An account with that email already exists.']);
            throw $error;
        }
        $user = ['id' => (int)$pdo->lastInsertId(), 'email' => $email, 'role' => 'customer'];
    } else {
        $lookup = $pdo->prepare('SELECT id, email, password_hash, role FROM users WHERE email = ? LIMIT 1');
        $lookup->execute([$email]);
        $record = $lookup->fetch();
        if (!$record || !password_verify($password, $record['password_hash'])) {
            authRespond(401, ['error' => 'Email or password is incorrect.']);
        }
        if ($action === 'admin-login' && $record['role'] !== 'admin') {
            authRespond(403, ['error' => 'This account does not have administrator access.']);
        }
        $user = ['id' => (int)$record['id'], 'email' => $record['email'], 'role' => $record['role']];
    }

    session_regenerate_id(true);
    $_SESSION['user'] = $user;
    authRespond(200, ['ok' => true, 'user' => $user]);
} catch (Throwable $error) {
    error_log('Stockroom authentication error: ' . $error->getMessage());
    authRespond(500, ['error' => 'Could not reach the account database. Check MySQL and the database settings.']);
}
