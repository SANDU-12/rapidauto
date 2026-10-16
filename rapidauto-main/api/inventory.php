<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
requireAdmin();
$pdo = getDB();
$method = $_SERVER['REQUEST_METHOD'];
$section = $_GET['section'] ?? 'items';   // items | purchases | supplier-orders

/* ================= INVENTORY ITEMS ================= */
if ($section === 'items') {
    if ($method === 'GET') respond($pdo->query("SELECT * FROM inventory ORDER BY name")->fetchAll());

    if ($method === 'POST') {
        $d = jsonInput();
        $pdo->prepare("INSERT INTO inventory (name,category,quantity,minimum_stock,purchase_price,selling_price,location,status) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$d['name'], $d['category'] ?? null, $d['quantity'] ?? 0, $d['minimumStock'] ?? 5,
                       $d['purchasePrice'] ?? 0, $d['sellingPrice'] ?? 0, $d['location'] ?? null, $d['status'] ?? 'available']);
        respond(['id' => $pdo->lastInsertId()], 201);
    }

    if ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) respond(['error' => 'Missing item id'], 400);
        $d = jsonInput();
        $pdo->prepare("UPDATE inventory SET name=?,category=?,quantity=?,minimum_stock=?,purchase_price=?,selling_price=?,location=?,status=? WHERE id=?")
            ->execute([$d['name'], $d['category'] ?? null, $d['quantity'] ?? 0, $d['minimumStock'] ?? 5,
                       $d['purchasePrice'] ?? 0, $d['sellingPrice'] ?? 0, $d['location'] ?? null, $d['status'] ?? 'available', $id]);
        respond(['ok' => true]);
    }

    // PATCH just the availability status
    if ($method === 'PATCH') {
        $id = $_GET['id'] ?? null;
        $d = jsonInput();
        if (!$id || empty($d['status'])) respond(['error' => 'Missing id or status'], 400);
        if (!in_array($d['status'], ['available','unavailable','low_stock'])) respond(['error' => 'Invalid status'], 400);
        $pdo->prepare("UPDATE inventory SET status=? WHERE id=?")->execute([$d['status'], $id]);
        respond(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) respond(['error' => 'Missing item id'], 400);
        $pdo->prepare("UPDATE inventory SET status='unavailable' WHERE id=?")->execute([$id]); // "hide" = mark unavailable
        respond(['ok' => true]);
    }
}

/* ================= PURCHASE RECORDS ================= */
if ($section === 'purchases') {
    if ($method === 'GET') respond($pdo->query("SELECT * FROM purchase_records ORDER BY purchase_date DESC")->fetchAll());

    if ($method === 'POST') {
        $d = jsonInput();
        $count = $pdo->query("SELECT COUNT(*) c FROM purchase_records")->fetch()['c'];
        $code = 'PR-' . (1000 + $count + 1);
        $pdo->prepare("INSERT INTO purchase_records (purchase_code,item_name,quantity,cost,purchase_date) VALUES (?,?,?,?,?)")
            ->execute([$code, $d['itemName'], $d['quantity'], $d['cost'], $d['purchaseDate']]);
        respond(['id' => $pdo->lastInsertId(), 'purchaseCode' => $code], 201);
    }

    if ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) respond(['error' => 'Missing purchase id'], 400);
        $d = jsonInput();
        $pdo->prepare("UPDATE purchase_records SET item_name=?,quantity=?,cost=?,purchase_date=? WHERE id=?")
            ->execute([$d['itemName'], $d['quantity'], $d['cost'], $d['purchaseDate'], $id]);
        respond(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) respond(['error' => 'Missing purchase id'], 400);
        $pdo->prepare("DELETE FROM purchase_records WHERE id=?")->execute([$id]);
        respond(['ok' => true]);
    }
}

/* ================= SUPPLIER ORDERS ================= */
if ($section === 'supplier-orders') {
    if ($method === 'GET') respond($pdo->query("SELECT * FROM supplier_orders ORDER BY order_date DESC")->fetchAll());

    if ($method === 'POST') {
        $d = jsonInput();
        $count = $pdo->query("SELECT COUNT(*) c FROM supplier_orders")->fetch()['c'];
        $code = 'SO-' . (1000 + $count + 1);
        $pdo->prepare("INSERT INTO supplier_orders (order_code,item_name,quantity,cost,order_date,status) VALUES (?,?,?,?,?,?)")
            ->execute([$code, $d['itemName'], $d['quantity'], $d['cost'], $d['orderDate'], $d['status'] ?? 'ordered']);
        respond(['id' => $pdo->lastInsertId(), 'orderCode' => $code], 201);
    }

    if ($method === 'PUT') {
        $id = $_GET['id'] ?? null;
        if (!$id) respond(['error' => 'Missing order id'], 400);
        $d = jsonInput();
        $pdo->prepare("UPDATE supplier_orders SET item_name=?,quantity=?,cost=?,order_date=?,status=? WHERE id=?")
            ->execute([$d['itemName'], $d['quantity'], $d['cost'], $d['orderDate'], $d['status'] ?? 'ordered', $id]);
        respond(['ok' => true]);
    }

    // PATCH just the status (received / ordered / returned)
    if ($method === 'PATCH') {
        $id = $_GET['id'] ?? null;
        $d = jsonInput();
        if (!$id || empty($d['status'])) respond(['error' => 'Missing id or status'], 400);
        if (!in_array($d['status'], ['ordered','received','returned'])) respond(['error' => 'Invalid status'], 400);
        $pdo->prepare("UPDATE supplier_orders SET status=? WHERE id=?")->execute([$d['status'], $id]);
        respond(['ok' => true]);
    }

    if ($method === 'DELETE') {
        $id = $_GET['id'] ?? null;
        if (!$id) respond(['error' => 'Missing order id'], 400);
        $pdo->prepare("DELETE FROM supplier_orders WHERE id=?")->execute([$id]);
        respond(['ok' => true]);
    }
}

respond(['error' => 'Unknown section or method'], 404);
