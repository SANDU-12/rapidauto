<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';
requireAdmin();
$pdo = getDB();

$period = $_GET['period'] ?? 'monthly'; // daily | weekly | monthly | yearly
$dateExpr = [
    'daily'   => "DATE(created_at)",
    'weekly'  => "CONCAT(YEAR(created_at),'-W',LPAD(WEEK(created_at,3),2,'0'))",
    'monthly' => "DATE_FORMAT(created_at, '%Y-%m')",
    'yearly'  => "YEAR(created_at)",
][$period] ?? "DATE_FORMAT(created_at, '%Y-%m')";

// Overall summary
$totalBookings = $pdo->query("SELECT COUNT(*) c FROM bookings")->fetch()['c'];
$totalOrders = $pdo->query("SELECT COUNT(*) c FROM orders")->fetch()['c'];
$serviceRevenue = $pdo->query("SELECT COALESCE(SUM(total),0) t FROM payments WHERE status='success'")->fetch()['t'];
$shopRevenue = $pdo->query("SELECT COALESCE(SUM(total),0) t FROM orders WHERE status != 'cancelled'")->fetch()['t'];
$totalCustomers = $pdo->query("SELECT COUNT(*) c FROM customers")->fetch()['c'];
$completedBookings = $pdo->query("SELECT COUNT(*) c FROM bookings WHERE status='completed'")->fetch()['c'];
$cancelledBookings = $pdo->query("SELECT COUNT(*) c FROM bookings WHERE status='cancelled'")->fetch()['c'];
$lowStockItems = $pdo->query("SELECT COUNT(*) c FROM inventory WHERE status='low_stock'")->fetch()['c'];

// Bookings trend by period
$bookingsTrend = $pdo->query("SELECT $dateExpr AS period, COUNT(*) AS count, COALESCE(SUM(total_amount),0) AS revenue
                               FROM bookings GROUP BY period ORDER BY period DESC LIMIT 24")->fetchAll();

// Shop orders trend by period
$ordersTrend = $pdo->query("SELECT $dateExpr AS period, COUNT(*) AS count, COALESCE(SUM(total),0) AS revenue
                             FROM orders GROUP BY period ORDER BY period DESC LIMIT 24")->fetchAll();

// Top services
$topServices = $pdo->query("SELECT s.service_name, COUNT(*) AS times_booked, COALESCE(SUM(bs.price_at_booking),0) AS revenue
                             FROM booking_services bs JOIN services s ON s.id = bs.service_id
                             GROUP BY s.id ORDER BY times_booked DESC LIMIT 10")->fetchAll();

// Payment method breakdown
$paymentBreakdown = $pdo->query("SELECT payment_method, COUNT(*) AS count, COALESCE(SUM(total),0) AS total
                                  FROM payments WHERE status='success' GROUP BY payment_method")->fetchAll();

respond([
    'period' => $period,
    'summary' => [
        'totalBookings' => (int)$totalBookings, 'totalOrders' => (int)$totalOrders,
        'serviceRevenue' => (float)$serviceRevenue, 'shopRevenue' => (float)$shopRevenue,
        'totalRevenue' => (float)$serviceRevenue + (float)$shopRevenue,
        'totalCustomers' => (int)$totalCustomers, 'completedBookings' => (int)$completedBookings,
        'cancelledBookings' => (int)$cancelledBookings, 'lowStockItems' => (int)$lowStockItems,
    ],
    'bookingsTrend' => $bookingsTrend,
    'ordersTrend' => $ordersTrend,
    'topServices' => $topServices,
    'paymentBreakdown' => $paymentBreakdown,
    'generatedAt' => date('Y-m-d H:i:s'),
]);
