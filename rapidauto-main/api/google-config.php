<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../config/google.php';

$clientId = trim(GOOGLE_CLIENT_ID);
if ($clientId === '') respond(['configured' => false, 'clientId' => '']);

respond(['configured' => true, 'clientId' => $clientId]);