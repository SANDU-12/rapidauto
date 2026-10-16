<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/payhere.php';
require_once __DIR__ . '/../includes/loyalty.php';
require_once __DIR__ . '/../includes/email.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !payhereVerifyNotification($_POST)) {
    respond(['error' => 'Invalid PayHere notification'], 400);
}

$orderCode = trim((string)($_POST['order_id'] ?? ''));
$statusCode = (string)($_POST['status_code'] ?? '');
$paidAmount = $_POST['payhere_amount'] ?? '';
$currency = strtoupper((string)($_POST['payhere_currency'] ?? ''));
if (!is_numeric($paidAmount) || $currency !== PAYHERE_CURRENCY) {
    respond(['error' => 'Invalid payment amount or currency'], 400);
}

$pdo = getDB();
$confirmedOrderId = null;
$paidBookingId = null;
$pdo->beginTransaction();
try {
    if (strpos($orderCode, 'ORD-') === 0) {
        $stmt = $pdo->prepare("SELECT id, total, status FROM orders WHERE order_code=? FOR UPDATE");
        $stmt->execute([$orderCode]);
        $order = $stmt->fetch();
        if (!$order || number_format((float)$order['total'], 2, '.', '') !== number_format((float)$paidAmount, 2, '.', '')) {
            throw new RuntimeException('Order not found or amount mismatch');
        }
        if ($statusCode === '2' && $order['status'] === 'pending') {
            $pdo->prepare("UPDATE orders SET status='confirmed' WHERE id=?")->execute([$order['id']]);
            $confirmedOrderId = (int)$order['id'];
            $items = $pdo->prepare("SELECT product_id, qty FROM order_items WHERE order_id=?");
            $items->execute([$order['id']]);
            foreach ($items->fetchAll() as $item) {
                $pdo->prepare("UPDATE products SET stock=GREATEST(0, stock-?) WHERE id=?")
                    ->execute([$item['qty'], $item['product_id']]);
            }
        } elseif (in_array($statusCode, ['-1','-2'], true) && $order['status'] === 'pending') {
            $pdo->prepare("UPDATE orders SET status='cancelled' WHERE id=?")->execute([$order['id']]);
        }
    } elseif (strpos($orderCode, 'RA-') === 0) {
        $stmt = $pdo->prepare("SELECT id, total_amount, payment_status FROM bookings WHERE booking_code=? FOR UPDATE");
        $stmt->execute([$orderCode]);
        $booking = $stmt->fetch();
        if (!$booking || number_format((float)$booking['total_amount'], 2, '.', '') !== number_format((float)$paidAmount, 2, '.', '')) {
            throw new RuntimeException('Booking not found or amount mismatch');
        }
        $paymentStmt = $pdo->prepare("SELECT id, status FROM payments WHERE booking_id=? AND payment_method='Card' ORDER BY id DESC LIMIT 1 FOR UPDATE");
        $paymentStmt->execute([$booking['id']]);
        $payment = $paymentStmt->fetch();
        if (!$payment) throw new RuntimeException('Card payment record not found');

        if ($statusCode === '2' && $payment['status'] === 'pending') {
            $pdo->prepare("UPDATE payments SET status='success' WHERE id=?")->execute([$payment['id']]);
            $pdo->prepare("UPDATE bookings SET payment_status='paid' WHERE id=?")->execute([$booking['id']]);
            awardBookingLoyaltyPoints($pdo, (int)$booking['id']);
            $paidBookingId = (int)$booking['id'];
        } elseif (in_array($statusCode, ['-1','-2'], true) && $payment['status'] === 'pending') {
            $pdo->prepare("UPDATE payments SET status='failed' WHERE id=?")->execute([$payment['id']]);
        }
    } else {
        throw new RuntimeException('Unknown PayHere order reference');
    }

    $pdo->commit();
} catch (Throwable $error) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('PayHere notification failed: ' . $error->getMessage());
    respond(['error' => 'Payment notification could not be processed'], 400);
}

if ($confirmedOrderId !== null) sendOrderConfirmationEmail($pdo, $confirmedOrderId);
if ($paidBookingId !== null) sendBookingConfirmationEmail($pdo, $paidBookingId);
respond(['received' => true]);