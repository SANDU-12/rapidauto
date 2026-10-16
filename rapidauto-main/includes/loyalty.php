<?php
const LOYALTY_POINTS_PER_100_LKR = 1;
const LOYALTY_POINTS_PER_LKR_DISCOUNT = 1;
const LOYALTY_FREE_WASH_POINTS = 500;

function awardBookingLoyaltyPoints(PDO $pdo, int $bookingId): void {
    $stmt = $pdo->prepare("SELECT customer_id, total_amount FROM bookings
                           WHERE id=? AND status='completed' AND payment_status='paid' FOR UPDATE");
    $stmt->execute([$bookingId]);
    $booking = $stmt->fetch();
    if (!$booking || empty($booking['customer_id'])) return;

    $points = (int)floor((float)$booking['total_amount'] / 100) * LOYALTY_POINTS_PER_100_LKR;
    if ($points < 1) return;

    $ledger = $pdo->prepare("INSERT IGNORE INTO loyalty_transactions
                             (customer_id, booking_id, transaction_type, points, description)
                             VALUES (?, ?, 'earned', ?, ?)");
    $ledger->execute([
        $booking['customer_id'],
        $bookingId,
        $points,
        'Earned from completed booking (1 point per Rs. 100)',
    ]);
    if ($ledger->rowCount() === 1) {
        $pdo->prepare('UPDATE customers SET reward_points=reward_points+? WHERE id=?')
            ->execute([$points, $booking['customer_id']]);
    }
}