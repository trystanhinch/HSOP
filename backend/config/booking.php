<?php

return [
    /*
    | Soft hold TTL — slot is reserved during intake until confirm or expiry.
    */
    'hold_ttl_seconds' => (int) env('BOOKING_HOLD_TTL_SECONDS', 600),

    /*
    | How many days ahead to offer slots on the public availability API.
    */
    'availability_horizon_days' => (int) env('BOOKING_HORIZON_DAYS', 14),

    /*
    | Default timezone for windows / display when brand does not override.
    | Site-visit date formatting already treats calendar dates carefully (Pacific-safe).
    */
    'default_timezone' => env('BOOKING_TIMEZONE', 'America/Vancouver'),
];
