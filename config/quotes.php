<?php

return [
    // Quote validity only. No slot hold or payment is created.
    'lifetime_seconds' => env('TEKORA_QUOTE_TTL_SECONDS', 1800),
];
