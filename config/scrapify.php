<?php

return [
    // Tax and fee percentages used by the H1 Summary report. Sourced from the
    // admin panel's PDF generator (src/lib/h1-report.ts).
    'gst_pct' => env('SCRAPIFY_GST_PCT', 18),
    'tcs_pct' => env('SCRAPIFY_TCS_PCT', 1),
    'sta_pct' => env('SCRAPIFY_STA_PCT', 3),

    // Hours a winner has to settle the balance before the order lapses.
    'payment_window_hours' => env('SCRAPIFY_PAYMENT_WINDOW_HOURS', 48),
];
