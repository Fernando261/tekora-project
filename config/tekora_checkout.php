<?php

// B3.3: explicitly opt-in synthetic payment choices on the LOCAL development
// installation. Never use for real banking activity or on a public server.
return [
    'local_demo_payments' => filter_var(env('TEKORA_LOCAL_DEMO_PAYMENTS', false), FILTER_VALIDATE_BOOLEAN),
];
