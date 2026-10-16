<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../config/payhere.php';
require_once __DIR__ . '/../includes/email.php';
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ---- Place order (checkout) ----
if ($method === 'POST' && $action === '') {
    $d = jsonInput();
    if (!is_array($d['items'] ?? null) || !$d['items']) respond(['error' => 'Cart is empty'], 400);
    $name = trim((string)($d['name'] ?? ''));
    $phone = trim((string)($d['phone'] ?? ''));
    $email = trim((string)($d['email'] ?? ''));
    $address = trim((string)($d['address'] ?? ''));
    $city = trim((string)($d['city'] ?? ''));
    $paymentMethod = $d['paymentMethod'] ?? '';
    if ($name === '') respond(['error' => 'Your full name is required.'], 400);
    if (!preg_match('/^[0-9]{10}$/D', $phone)) respond(['error' => 'Phone number must contain exactly 10 digits.'], 400);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) respond(['error' => 'A valid email address is required for your order confirmation.'], 400);
    if ($address === '') respond(['error' => 'A delivery or pickup address is required.'], 400);
    if ($city === '') respond(['error' => 'City is required.'], 400);
    if (!in_array($paymentMethod, ['Card','PayPal','Cash'], true)) respond(['error' => 'Choose a valid payment method.'], 400);
    $items = [];
    foreach ($d['items'] as $item) {
        if (!is_array($item)) respond(['error' => 'Invalid spare part in cart.'], 400);
        $productId = filter_var($item['id'] ?? null, FILTER_VALIDATE_INT);
        $quantity = filter_var($item['qty'] ?? null, FILTER_VALIDATE_INT);
        if ($productId === false || $productId < 1) respond(['error' => 'Invalid spare part in cart.'], 400);
        if ($quantity === false || $quantity < 1) respond(['error' => 'Each spare part quantity must be a whole number greater than zero.'], 400);
        $items[] = ['id' => $productId, 'qty' => $quantity];
    }
    $paypalEmail = trim((string)($d['paypalEmail'] ?? ''));
    if ($paymentMethod === 'PayPal' && !filter_var($paypalEmail, FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'A valid PayPal email address is required.'], 400);
    }
    $d['name'] = $name;
    $d['phone'] = $phone;
    $d['email'] = $email;
    $d['address'] = $address;
    $d['city'] = $city;
    $d['paymentMethod'] = $paymentMethod;

    $pdo->beginTransaction();
    try {
        $subtotal = 0;
        $itemPrices = [];
        foreach ($items as $it) {
            $stmt = $pdo->prepare("SELECT price FROM products WHERE id = ?");
            $stmt->execute([$it['id']]);
            $row = $stmt->fetch();
            if (!$row) throw new Exception('Product not found: ' . $it['id']);
            $itemPrices[$it['id']] = $row['price'];
            $subtotal += $row['price'] * $it['qty'];
        }
        $delivery = 350;
        $total = $subtotal + $delivery;
        if ($total <= 0) throw new Exception('Order total must be greater than zero');

        $count = $pdo->query("SELECT COUNT(*) c FROM orders")->fetch()['c'];
        $code = 'ORD-' . (1000 + $count + 1);

        $isPayHere = $paymentMethod === 'Card';
        if ($isPayHere) {
            $pdo->prepare("INSERT INTO orders (order_code,customer_name,phone,email,address,subtotal,delivery_fee,total,payment_method,status)
                           VALUES (?,?,?,?,?,?,?,?,?,'pending')")
                ->execute([$code, $d['name'], $d['phone'], $d['email'] ?? null, $d['address'] ?? null, $subtotal, $delivery, $total, 'Card']);
            $orderId = $pdo->lastInsertId();
            foreach ($items as $it) {
                $pdo->prepare("INSERT INTO order_items (order_id,product_id,qty,price_at_order) VALUES (?,?,?,?)")
                    ->execute([$orderId, $it['id'], $it['qty'], $itemPrices[$it['id']]]);
            }
            $checkout = payhereCheckoutData(
                $code, (float)$total, $d, 'Rapid Auto order ' . $code,
                PAYHERE_APP_URL . '/checkout.html?payment=return&order=' . urlencode($code),
                PAYHERE_APP_URL . '/checkout.html?payment=cancelled&order=' . urlencode($code)
            );
            $pdo->commit();
            respond(['payhereCheckout' => $checkout, 'orderCode' => $code, 'total' => $total], 201);
        }

        $pdo->prepare("INSERT INTO orders (order_code,customer_name,phone,email,address,subtotal,delivery_fee,total,payment_method,paypal_email,card_last4,status)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?, 'confirmed')")
            ->execute([$code, $d['name'], $d['phone'], $d['email'] ?? null, $d['address'] ?? null,
                       $subtotal, $delivery, $total, $d['paymentMethod'], $d['paypalEmail'] ?? null, $d['cardLast4'] ?? null]);
        $orderId = $pdo->lastInsertId();

        foreach ($items as $it) {
            $pdo->prepare("INSERT INTO order_items (order_id,product_id,qty,price_at_order) VALUES (?,?,?,?)")
                ->execute([$orderId, $it['id'], $it['qty'], $itemPrices[$it['id']]]);
            $pdo->prepare("UPDATE products SET stock = GREATEST(0, stock - ?) WHERE id = ?")->execute([$it['qty'], $it['id']]);
        }

        $pdo->commit();
    sendOrderConfirmationEmail($pdo, (int)$orderId);
        respond(['orderCode' => $code, 'total' => $total], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        respond(['error' => $e->getMessage()], 500);
    }
}

// ---- Admin: list all orders ----
if ($method === 'GET' && $action === '') {
    requireAdmin();
    $orders = $pdo->query("SELECT * FROM orders ORDER BY created_at DESC")->fetchAll();
    foreach ($orders as &$o) {
        $stmt = $pdo->prepare("SELECT oi.*, p.name FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?");
        $stmt->execute([$o['id']]);
        $o['items'] = $stmt->fetchAll();
    }
    respond($orders);
}

// ---- Public order lookup ----
if ($method === 'GET' && $action === 'lookup') {
    $code = $_GET['code'] ?? '';
    $stmt = $pdo->prepare("SELECT * FROM orders WHERE order_code = ?");
    $stmt->execute([$code]);
    $order = $stmt->fetch();
    if (!$order) respond(['error' => 'Order not found'], 404);
    $stmt = $pdo->prepare("SELECT oi.*, p.name FROM order_items oi JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll();
    respond($order);
}

respond(['error' => 'Unknown action'], 404);
