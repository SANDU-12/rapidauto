<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
require_once __DIR__ . '/../config/payhere.php';
require_once __DIR__ . '/../includes/loyalty.php';
require_once __DIR__ . '/../includes/email.php';
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

$STAGES = ['Booking Confirmed','Vehicle Received','Inspection Started','Washing','Interior Cleaning','Vacuum','Wax','Quality Check','Payment Pending','Completed'];

function recordCompletedBookingService(PDO $pdo, int $bookingId): void {
    $bookingStmt = $pdo->prepare("SELECT b.vehicle_id, b.booking_date,
                                         GROUP_CONCAT(DISTINCT s.service_name ORDER BY s.service_name SEPARATOR ', ') AS service_summary
                                  FROM bookings b
                                  LEFT JOIN booking_services bs ON bs.booking_id=b.id
                                  LEFT JOIN services s ON s.id=bs.service_id
                                  WHERE b.id=? GROUP BY b.id, b.vehicle_id, b.booking_date");
    $bookingStmt->execute([$bookingId]);
    $booking = $bookingStmt->fetch();
    if (!$booking) return;
    if (!empty($booking['vehicle_id'])) {
        $existingStmt = $pdo->prepare("SELECT id FROM vehicle_service_records WHERE booking_id=? LIMIT 1");
        $existingStmt->execute([$bookingId]);
        if (!$existingStmt->fetchColumn()) {
            $summary = trim($booking['service_summary'] ?? '') ?: 'Completed booking service';
            $pdo->prepare("INSERT INTO vehicle_service_records (vehicle_id,booking_id,service_date,service_summary) VALUES (?,?,?,?)")
                ->execute([$booking['vehicle_id'], $bookingId, $booking['booking_date'], substr($summary, 0, 255)]);
        }
    }
    awardBookingLoyaltyPoints($pdo, $bookingId);
}

// ---- Create booking (guest or logged-in customer) ----
if ($method === 'POST' && $action === '') {
    $d = jsonInput();
    if (!is_array($d['services'] ?? null) || !$d['services']) respond(['error' => 'At least one service is required'], 400);
    if (empty($d['date']) || empty($d['slot'])) respond(['error' => 'Date and time slot are required'], 400);
    $availableSlots = ['09:00 AM','10:00 AM','11:00 AM','12:30 PM','02:00 PM','03:30 PM','05:00 PM','06:00 PM'];
    if (!in_array($d['slot'], $availableSlots, true)) respond(['error' => 'Choose a valid appointment time'], 400);
    $bookingTimezone = new DateTimeZone('Asia/Colombo');
    $bookingDateTimeText = $d['date'] . ' ' . $d['slot'];
    $bookingDateTime = DateTimeImmutable::createFromFormat('!Y-m-d h:i A', $bookingDateTimeText, $bookingTimezone);
    $dateParseErrors = DateTimeImmutable::getLastErrors();
    if (!$bookingDateTime || $bookingDateTime->format('Y-m-d h:i A') !== $bookingDateTimeText
        || ($dateParseErrors !== false && ($dateParseErrors['warning_count'] > 0 || $dateParseErrors['error_count'] > 0))) {
        respond(['error' => 'Choose a valid appointment date and time'], 400);
    }
    if ($bookingDateTime <= new DateTimeImmutable('now', $bookingTimezone)) {
        respond(['error' => 'That time slot has passed. Choose an available time.'], 400);
    }
    $guest = $d['guestInfo'] ?? [];
    if (!is_array($guest)) respond(['error' => 'Booking contact details are required.'], 400);
    $guest['name'] = trim((string)($guest['name'] ?? ''));
    $guest['phone'] = trim((string)($guest['phone'] ?? ''));
    $guest['email'] = trim((string)($guest['email'] ?? ''));
    if ($guest['name'] === '') respond(['error' => 'Your full name is required.'], 400);
    if (!preg_match('/^[0-9]{10}$/D', $guest['phone'])) respond(['error' => 'Phone number must contain exactly 10 digits.'], 400);
    if (!filter_var($guest['email'], FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'A valid email address is required for your booking confirmation.'], 400);
    }
    $vehicle = $d['vehicle'] ?? [];
    if (!is_array($vehicle)) respond(['error' => 'Vehicle details are required.'], 400);
    $vehicleType = trim((string)($vehicle['vehicleType'] ?? ''));
    $vehicleBrand = trim((string)($vehicle['brand'] ?? ''));
    $vehicleRegistration = trim((string)($vehicle['registrationNumber'] ?? ''));
    $plate = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $vehicleRegistration));
    $validVehicleTypes = ['Motorcycle','Car','Jeep/Vessel','Three Wheeler','Van (Small)','Van (Large)','Lorry'];
    if (!in_array($vehicleType, $validVehicleTypes, true) || $vehicleBrand === '' || $plate === '') {
        respond(['error' => 'Vehicle type, brand or model, and registration number are required.'], 400);
    }
    $paymentMethod = $d['paymentMethod'] ?? '';
    if (!in_array($paymentMethod, ['Cash','Card','PayPal'], true)) {
        respond(['error' => 'Choose a valid payment method.'], 400);
    }
    if ($paymentMethod === 'Card') {
        $guest['address'] = trim((string)($guest['address'] ?? ''));
        $guest['city'] = trim((string)($guest['city'] ?? ''));
        if ($guest['address'] === '' || $guest['city'] === '') {
            respond(['error' => 'PayHere card checkout requires your address and city.'], 400);
        }
    }
    if ($paymentMethod === 'PayPal' && !filter_var(trim((string)($d['paypalEmail'] ?? '')), FILTER_VALIDATE_EMAIL)) {
        respond(['error' => 'A valid PayPal email address is required.'], 400);
    }

    $pdo->beginTransaction();
    try {
        $customerId = null;
        $bookingToken = getBearerToken();
        $bookingUser = $bookingToken ? jwt_decode($bookingToken) : null;
        if (($bookingUser['role'] ?? '') === 'customer') {
            $customerStmt = $pdo->prepare("SELECT id FROM customers WHERE user_id=? LIMIT 1");
            $customerStmt->execute([$bookingUser['id']]);
            $customerId = $customerStmt->fetchColumn() ?: null;
        }

        $vehicleId = null;
        if ($plate !== '') {
            try {
                $existing = $pdo->prepare("SELECT id FROM vehicles WHERE registration_number=? AND active=1");
                $existing->execute([$plate]);
            } catch (PDOException $schemaError) {
                if ((int)($schemaError->errorInfo[1] ?? 0) !== 1054) throw $schemaError;
                // Legacy installations do not have the soft-delete column yet.
                $existing = $pdo->prepare("SELECT id FROM vehicles WHERE registration_number=?");
                $existing->execute([$plate]);
            }
            $vehicleId = $existing->fetchColumn();
        }
        if (!$vehicleId) {
            $pdo->prepare("INSERT INTO vehicles (customer_id,vehicle_type,brand,model,registration_number) VALUES (?,?,?,?,?)")
                ->execute([$customerId, $vehicleType, $vehicleBrand, $vehicle['model'] ?? null, $plate]);
            $vehicleId = $pdo->lastInsertId();
        }

        // generate booking code RA-1001, RA-1002...
        $count = $pdo->query("SELECT COUNT(*) c FROM bookings")->fetch()['c'];
        $code = 'RA-' . (1000 + $count + 1);

        $totalAmount = 0;
        $selectedServices = [];
        foreach ($d['services'] as $svc) {
            if (!is_numeric($svc)) throw new Exception('Invalid service selected');
            $serviceStmt = $pdo->prepare("SELECT service_name, price FROM services WHERE id=? AND status='active'");
            $serviceStmt->execute([(int)$svc]);
            $service = $serviceStmt->fetch();
            if (!$service) throw new Exception('Service not found: ' . $svc);
            $servicePrice = (float)$service['price'];
            $totalAmount += $servicePrice;
            $selectedServices[] = ['name' => $service['service_name'], 'price' => $servicePrice];
        }
        if ($totalAmount <= 0) throw new Exception('Booking total must be greater than zero');
        $pointsUsed = 0;
        $discountAmount = 0.0;
        $reward = $d['loyaltyReward'] ?? null;
        if ($reward !== null && $reward !== '') {
            if (!$customerId || !is_array($reward)) throw new InvalidArgumentException('Sign in as a customer to redeem loyalty points.');
            $balanceStmt = $pdo->prepare('SELECT reward_points FROM customers WHERE id=? FOR UPDATE');
            $balanceStmt->execute([$customerId]);
            $balance = (int)$balanceStmt->fetchColumn();
            if (($reward['type'] ?? '') === 'discount') {
                $pointsUsed = filter_var($reward['points'] ?? null, FILTER_VALIDATE_INT);
                if ($pointsUsed === false || $pointsUsed < 100 || $pointsUsed % 100 !== 0) throw new InvalidArgumentException('Choose a discount in 100-point increments.');
                if ($pointsUsed > $balance) throw new InvalidArgumentException('You do not have enough loyalty points for this discount.');
                $discountAmount = $pointsUsed / LOYALTY_POINTS_PER_LKR_DISCOUNT;
                if ($discountAmount > $totalAmount) throw new InvalidArgumentException('The selected reward exceeds the booking total.');
            } elseif (($reward['type'] ?? '') === 'free_wash') {
                $pointsUsed = LOYALTY_FREE_WASH_POINTS;
                if ($balance < $pointsUsed) throw new InvalidArgumentException('You do not have enough points for a free wash.');
                $washPrices = array_column(array_filter($selectedServices, static fn($service) => stripos($service['name'], 'wash') !== false), 'price');
                if (!$washPrices) throw new InvalidArgumentException('Add a wash service to your booking to use the free wash reward.');
                $discountAmount = min($washPrices);
            } else {
                throw new InvalidArgumentException('Select a valid loyalty reward.');
            }
            $debit = $pdo->prepare('UPDATE customers SET reward_points=reward_points-? WHERE id=? AND reward_points>=?');
            $debit->execute([$pointsUsed, $customerId, $pointsUsed]);
            if ($debit->rowCount() !== 1) throw new InvalidArgumentException('Your loyalty balance changed. Refresh and try again.');
        }
        $payableAmount = max(0, $totalAmount - $discountAmount);
        $paymentState = $payableAmount <= 0 || ($paymentMethod !== 'Cash' && $paymentMethod !== 'Card') ? 'paid' : 'unpaid';
         $pdo->prepare("INSERT INTO bookings (booking_code,customer_id,vehicle_id,guest_name,guest_phone,guest_email,booking_date,time_slot,notes,total_amount,loyalty_points_used,loyalty_discount,status,payment_status)
                 VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'confirmed', ?)")
             ->execute([$code, $customerId, $vehicleId, $guest['name'] ?? null, $guest['phone'] ?? null, $guest['email'] ?? null,
                   $d['date'], $d['slot'], $d['notes'] ?? null, $payableAmount, $pointsUsed, $discountAmount, $paymentState]);
        $bookingId = $pdo->lastInsertId();

        if ($pointsUsed > 0) {
            $description = ($reward['type'] ?? '') === 'free_wash'
                ? 'Redeemed for a free wash'
                : 'Redeemed for Rs. ' . number_format($discountAmount, 2) . ' off';
            $pdo->prepare("INSERT INTO loyalty_transactions (customer_id,booking_id,transaction_type,points,description) VALUES (?,?,'redeemed',?,?)")
                ->execute([$customerId, $bookingId, $pointsUsed, $description]);
        }

        foreach ($d['services'] as $svc) {
            // accept either a service id (int) or an inline {name,price} pair
            if (is_numeric($svc)) {
                $price = $pdo->query("SELECT price FROM services WHERE id=" . intval($svc))->fetch()['price'] ?? 0;
                $pdo->prepare("INSERT INTO booking_services (booking_id,service_id,price_at_booking) VALUES (?,?,?)")
                    ->execute([$bookingId, $svc, $price]);
            }
        }

        $pdo->prepare("INSERT INTO payments (booking_id,amount,total,payment_method,paypal_email,card_last4,status)
                   VALUES (?,?,?,?,?,?,?)")
            ->execute([
                $bookingId, $payableAmount, $payableAmount,
                $paymentMethod,
                $d['paypalEmail'] ?? null,
                $d['cardLast4'] ?? null,
                $paymentState === 'unpaid' ? 'pending' : 'success'
            ]);

        if ($paymentMethod === 'Card' && $payableAmount > 0) {
            $checkout = payhereCheckoutData(
                $code, (float)$payableAmount, $guest, 'Rapid Auto service booking ' . $code,
                PAYHERE_APP_URL . '/track.html?id=' . urlencode($code) . '&payment=return',
                PAYHERE_APP_URL . '/book.html?payment=cancelled'
            );
            $pdo->commit();
            respond(['payhereCheckout' => $checkout, 'bookingCode' => $code, 'id' => $bookingId, 'total' => $payableAmount, 'loyaltyDiscount' => $discountAmount, 'stages' => $STAGES], 201);
        }

        $pdo->commit();
    sendBookingConfirmationEmail($pdo, (int)$bookingId);
        respond(['bookingCode' => $code, 'id' => $bookingId, 'stages' => $STAGES], 201);
    } catch (InvalidArgumentException $e) {
        $pdo->rollBack();
        respond(['error' => $e->getMessage()], 400);
    } catch (Exception $e) {
        $pdo->rollBack();
        respond(['error' => $e->getMessage()], 500);
    }
}

