<?php

return [
    'vendor_registration_fee' => (float) env('VENDOR_REGISTRATION_FEE', 5000),
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'https://scrapifyauctions.com'), '/'),
];
