<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';

$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
// Customer routes are scoped to the signed-in owner; management routes below require staff roles.
$staff = requireAuth();

// Normalize plate formatting before matching or storing a registration number.
function normalizePlate($value) {
    return strtoupper(preg_replace('/[^A-Z0-9]/i', '', trim((string)$value)));
}

// Current owner lookup for the staff register, including technician service counts.
function vehicleQuery() {
    return "SELECT v.*, c.id AS owner_id, u.first_name, u.last_name, u.email AS owner_email, u.phone AS owner_phone,
                   (SELECT COUNT(*) FROM vehicle_service_records vsr WHERE vsr.vehicle_id=v.id) AS service_count,
                   (SELECT MAX(vsr.service_date) FROM vehicle_service_records vsr WHERE vsr.vehicle_id=v.id) AS last_service_date
            FROM vehicles v
            LEFT JOIN customers c ON c.id=v.customer_id
            LEFT JOIN users u ON u.id=c.user_id";
}

// Retain owner details for older vehicles linked through bookings instead of customer_id.
function legacyVehicleQuery() {
    return "SELECT v.*, c.id AS owner_id,
                   COALESCE(u.first_name, NULLIF(TRIM(SUBSTRING_INDEX(b.guest_name, ' ', 1)), '')) AS first_name,
                   COALESCE(u.last_name, NULLIF(TRIM(SUBSTRING(b.guest_name, LENGTH(SUBSTRING_INDEX(b.guest_name, ' ', 1)) + 1)), '')) AS last_name,
                   COALESCE(u.email, b.guest_email) AS owner_email,
                   COALESCE(u.phone, b.guest_phone) AS owner_phone,
                   b.booking_date AS last_service_date,
                   b.id AS last_booking_id,
                   b.booking_code AS last_booking_code
            FROM vehicles v
            LEFT JOIN customers c ON c.id=v.customer_id
            LEFT JOIN users u ON u.id=c.user_id
            LEFT JOIN bookings b ON b.id=(SELECT MAX(b2.id) FROM bookings b2 WHERE b2.vehicle_id=v.id)";
}

function getVehicleServiceRecords(PDO $pdo, $vehicleId) {
    try {
        $stmt = $pdo->prepare("SELECT id AS service_record_id, booking_id, service_date, odometer_km,
                                      service_summary, issues, recommendations, next_service_date, next_service_km
                               FROM vehicle_service_records
                               WHERE vehicle_id=? ORDER BY service_date DESC, id DESC");
        $stmt->execute([$vehicleId]);
        return $stmt->fetchAll();
    } catch (PDOException $error) {
        // Booking history remains available on databases that predate technician records.
        if ((int)($error->errorInfo[1] ?? 0) === 1146) return [];
        throw $error;
    }
}

// Staff check-ins link a vehicle to an existing customer or create its user/profile pair.
function findOrCreateCustomer(PDO $pdo, array $owner) {
    $phone = trim($owner['phone'] ?? '');
    $email = strtolower(trim($owner['email'] ?? ''));
    if (!$phone && !$email) respond(['error' => 'Owner phone or email is required'], 400);

    $stmt = $pdo->prepare("SELECT c.id FROM customers c WHERE (? <> '' AND c.phone=?) OR (? <> '' AND c.email=?) LIMIT 1");
    $stmt->execute([$phone, $phone, $email, $email]);
    $existing = $stmt->fetchColumn();
    if ($existing) return (int)$existing;

    $firstName = trim($owner['firstName'] ?? '');
    $lastName = trim($owner['lastName'] ?? '');
    $fullName = preg_split('/\s+/', trim($owner['name'] ?? ''));
    if (!$firstName && !empty($fullName[0])) $firstName = array_shift($fullName);
    if (!$lastName && $fullName) $lastName = implode(' ', $fullName);
    $syntheticEmail = $email ?: 'walkin+' . strtolower(sha1($phone)) . '@rapidauto.local';

    $pdo->prepare("INSERT INTO users (first_name,last_name,email,phone,password,role) VALUES (?,?,?,?,?,'customer')")
        ->execute([$firstName ?: 'Walk-in', $lastName, $syntheticEmail, $phone, password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT)]);
    $userId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO customers (user_id,first_name,last_name,email,phone) VALUES (?,?,?,?,?)")
        ->execute([$userId, $firstName ?: 'Walk-in', $lastName, $syntheticEmail, $phone]);
    return (int)$pdo->lastInsertId();
}

