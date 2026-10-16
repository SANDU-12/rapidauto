<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
requireAdmin();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// ---- List customer records, with booking totals ----
if ($method === 'GET' && $action === '') {
    $rows = $pdo->query("SELECT c.id, c.user_id, c.first_name, c.last_name,
                          CONCAT_WS(' ', c.first_name, c.last_name) AS name, c.phone, c.email, c.address,
                          COUNT(b.id) AS booking_count, COALESCE(SUM(b.total_amount),0) AS total_spent,
                          MAX(b.created_at) AS last_booking
                          FROM customers c
                          LEFT JOIN bookings b ON b.guest_phone=c.phone
                          GROUP BY c.id, c.user_id, c.first_name, c.last_name, c.phone, c.email, c.address
                          ORDER BY c.created_at DESC")->fetchAll();
    respond($rows);
}

// ---- View one customer's full detail + booking history ----
if ($method === 'GET' && $action === 'detail') {
    $phone = $_GET['phone'] ?? '';
    if (!$phone) respond(['error' => 'Missing phone'], 400);
    $stmt = $pdo->prepare("SELECT c.id AS customer_id, c.first_name, c.last_name, c.email, c.phone, c.address
                            FROM customers c WHERE c.phone=?");
    $stmt->execute([$phone]);
    $customer = $stmt->fetch();
    if (!$customer) respond(['error' => 'No customer found with that phone'], 404);
    $bookingStmt = $pdo->prepare("SELECT id, booking_code, guest_name, guest_email, booking_date, time_slot, status, total_amount, created_at
                                  FROM bookings WHERE guest_phone = ? ORDER BY created_at DESC");
    $bookingStmt->execute([$phone]);
    $bookings = $bookingStmt->fetchAll();
    respond([
        'phone' => $customer['phone'], 'name' => trim($customer['first_name'].' '.$customer['last_name']), 'email' => $customer['email'],
        'address' => $customer['address'],
        'bookingCount' => count($bookings),
        'totalSpent' => array_sum(array_column($bookings, 'total_amount')),
        'bookings' => $bookings,
    ]);
}

// ---- Edit a customer's contact info across all their bookings ----
if ($method === 'PUT' && $action === '') {
    $oldPhone = $_GET['phone'] ?? '';
    if (!$oldPhone) respond(['error' => 'Missing phone'], 400);
    $d = jsonInput();
    $nameParts = explode(' ', trim($d['name'] ?? ''), 2);
    $stmt = $pdo->prepare("SELECT c.id, c.user_id FROM customers c WHERE c.phone=?");
    $stmt->execute([$oldPhone]);
    $customer = $stmt->fetch();
    if (!$customer) respond(['error' => 'Customer not found'], 404);
    // Mirror contact edits to the login identity and legacy guest-booking records.
    $pdo->prepare("UPDATE customers SET first_name=?, last_name=?, email=?, phone=?, address=? WHERE id=?")
        ->execute([$nameParts[0] ?? '', $nameParts[1] ?? '', $d['email'] ?? null, $d['phone'] ?? $oldPhone, $d['address'] ?? null, $customer['id']]);
    $pdo->prepare("UPDATE users SET first_name=?, last_name=?, email=?, phone=? WHERE id=?")
        ->execute([$nameParts[0] ?? '', $nameParts[1] ?? '', $d['email'] ?? null, $d['phone'] ?? $oldPhone, $customer['user_id']]);
    $pdo->prepare("UPDATE bookings SET guest_name=?, guest_email=?, guest_phone=? WHERE guest_phone=?")
        ->execute([$d['name'] ?? null, $d['email'] ?? null, $d['phone'] ?? $oldPhone, $oldPhone]);
    respond(['ok' => true]);
}

// ---- Delete a customer (their booking records) ----
if ($method === 'DELETE' && $action === '') {
    $phone = $_GET['phone'] ?? '';
    if (!$phone) respond(['error' => 'Missing phone'], 400);
    $stmt = $pdo->prepare("SELECT c.id AS customer_id, c.user_id FROM customers c WHERE c.phone=?");
    $stmt->execute([$phone]);
    $customer = $stmt->fetch();
    if (!$customer) respond(['error' => 'Customer not found'], 404);
    $pdo->beginTransaction();
    try {
        // Remove guest-linked bookings, then delete the user so the customer profile cascades.
        $pdo->prepare("DELETE FROM bookings WHERE guest_phone=?")->execute([$phone]);
        $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$customer['user_id']]);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        respond(['error' => 'Could not delete customer'], 500);
    }
    respond(['ok' => true]);
}

respond(['error' => 'Unknown action'], 404);