// ---- Public tracking ----
if ($method === 'GET' && $action === 'track') {
    $code = $_GET['code'] ?? '';
    $stmt = $pdo->prepare("SELECT b.*, v.vehicle_type, v.registration_number FROM bookings b
                            LEFT JOIN vehicles v ON v.id = b.vehicle_id WHERE b.booking_code = ?");
    $stmt->execute([$code]);
    $b = $stmt->fetch();
    if (!$b) respond(['error' => 'No booking found with that ID'], 404);

    $svcStmt = $pdo->prepare("SELECT s.service_name FROM booking_services bs JOIN services s ON s.id = bs.service_id WHERE bs.booking_id = ?");
    $svcStmt->execute([$b['id']]);
    $services = array_column($svcStmt->fetchAll(), 'service_name');

    respond([
        'bookingCode' => $b['booking_code'], 'status' => $b['status'], 'stages' => $STAGES,
        'currentStageIndex' => (int)$b['tracking_stage_index'], 'currentStage' => $STAGES[$b['tracking_stage_index']],
        'services' => $services, 'vehicleType' => $b['vehicle_type'], 'registration' => $b['registration_number'],
        'date' => $b['booking_date'], 'slot' => $b['time_slot'],
    ]);
}

// ---- Public: manage a booking (lookup for reschedule/cancel form) ----
if ($method === 'GET' && $action === 'manage') {
    $code = $_GET['code'] ?? '';
    $phone = $_GET['phone'] ?? '';
    $stmt = $pdo->prepare("SELECT b.*, v.vehicle_type, v.registration_number FROM bookings b
                            LEFT JOIN vehicles v ON v.id = b.vehicle_id WHERE b.booking_code = ?");
    $stmt->execute([$code]);
    $b = $stmt->fetch();
    if (!$b) respond(['error' => 'No booking found with that reference'], 404);
    // simple verification: phone must match, if the booking has one on file
    if (!empty($b['guest_phone']) && $phone && trim($phone) !== trim($b['guest_phone'])) {
        respond(['error' => 'Phone number does not match our records for this booking'], 403);
    }
    if (in_array($b['status'], ['completed','cancelled'])) {
        respond(['error' => 'This booking is already ' . $b['status'] . ' and can no longer be changed'], 400);
    }
    $svcStmt = $pdo->prepare("SELECT s.service_name FROM booking_services bs JOIN services s ON s.id = bs.service_id WHERE bs.booking_id = ?");
    $svcStmt->execute([$b['id']]);
    $services = array_column($svcStmt->fetchAll(), 'service_name');
    respond([
        'bookingCode' => $b['booking_code'], 'status' => $b['status'], 'date' => $b['booking_date'], 'slot' => $b['time_slot'],
        'vehicleType' => $b['vehicle_type'], 'registration' => $b['registration_number'], 'services' => $services,
        'guestName' => $b['guest_name'], 'totalAmount' => $b['total_amount'],
    ]);
}

// ---- Public: reschedule a booking ----
if ($method === 'PATCH' && $action === 'reschedule') {
    $code = $_GET['code'] ?? '';
    $d = jsonInput();
    if (empty($d['date']) || empty($d['slot'])) respond(['error' => 'New date and time slot are required'], 400);
    $stmt = $pdo->prepare("SELECT id, status FROM bookings WHERE booking_code = ?");
    $stmt->execute([$code]);
    $b = $stmt->fetch();
    if (!$b) respond(['error' => 'No booking found with that reference'], 404);
    if (in_array($b['status'], ['completed','cancelled'])) respond(['error' => 'This booking can no longer be changed'], 400);
    $pdo->prepare("UPDATE bookings SET booking_date=?, time_slot=? WHERE id=?")->execute([$d['date'], $d['slot'], $b['id']]);
    respond(['ok' => true, 'message' => 'Booking rescheduled successfully']);
}

// ---- Public: cancel a booking ----
if ($method === 'PATCH' && $action === 'cancel') {
    $code = $_GET['code'] ?? '';
    $stmt = $pdo->prepare("SELECT id, status FROM bookings WHERE booking_code = ?");
    $stmt->execute([$code]);
    $b = $stmt->fetch();
    if (!$b) respond(['error' => 'No booking found with that reference'], 404);
    if (in_array($b['status'], ['completed','cancelled'])) respond(['error' => 'This booking can no longer be changed'], 400);
    $pdo->prepare("UPDATE bookings SET status='cancelled' WHERE id=?")->execute([$b['id']]);
    respond(['ok' => true, 'message' => 'Booking cancelled successfully']);
}

// ---- Admin: list all bookings ----
if ($method === 'GET' && $action === '') {
    requireAdmin();
    $rows = $pdo->query("SELECT b.*, v.vehicle_type, v.registration_number,
                          p.status AS payment_record_status
                          FROM bookings b
                          LEFT JOIN vehicles v ON v.id=b.vehicle_id
                          LEFT JOIN payments p ON p.id=(SELECT MAX(p2.id) FROM payments p2 WHERE p2.booking_id=b.id)
                          ORDER BY b.created_at DESC")->fetchAll();
    respond($rows);
}

// ---- Admin: view full booking detail (services + payment) ----
if ($method === 'GET' && $action === 'detail') {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing booking id'], 400);
    $stmt = $pdo->prepare("SELECT b.*, v.vehicle_type, v.registration_number, v.brand, v.model FROM bookings b
                            LEFT JOIN vehicles v ON v.id=b.vehicle_id WHERE b.id=?");
    $stmt->execute([$id]);
    $b = $stmt->fetch();
    if (!$b) respond(['error' => 'Booking not found'], 404);
    $svcStmt = $pdo->prepare("SELECT s.service_name, bs.price_at_booking FROM booking_services bs JOIN services s ON s.id=bs.service_id WHERE bs.booking_id=?");
    $svcStmt->execute([$id]);
    $b['services'] = $svcStmt->fetchAll();
    $payStmt = $pdo->prepare("SELECT * FROM payments WHERE booking_id=?");
    $payStmt->execute([$id]);
    $b['payment'] = $payStmt->fetch();
    respond($b);
}

// ---- Admin: full edit of a booking ----
if ($method === 'PUT' && $action === '') {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing booking id'], 400);
    $d = jsonInput();
    $pdo->beginTransaction();
    try {
        $status = $d['status'] ?? 'confirmed';
        $pdo->prepare("UPDATE bookings SET guest_name=?, guest_phone=?, guest_email=?, booking_date=?, time_slot=?, notes=?, total_amount=?, status=? WHERE id=?")
            ->execute([$d['guestName'] ?? null, $d['guestPhone'] ?? null, $d['guestEmail'] ?? null, $d['date'] ?? null,
                       $d['slot'] ?? null, $d['notes'] ?? null, $d['totalAmount'] ?? 0, $status, $id]);
        if ($status === 'completed') recordCompletedBookingService($pdo, (int)$id);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        respond(['error' => $error->getMessage()], 500);
    }
    respond(['ok' => true]);
}

// ---- Admin: delete a booking entirely ----
if ($method === 'DELETE' && $action === '') {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing booking id'], 400);
    $pdo->prepare("DELETE FROM bookings WHERE id=?")->execute([$id]); // cascades to booking_services + payments
    respond(['ok' => true]);
}

// ---- Admin: move tracking stage forward or backward ----
if ($method === 'PATCH' && in_array($action, ['advance','stage'], true)) {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing booking id'], 400);
    $stmt = $pdo->prepare("SELECT tracking_stage_index FROM bookings WHERE id=?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) respond(['error' => 'Booking not found'], 404);
    $direction = $action === 'advance' ? 1 : (int)($_GET['direction'] ?? 0);
    if (!in_array($direction, [-1, 1], true)) respond(['error' => 'Direction must be -1 or 1'], 400);
    $next = max(0, min((int)$row['tracking_stage_index'] + $direction, count($STAGES) - 1));
    $status = $next === count($STAGES) - 1 ? 'completed' : ($next === 0 ? 'confirmed' : 'in_progress');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE bookings SET tracking_stage_index=?, status=? WHERE id=?")->execute([$next, $status, $id]);
        if ($status === 'completed') recordCompletedBookingService($pdo, (int)$id);
        $pdo->commit();
    } catch (Throwable $error) {
        $pdo->rollBack();
        respond(['error' => $error->getMessage()], 500);
    }
    respond(['currentStage' => $STAGES[$next]]);
}

respond(['error' => 'Unknown action'], 404);