// Assemble the vehicle, bookings, booked services, and technician history for detail views.
function vehicleDetail(PDO $pdo, $id) {
    $stmt = $pdo->prepare(legacyVehicleQuery() . " WHERE v.id=?");
    $stmt->execute([$id]);
    $vehicle = $stmt->fetch();
    if (!$vehicle) respond(['error' => 'Vehicle not found'], 404);
    $bookingStmt = $pdo->prepare("SELECT id AS booking_id, booking_code, booking_date, time_slot, notes,
                                         total_amount, status, payment_status
                                  FROM bookings WHERE vehicle_id=? ORDER BY booking_date DESC, id DESC");
    $bookingStmt->execute([$id]);
    $vehicle['bookings'] = $bookingStmt->fetchAll();
    $serviceStmt = $pdo->prepare("SELECT s.service_name, bs.price_at_booking
                                  FROM booking_services bs JOIN services s ON s.id=bs.service_id
                                  WHERE bs.booking_id=? ORDER BY s.service_name");
    foreach ($vehicle['bookings'] as &$booking) {
        $serviceStmt->execute([$booking['booking_id']]);
        $booking['services'] = $serviceStmt->fetchAll();
    }
    unset($booking);
    $vehicle['history'] = getVehicleServiceRecords($pdo, $id);
    return $vehicle;
}

// Customer garage: derive the owner from the token, never from a client-supplied customer ID.
if ($method === 'GET' && $action === 'mine') {
    if ($staff['role'] !== 'customer') respond(['error' => 'Customer access required'], 403);
    $profileStmt = $pdo->prepare("SELECT u.id, u.first_name, u.last_name, u.email, u.phone, c.id AS customer_id
                                  FROM users u JOIN customers c ON c.user_id=u.id WHERE u.id=? LIMIT 1");
    $profileStmt->execute([$staff['id']]);
    $owner = $profileStmt->fetch();
    if (!$owner) respond(['error' => 'Customer profile not found'], 404);
    $stmt = $pdo->prepare(legacyVehicleQuery() . " WHERE c.user_id=? OR b.guest_phone=? OR b.guest_email=? ORDER BY v.registration_number");
    $stmt->execute([$staff['id'], $owner['phone'] ?? '', $owner['email'] ?? '']);
    respond(['owner' => $owner, 'vehicles' => $stmt->fetchAll()]);
}

// Customer self-service vehicle creation; ownership is assigned from the authenticated profile.
if ($method === 'POST' && $action === 'mine') {
    if ($staff['role'] !== 'customer') respond(['error' => 'Customer access required'], 403);
    $d = jsonInput();
    $v = $d['vehicle'] ?? [];
    $vehicleType = trim((string)($v['vehicleType'] ?? ''));
    $vehicleTypes = ['Motorcycle','Car','Jeep/Vessel','Three Wheeler','Van (Small)','Van (Large)','Lorry'];
    $plate = normalizePlate($v['registrationNumber'] ?? '');
    if (!$plate || !in_array($vehicleType, $vehicleTypes, true)) {
        respond(['error' => 'A valid vehicle type and registration number are required'], 400);
    }

    $yearValue = trim((string)($v['year'] ?? ''));
    $year = $yearValue === '' ? null : filter_var($yearValue, FILTER_VALIDATE_INT);
    $kmValue = trim((string)($v['currentKm'] ?? ''));
    $currentKm = $kmValue === '' ? null : filter_var($kmValue, FILTER_VALIDATE_INT);
    if ($year === false || ($year !== null && ($year < 1886 || $year > (int)date('Y') + 1))) {
        respond(['error' => 'Enter a valid vehicle year'], 400);
    }
    if ($currentKm === false || ($currentKm !== null && $currentKm < 0)) {
        respond(['error' => 'Mileage must be a non-negative whole number'], 400);
    }

    $customerStmt = $pdo->prepare('SELECT id FROM customers WHERE user_id=? LIMIT 1');
    $customerStmt->execute([$staff['id']]);
    $customerId = $customerStmt->fetchColumn();
    if (!$customerId) respond(['error' => 'Customer profile not found'], 404);

    $duplicateStmt = $pdo->prepare('SELECT id FROM vehicles WHERE registration_number=? AND active=1 LIMIT 1');
    $duplicateStmt->execute([$plate]);
    if ($duplicateStmt->fetchColumn()) respond(['error' => 'That registration number is already registered'], 409);

    try {
        $pdo->prepare('INSERT INTO vehicles (customer_id,vehicle_type,brand,model,year,color,registration_number,engine_number,chassis_number,fuel_type,current_km) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $customerId, $vehicleType,
                trim((string)($v['brand'] ?? '')) ?: null,
                trim((string)($v['model'] ?? '')) ?: null,
                $year,
                trim((string)($v['color'] ?? '')) ?: null,
                $plate,
                trim((string)($v['engineNumber'] ?? '')) ?: null,
                trim((string)($v['chassisNumber'] ?? '')) ?: null,
                trim((string)($v['fuelType'] ?? '')) ?: null,
                $currentKm,
            ]);
        $vehicleId = (int)$pdo->lastInsertId();
        respond(vehicleDetail($pdo, $vehicleId), 201);
    } catch (PDOException $error) {
        if ((int)($error->errorInfo[1] ?? 0) === 1062) {
            respond(['error' => 'That registration number is already registered'], 409);
        }
        respond(['error' => 'Could not add this vehicle'], 500);
    }
}

// Customer detail access is checked against direct ownership or a matching booking contact.
if ($method === 'GET' && $action === 'detail') {
    if ($staff['role'] === 'customer') {
        $ownerCheck = $pdo->prepare("SELECT 1 FROM vehicles v
                                     LEFT JOIN customers c ON c.id=v.customer_id
                                     LEFT JOIN users u ON u.id=c.user_id
                                     LEFT JOIN bookings b ON b.vehicle_id=v.id
                                     WHERE v.id=? AND (c.user_id=? OR EXISTS (
                                         SELECT 1 FROM users logged_user
                                         WHERE logged_user.id=? AND (b.guest_phone=logged_user.phone OR b.guest_email=logged_user.email)
                                     )) LIMIT 1");
        $ownerCheck->execute([$_GET['id'] ?? 0, $staff['id'], $staff['id']]);
        if (!$ownerCheck->fetchColumn()) respond(['error' => 'Vehicle not found'], 404);
    }
    respond(vehicleDetail($pdo, $_GET['id'] ?? 0));
}

// Staff-only searchable vehicle register.
if ($method === 'GET' && $action === '') {
    requireStaff();
    $search = trim($_GET['search'] ?? '');
    $params = [];
    $where = '1=1';
    if ($search !== '') {
        $plate = normalizePlate($search);
        $where .= " AND (v.registration_number LIKE ? OR CONCAT_WS(' ',u.first_name,u.last_name,b.guest_name) LIKE ? OR COALESCE(u.phone,b.guest_phone) LIKE ? )";
        $params = [$plate . '%', '%' . $search . '%', '%' . $search . '%'];
    }
    $stmt = $pdo->prepare(legacyVehicleQuery() . " WHERE $where ORDER BY v.registration_number LIMIT 100");
    $stmt->execute($params);
    respond($stmt->fetchAll());
}

// Record completed work and update the vehicle's mileage-based/date-based reminder targets.
if ($method === 'POST' && $action === 'service') {
    requireStaff();
    $d = jsonInput();
    $vehicleId = (int)($d['vehicleId'] ?? 0);
    if (!$vehicleId || empty($d['serviceSummary']) || empty($d['serviceDate'])) respond(['error' => 'Vehicle, service date, and summary are required'], 400);
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO vehicle_service_records (vehicle_id,booking_id,service_date,odometer_km,service_summary,issues,recommendations,next_service_date,next_service_km) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$vehicleId, $d['bookingId'] ?? null, $d['serviceDate'], $d['odometerKm'] ?? null, $d['serviceSummary'], $d['issues'] ?? null, $d['recommendations'] ?? null, $d['nextServiceDate'] ?? null, $d['nextServiceKm'] ?? null]);
        $pdo->prepare("UPDATE vehicles SET current_km=COALESCE(?,current_km), next_service_date=?, next_service_km=? WHERE id=? AND active=1")
            ->execute([$d['odometerKm'] ?? null, $d['nextServiceDate'] ?? null, $d['nextServiceKm'] ?? null, $vehicleId]);
        $pdo->commit();
        respond(['id' => $pdo->lastInsertId()], 201);
    } catch (Exception $e) {
        $pdo->rollBack();
        respond(['error' => $e->getMessage()], 500);
    }
}

