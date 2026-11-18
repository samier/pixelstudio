<?php

return [
    // Users pay Pixelforge's cost plus this percentage.
    'markup_percent' => 25,

    // Free credit every new account starts with, in whole US dollars.
    'signup_credit_usd' => (int) env('BILLING_SIGNUP_CREDIT_USD', 1),

    // Amounts offered by the (fake) "Buy credits" button, in whole US dollars.
    'purchase_options_usd' => [5, 10, 25],

    // Limits for a custom amount typed by the user, in US dollars (whole cents only).
    'custom_purchase_min_usd' => 1,
    'custom_purchase_max_usd' => 500,

    // Comma-separated emails that can see the operator report.
    'admin_emails' => array_filter(array_map('trim', explode(',', (string) env('ADMIN_EMAILS', '')))),
];
