<?php

// Prices are in naira. Change them here (or the daily limit in .env) and nothing else needs to change.
return [
    'free_daily_limit' => (int) env('FREE_DAILY_EXAMS', 2),
    'paystack_secret' => env('PAYSTACK_SECRET_KEY'),
    'frontend_url' => env('FRONTEND_URL', 'http://localhost:5173'),
    'plans' => [
        'month'   => ['name' => '1 month',  'days' => 30, 'amount' => 1500],
        'quarter' => ['name' => '3 months', 'days' => 90, 'amount' => 3500],
    ],
];
