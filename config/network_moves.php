<?php

return [
    // Both gates must be enabled explicitly after isolated MySQL validation.
    'backend_certified' => env('NETWORK_MOVE_BACKEND_CERTIFIED', false),
    'confirmation_enabled' => env('NETWORK_MOVE_CONFIRMATION_ENABLED', false),
    'local_testing_enabled' => env('NETWORK_MOVE_LOCAL_TESTING_ENABLED', false),
];
