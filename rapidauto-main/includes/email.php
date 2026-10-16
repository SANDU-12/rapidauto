<?php
use PHPMailer\PHPMailer\PHPMailer;

// SMTP settings: environment variables first, then config/mail.local.php.
function mailSetting(string $env, string $key, $default = '') {
    static $local = null;
    if ($local === null) {
        $file = __DIR__ . '/../config/mail.local.php';
        $local = is_file($file) ? (require $file) : [];
    }
    $v = getenv($env);
    if ($v !== false && trim($v) !== '') return trim($v);
    $v = $local[$key] ?? '';
    return ($v === '' || $v === null) ? $default : trim((string)$v);
}

function sendRapidAutoEmail(string $recipient, string $subject, string $htmlBody, string $textBody): bool {
    $autoload = __DIR__ . '/../vendor/autoload.php';
    if (!is_file($autoload)) {
        error_log('Rapid Auto email not sent: run "composer install" in the project folder.');
        return false;
    }
    require_once $autoload;

    $host = mailSetting('SMTP_HOST', 'host');
    $username = mailSetting('SMTP_USERNAME', 'username');
    $password = (string)(getenv('SMTP_PASSWORD') ?: (mailSetting('SMTP_PASSWORD', 'password')));
    if ($host === '' || $username === '' || $password === '') {
        error_log('Rapid Auto email not sent: SMTP host, username and password are not configured.');
        return false;
    }

    try {
        $encryption = strtolower(mailSetting('SMTP_ENCRYPTION', 'encryption', 'tls'));
        if (!in_array($encryption, ['tls', 'ssl', 'none'], true)) {
            throw new RuntimeException('SMTP encryption must be tls, ssl, or none.');
        }
        $defaultPort = $encryption === 'ssl' ? 465 : 587;

        $mailer = new PHPMailer(true);
        $mailer->CharSet = 'UTF-8';
        $mailer->Timeout = 10;
        $mailer->isSMTP();
        $mailer->Host = $host;
        $mailer->SMTPAuth = true;
        $mailer->Username = $username;
        $mailer->Password = $password;
        $mailer->Port = (int)mailSetting('SMTP_PORT', 'port', $defaultPort);
        if ($encryption === 'ssl') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif ($encryption === 'tls') {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mailer->SMTPSecure = '';
            $mailer->SMTPAutoTLS = false;
        }

        $fromEmail = mailSetting('MAIL_FROM_EMAIL', 'from_email', $username);
        $fromName = mailSetting('MAIL_FROM_NAME', 'from_name', 'Rapid Auto Service Center');
        $mailer->setFrom($fromEmail, $fromName);
        $mailer->addAddress($recipient);
        $mailer->isHTML(true);
        $mailer->Subject = $subject;
        $mailer->Body = $htmlBody;
        $mailer->AltBody = $textBody;
        $mailer->send();
        return true;
    } catch (Throwable $error) {
        error_log('Rapid Auto email delivery failed: ' . $error->getMessage());
        return false;
    }
}

// Claim the "confirmation sent" flag so PayHere retries don't send duplicates.
// Needs email_confirmation_migration.sql; if the column is missing, sending still works (just without de-duplication).
function claimConfirmationEmail(PDO $pdo, string $table, int $id): bool {
    try {
        $stmt = $pdo->prepare("UPDATE `$table` SET confirmation_email_sent_at=NOW() WHERE id=? AND confirmation_email_sent_at IS NULL");
        $stmt->execute([$id]);
        return $stmt->rowCount() === 1;
    } catch (Throwable $e) {
        return true;
    }
}
function releaseConfirmationEmail(PDO $pdo, string $table, int $id): void {
    try {
        $pdo->prepare("UPDATE `$table` SET confirmation_email_sent_at=NULL WHERE id=?")->execute([$id]);
    } catch (Throwable $e) { /* column not installed */ }
}

