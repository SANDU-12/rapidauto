<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../includes/loyalty.php';
requireAdmin();
$pdo = getDB();
$action = $_GET['action'] ?? 'payments';
$method = $_SERVER['REQUEST_METHOD'];

/* ================= PAYMENTS CRUD ================= */
if ($action === 'payments' && $method === 'GET' && isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT p.*, b.booking_code FROM payments p JOIN bookings b ON b.id=p.booking_id WHERE p.id=?");
    $stmt->execute([$_GET['id']]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Payment not found'], 404);
    respond($row);
}

if ($action === 'payments' && $method === 'GET') {
    respond($pdo->query("SELECT p.*, b.booking_code FROM payments p JOIN bookings b ON b.id=p.booking_id ORDER BY p.created_at DESC")->fetchAll());
}


// edit a payment record (e.g. mark refunded, correct amount)
if ($action === 'payments' && $method === 'PUT') {
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing payment id'], 400);
    $d = jsonInput();
    $status = $d['status'] ?? 'pending';
    if (!in_array($status, ['pending','success','failed','refunded'], true)) respond(['error' => 'Invalid status'], 400);
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE payments SET amount=?, total=?, payment_method=?, status=? WHERE id=?")
        ->execute([$d['amount'] ?? 0, $d['total'] ?? 0, $d['paymentMethod'] ?? 'Cash', $status, $id]);
    $pdo->prepare("UPDATE bookings b JOIN payments p ON p.booking_id=b.id SET b.payment_status=? WHERE p.id=?")
        ->execute([$status === 'success' ? 'paid' : 'unpaid', $id]);
    if ($status === 'success') {
        $bookingStmt = $pdo->prepare('SELECT booking_id FROM payments WHERE id=?');
        $bookingStmt->execute([$id]);
        awardBookingLoyaltyPoints($pdo, (int)$bookingStmt->fetchColumn());
    }
    $pdo->commit();
    respond(['ok' => true]);
}

// quick status-only update (e.g. mark refunded from a dropdown)
if ($action === 'payments' && $method === 'PATCH') {
    $id = $_GET['id'] ?? null;
    $d = jsonInput();
    if (!$id || empty($d['status'])) respond(['error' => 'Missing id or status'], 400);
    if (!in_array($d['status'], ['pending','success','failed','refunded'])) respond(['error' => 'Invalid status'], 400);
    $pdo->beginTransaction();
    $pdo->prepare("UPDATE payments SET status=? WHERE id=?")->execute([$d['status'], $id]);
    $pdo->prepare("UPDATE bookings b JOIN payments p ON p.booking_id=b.id SET b.payment_status=? WHERE p.id=?")
        ->execute([$d['status'] === 'success' ? 'paid' : 'unpaid', $id]);
    if ($d['status'] === 'success') {
        $bookingStmt = $pdo->prepare('SELECT booking_id FROM payments WHERE id=?');
        $bookingStmt->execute([$id]);
        awardBookingLoyaltyPoints($pdo, (int)$bookingStmt->fetchColumn());
    }
    $pdo->commit();
    respond(['ok' => true]);
}

if ($action === 'payments' && $method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing payment id'], 400);
    $pdo->beginTransaction();
    $pdo->prepare("SELECT booking_id FROM payments WHERE id=? FOR UPDATE")->execute([$id]);
    $bookingId = $pdo->query("SELECT booking_id FROM payments WHERE id=" . (int)$id)->fetchColumn();
    $pdo->prepare("DELETE FROM payments WHERE id=?")->execute([$id]);
    if ($bookingId) $pdo->prepare("UPDATE bookings SET payment_status='unpaid' WHERE id=?")->execute([$bookingId]);
    $pdo->commit();
    respond(['ok' => true]);
}

respond(['error' => 'Unknown admin action'], 404);

