<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
requireAdmin();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

const STAFF_ROLES = ['super_admin','manager','cashier','receptionist','mechanic','car_wash_staff','inventory_manager'];

// ---- List all employees, with their linked login (if any) ----
if ($method === 'GET') {
    $rows = $pdo->query("SELECT e.*, u.email AS login_email, u.role AS login_role
                          FROM employees e LEFT JOIN users u ON u.id = e.user_id
                          ORDER BY e.name")->fetchAll();
    respond($rows);
}

// ---- Add an employee, optionally creating a real login account for them ----
if ($method === 'POST') {
    $d = jsonInput();
    if (empty($d['name'])) respond(['error' => 'Name is required'], 400);

    $pdo->beginTransaction();
    try {
        $userId = null;
        if (!empty($d['createLogin'])) {
            if (empty($d['email']) || empty($d['password'])) throw new Exception('Email and password are required to create a login');
            if (empty($d['role']) || !in_array($d['role'], STAFF_ROLES, true)) throw new Exception('A valid staff role is required to create a login');
            $email = strtolower(trim($d['email']));
            $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetch()) throw new Exception('A login with this email already exists');

            $hash = password_hash($d['password'], PASSWORD_BCRYPT);
            $nameParts = explode(' ', trim($d['name']), 2);
            $pdo->prepare("INSERT INTO users (first_name,last_name,email,phone,password,role) VALUES (?,?,?,?,?,?)")
                ->execute([$nameParts[0], $nameParts[1] ?? '', $email, $d['phone'] ?? '', $hash, $d['role']]);
            $userId = $pdo->lastInsertId();
        }

        $pdo->prepare("INSERT INTO employees (user_id,name,position,phone,email,salary,availability,skills) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$userId, $d['name'], $d['position'] ?? null, $d['phone'] ?? null, $d['email'] ?? null,
                       $d['salary'] ?? null, $d['availability'] ?? 'available', $d['skills'] ?? null]);
        $employeeId = $pdo->lastInsertId();
        $pdo->commit();
        respond(['id' => $employeeId, 'loginCreated' => (bool)$userId], 201);
    } catch (Throwable $error) {
        $pdo->rollBack();
        respond(['error' => $error->getMessage() ?: 'Could not create employee'], 500);
    }
}

// ---- Edit an employee's profile fields (not their login credentials) ----
if ($method === 'PUT') {
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing employee id'], 400);
    $d = jsonInput();
    $pdo->prepare("UPDATE employees SET name=?, position=?, phone=?, email=?, salary=?, availability=?, skills=? WHERE id=?")
        ->execute([$d['name'], $d['position'] ?? null, $d['phone'] ?? null, $d['email'] ?? null,
                   $d['salary'] ?? null, $d['availability'] ?? 'available', $d['skills'] ?? null, $id]);
    respond(['ok' => true]);
}

// ---- Quick availability update ----
if ($method === 'PATCH') {
    $id = $_GET['id'] ?? null;
    $d = jsonInput();
    if (!$id || empty($d['availability'])) respond(['error' => 'Missing id or availability'], 400);
    if (!in_array($d['availability'], ['available','busy','off'])) respond(['error' => 'Invalid availability value'], 400);
    $pdo->prepare("UPDATE employees SET availability=? WHERE id=?")->execute([$d['availability'], $id]);
    respond(['ok' => true]);
}

// ---- Remove an employee (their login account, if any, is kept but unlinked) ----
if ($method === 'DELETE') {
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing employee id'], 400);
    $pdo->prepare("DELETE FROM employees WHERE id=?")->execute([$id]);
    respond(['ok' => true]);
}

respond(['error' => 'Method not allowed'], 405);