// Staff check-in creates a vehicle and links it to the resolved customer profile.
if ($method === 'POST' && $action === '') {
    requireStaff();
    $d = jsonInput();
    $v = $d['vehicle'] ?? $d;
    $plate = normalizePlate($v['registrationNumber'] ?? $v['registration_number'] ?? '');
    if (!$plate || empty($v['vehicleType'])) respond(['error' => 'Plate number and vehicle type are required'], 400);
    $pdo->beginTransaction();
    try {
        $customerId = findOrCreateCustomer($pdo, $d['owner'] ?? []);
        $pdo->prepare("INSERT INTO vehicles (customer_id,vehicle_type,brand,model,year,color,registration_number,engine_number,chassis_number,fuel_type,current_km,next_service_date,next_service_km) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)")
            ->execute([$customerId, $v['vehicleType'], $v['brand'] ?? null, $v['model'] ?? null, $v['year'] ?? null, $v['color'] ?? null, $plate, $v['engineNumber'] ?? null, $v['chassisNumber'] ?? null, $v['fuelType'] ?? null, $v['currentKm'] ?? null, $v['nextServiceDate'] ?? null, $v['nextServiceKm'] ?? null]);
        $id = $pdo->lastInsertId();
        $pdo->commit();
        respond(vehicleDetail($pdo, $id), 201);
    } catch (PDOException $e) {
        $pdo->rollBack();
        if ((int)$e->errorInfo[1] === 1062) respond(['error' => 'That plate number is already registered'], 409);
        respond(['error' => $e->getMessage()], 500);
    }
}

