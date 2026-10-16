<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/jwt.php';

// This endpoint always reads and writes the profile belonging to the authenticated customer.
$user = requireAuth();
if (($user['role'] ?? '') !== 'customer') respond(['error' => 'Customer access required'], 403);

$pdo = getDB();
$stmt = $pdo->prepare("SELECT c.id, c.user_id, u.first_name, u.last_name,
                              u.email, u.phone, u.profile_image, c.address
                       FROM users u JOIN customers c ON c.user_id=u.id WHERE u.id=?");
$stmt->execute([$user['id']]);
$profile = $stmt->fetch();
if (!$profile) respond(['error' => 'Customer profile not found'], 404);

if ($_SERVER['REQUEST_METHOD'] === 'GET') respond($profile);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed'], 405);

$firstName = trim($_POST['firstName'] ?? '');
$lastName = trim($_POST['lastName'] ?? '');
$phone = trim($_POST['phone'] ?? '');
$address = trim($_POST['address'] ?? '');
if ($firstName === '' || $lastName === '') respond(['error' => 'First name and last name are required'], 400);

$profileImage = ($_POST['removeProfileImage'] ?? '0') === '1' ? null : $profile['profile_image'];
// Validate the uploaded image by its detected MIME type before storing it.
if (!empty($_FILES['profileImage']['name'])) {
    $file = $_FILES['profileImage'];
    if ($file['error'] !== UPLOAD_ERR_OK) respond(['error' => 'Profile image upload failed'], 400);
    if ($file['size'] > 3 * 1024 * 1024) respond(['error' => 'Profile image must be 3 MB or smaller'], 400);

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
    $extensions = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    if (!isset($extensions[$mime])) respond(['error' => 'Use a JPG, PNG, or WebP image'], 400);

    $directory = dirname(__DIR__) . '/uploads/profiles';
    if (!is_dir($directory) && !mkdir($directory, 0755, true)) respond(['error' => 'Could not prepare image storage'], 500);
    $filename = 'user-' . (int)$user['id'] . '-' . bin2hex(random_bytes(8)) . '.' . $extensions[$mime];
    if (!move_uploaded_file($file['tmp_name'], $directory . '/' . $filename)) respond(['error' => 'Could not save profile image'], 500);
    $profileImage = 'uploads/profiles/' . $filename;
}

$pdo->beginTransaction();
try {
    // Keep the customer-facing profile and login identity synchronized.
    $pdo->prepare("UPDATE customers SET first_name=?, last_name=?, phone=?, address=? WHERE user_id=?")
        ->execute([$firstName, $lastName, $phone, $address, $user['id']]);
    $pdo->prepare("UPDATE users SET first_name=?, last_name=?, phone=?, profile_image=? WHERE id=?")
        ->execute([$firstName, $lastName, $phone, $profileImage, $user['id']]);
    $pdo->commit();
} catch (Throwable $error) {
    $pdo->rollBack();
    respond(['error' => 'Could not update profile'], 500);
}

$profile['first_name'] = $firstName;
$profile['last_name'] = $lastName;
$profile['phone'] = $phone;
$profile['address'] = $address;
$profile['profile_image'] = $profileImage;
respond($profile);