<?php

return [
    'vendor_registration_fee' => (float) env('VENDOR_REGISTRATION_FEE', 5000),
    'gst_pct' => (float) env('SCRAPIFY_GST_PCT', 18),
    'tcs_pct' => (float) env('SCRAPIFY_TCS_PCT', 1),
    'payment_window_hours' => (int) env('SCRAPIFY_PAYMENT_WINDOW_HOURS', 48),
    'frontend_url' => rtrim((string) env('FRONTEND_URL', 'https://scrapifyauctions.com'), '/'),
];