// Only vehicle administrators may edit registered vehicles.
if ($method === 'PUT' && $action === '') {
    requireVehicleAdmin();
    $id = (int)($_GET['id'] ?? 0);
    $d = jsonInput();
    $v = $d['vehicle'] ?? $d;
    $plate = normalizePlate($v['registrationNumber'] ?? $v['registration_number'] ?? '');
    if (!$id || !$plate || empty($v['vehicleType'])) respond(['error' => 'Vehicle id, plate number, and type are required'], 400);
    try {
        $pdo->prepare("UPDATE vehicles SET vehicle_type=?,brand=?,model=?,year=?,color=?,registration_number=?,engine_number=?,chassis_number=?,fuel_type=?,current_km=?,next_service_date=?,next_service_km=? WHERE id=? AND active=1")
            ->execute([$v['vehicleType'], $v['brand'] ?? null, $v['model'] ?? null, $v['year'] ?? null, $v['color'] ?? null, $plate, $v['engineNumber'] ?? null, $v['chassisNumber'] ?? null, $v['fuelType'] ?? null, $v['currentKm'] ?? null, $v['nextServiceDate'] ?? null, $v['nextServiceKm'] ?? null, $id]);
        respond(['ok' => true]);
    } catch (PDOException $e) {
        if ((int)$e->errorInfo[1] === 1062) respond(['error' => 'That plate number is already registered'], 409);
        respond(['error' => $e->getMessage()], 500);
    }
}

// Soft-delete preserves vehicle and service history for reporting.
if ($method === 'DELETE' && $action === '') {
    requireVehicleAdmin();
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) respond(['error' => 'Missing vehicle id'], 400);
    $pdo->prepare("UPDATE vehicles SET active=0 WHERE id=?")->execute([$id]);
    respond(['ok' => true]);
}

respond(['error' => 'Unknown action'], 404);
