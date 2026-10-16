<?php

$amount = 3000;
$merchant_id = "1238494";
$merchant_secret = "MzM1NTkxMDY4NjY4NTAxNjk4NjE1MzkwNDYzNDUzODA5ODg5MTc4";
$order_id = uniqid();
$currency = "LKR";

$hash = strtoupper(md5(
    $merchant_id . 
    $order_id . 
    number_format($amount, 2, '.', '') .
    $amount . 
    $currency . 
    strtoupper(md5($merchant_secret))
    )
    );

    $array = [,
        'first_name' => "John",
        'last_name' => "Doe",
        'email' => "john.doe@example.com",
        'phone' => "0771234567",
        'address' => "No. 46, Galle road, Kalutara South",
        'city' => "Kalutara",
        'country' => "Sri Lanka",
        'delivery_address' => "No. 46, Galle road, Kalutara South",
        'delivery_city' => "Kalutara",
        'delivery_country' => "Sri Lanka",
        'order_id' => $order_id,
        'items' => [
            [
                'name' => "Product 1",
                'quantity' => 1,
                'amount' => $amount
            ]
        ],
        'amount' => $amount,
        'currency' => $currency,
        'hash' => $hash
    ];