function sendBookingConfirmationEmail(PDO $pdo, int $bookingId): void {
    try {
        $stmt = $pdo->prepare("SELECT booking_code, guest_name, guest_email, booking_date, time_slot, total_amount, payment_status FROM bookings WHERE id=?");
        $stmt->execute([$bookingId]);
        $booking = $stmt->fetch();
        if (!$booking || !filter_var($booking['guest_email'] ?? '', FILTER_VALIDATE_EMAIL)) return;
        if (!claimConfirmationEmail($pdo, 'bookings', $bookingId)) return;

        $serviceStmt = $pdo->prepare("SELECT s.service_name, bs.price_at_booking FROM booking_services bs JOIN services s ON s.id=bs.service_id WHERE bs.booking_id=?");
        $serviceStmt->execute([$bookingId]);
        $services = $serviceStmt->fetchAll();
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $serviceRows = '';
        $serviceText = [];
        foreach ($services as $service) {
            $price = number_format((float)$service['price_at_booking'], 2);
            $serviceRows .= '<li>' . $escape($service['service_name']) . ' - Rs. ' . $price . '</li>';
            $serviceText[] = $service['service_name'] . ' - Rs. ' . $price;
        }

        $code = $escape($booking['booking_code']);
        $name = $escape($booking['guest_name'] ?: 'Customer');
        $date = $escape($booking['booking_date']);
        $slot = $escape($booking['time_slot']);
        $total = number_format((float)$booking['total_amount'], 2);
        $payment = $escape(ucfirst((string)$booking['payment_status']));
        $html = '<h2>Service booking confirmed</h2><p>Hello ' . $name . ',</p><p>Your service booking <strong>' . $code . '</strong> is confirmed.</p><p><strong>Date:</strong> ' . $date . '<br><strong>Time:</strong> ' . $slot . '<br><strong>Payment:</strong> ' . $payment . '</p><p><strong>Services</strong></p><ul>' . $serviceRows . '</ul><p><strong>Total: Rs. ' . $total . '</strong></p><p>Thank you for choosing Rapid Auto Service Center.</p>';
        $text = "Service booking confirmed\nHello " . ($booking['guest_name'] ?: 'Customer') . ",\nBooking: " . $booking['booking_code'] . "\nDate: " . $booking['booking_date'] . ' ' . $booking['time_slot'] . "\nPayment: " . ucfirst((string)$booking['payment_status']) . "\nServices:\n- " . implode("\n- ", $serviceText) . "\nTotal: Rs. " . $total . "\nThank you for choosing Rapid Auto Service Center.";
        if (!sendRapidAutoEmail($booking['guest_email'], 'Service booking confirmed: ' . $booking['booking_code'], $html, $text)) {
            releaseConfirmationEmail($pdo, 'bookings', $bookingId);
        }
    } catch (Throwable $error) {
        error_log('Rapid Auto booking confirmation failed: ' . $error->getMessage());
    }
}

function sendOrderConfirmationEmail(PDO $pdo, int $orderId): void {
    try {
        $stmt = $pdo->prepare("SELECT order_code, customer_name, email, total, status FROM orders WHERE id=?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        if (!$order || !filter_var($order['email'] ?? '', FILTER_VALIDATE_EMAIL)) return;
        if (!claimConfirmationEmail($pdo, 'orders', $orderId)) return;

        $itemStmt = $pdo->prepare("SELECT p.name, oi.qty, oi.price_at_order FROM order_items oi LEFT JOIN products p ON p.id=oi.product_id WHERE oi.order_id=?");
        $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll();
        $escape = static fn($value): string => htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $itemRows = '';
        $itemText = [];
        foreach ($items as $item) {
            $name = $item['name'] ?: 'Product';
            $lineTotal = number_format((float)$item['qty'] * (float)$item['price_at_order'], 2);
            $itemRows .= '<li>' . $escape($name) . ' x ' . (int)$item['qty'] . ' - Rs. ' . $lineTotal . '</li>';
            $itemText[] = $name . ' x ' . (int)$item['qty'] . ' - Rs. ' . $lineTotal;
        }

        $code = $escape($order['order_code']);
        $name = $escape($order['customer_name']);
        $status = $escape(ucfirst((string)$order['status']));
        $total = number_format((float)$order['total'], 2);
        $html = '<h2>Order confirmed</h2><p>Hello ' . $name . ',</p><p>Your order <strong>' . $code . '</strong> is confirmed.</p><p><strong>Status:</strong> ' . $status . '</p><p><strong>Items</strong></p><ul>' . $itemRows . '</ul><p><strong>Total: Rs. ' . $total . '</strong></p><p>Thank you for shopping with Rapid Auto Service Center.</p>';
        $text = "Order confirmed\nHello " . $order['customer_name'] . ",\nOrder: " . $order['order_code'] . "\nStatus: " . ucfirst((string)$order['status']) . "\nItems:\n- " . implode("\n- ", $itemText) . "\nTotal: Rs. " . $total . "\nThank you for shopping with Rapid Auto Service Center.";
        if (!sendRapidAutoEmail($order['email'], 'Order confirmed: ' . $order['order_code'], $html, $text)) {
            releaseConfirmationEmail($pdo, 'orders', $orderId);
        }
    } catch (Throwable $error) {
        error_log('Rapid Auto order confirmation failed: ' . $error->getMessage());
    }
}
