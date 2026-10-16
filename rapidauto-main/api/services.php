<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $category = $_GET['category'] ?? null;
    if ($category) {
        $stmt = $pdo->prepare("SELECT * FROM services WHERE status='active' AND category = ? ORDER BY price ASC");
        $stmt->execute([$category]);
    } else {
        $stmt = $pdo->query("SELECT * FROM services WHERE status='active' ORDER BY category, price ASC");
    }
    respond($stmt->fetchAll());
}

if ($method === 'POST') {
    requireAdmin();
    $d = jsonInput();
    $category = trim((string)($d['category'] ?? ''));
    $serviceName = trim((string)($d['serviceName'] ?? ''));
    $price = filter_var($d['price'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($category === '' || $serviceName === '') respond(['error' => 'Category and service name are required'], 400);
    if ($price === false || $price < 0) respond(['error' => 'Price must be a valid non-negative number'], 400);
    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO services (category,service_name,description,price,duration) VALUES (?,?,?,?,?)");
        $stmt->execute([$category, $serviceName, $d['description'] ?? null, $price, $d['duration'] ?? 30]);
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
        if ($id < 1) respond(['error' => 'Service was not inserted'], 500);
        respond(['id' => $id], 201);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        respond(['error' => 'Could not insert service into the services table: ' . $e->getMessage()], 500);
    }
}

if ($method === 'PUT') {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing service id'], 400);
    $d = jsonInput();
    $category = trim((string)($d['category'] ?? ''));
    $serviceName = trim((string)($d['serviceName'] ?? ''));
    $price = filter_var($d['price'] ?? null, FILTER_VALIDATE_FLOAT);
    if ($category === '' || $serviceName === '') respond(['error' => 'Category and service name are required'], 400);
    if ($price === false || $price < 0) respond(['error' => 'Price must be a valid non-negative number'], 400);
    $pdo->prepare("UPDATE services SET category=?, service_name=?, description=?, price=?, duration=? WHERE id=?")
        ->execute([$category, $serviceName, $d['description'] ?? null, $price, $d['duration'] ?? 30, $id]);
    respond(['ok' => true]);
}

if ($method === 'DELETE') {
    requireAdmin();
    $id = $_GET['id'] ?? null;
    if (!$id) respond(['error' => 'Missing service id'], 400);
    $pdo->prepare("UPDATE services SET status='inactive' WHERE id=?")->execute([$id]);
    respond(['ok' => true]);
}

respond(['error' => 'Method not allowed'], 405);
