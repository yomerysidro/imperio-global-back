<?php

return [
    // Enable only when every recovery PHP worker uses the same persistent local
    // cache and lock directories, preserved across releases (single server).
    'file_cache_shared' => env('PASSWORD_RECOVERY_FILE_CACHE_SHARED', false),
];
