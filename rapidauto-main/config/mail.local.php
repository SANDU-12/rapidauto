<?php
// SMTP settings for confirmation emails. Keep this file out of source control.
// Gmail will NOT accept your normal account password here. Create an "App password":
//   Google Account > Security > 2-Step Verification (turn on) > App passwords
// and paste the 16-character code into 'password' below.
return [
    'host'       => 'smtp.gmail.com',
    'port'       => 587,
    'encryption' => 'tls',               // tls | ssl | none
    'username'   => 'henukadulaje@gmail.com',
    'password'   => 'ezqc ewfx posv ihhq',
    'from_email' => 'henukadulaje@gmail.com',
    'from_name'  => 'Rapid Auto Service Center',
];
