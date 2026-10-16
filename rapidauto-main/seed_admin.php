<?php
// Run this ONCE in your browser after importing schema.sql:
// http://localhost/rapidauto/seed_admin.php
require_once __DIR__ . '/config/db.php';
$pdo = getDB();

$email = 'admin@rapid.lk';
$plainPassword = 'admin123';
$hash = password_hash($plainPassword, PASSWORD_BCRYPT);

$stmt = $pdo->prepare("SELECT id FROM users WHERE email = ?");
$stmt->execute([$email]);

if ($stmt->fetch()) {
    $pdo->prepare("UPDATE users SET password = ?, role = 'super_admin', status = 'active' WHERE email = ?")
        ->execute([$hash, $email]);
    echo "Admin account already existed — password reset to: admin123";
} else {
    $pdo->prepare("INSERT INTO users (first_name,last_name,email,password,role) VALUES (?,?,?,?,?)")
        ->execute(['Rapid','Admin',$email,$hash,'super_admin']);
    echo "Admin account created!<br>Email: admin@rapid.lk<br>Password: admin123";
}
