<?php

use Illuminate\Support\Facades\Broadcast;

// ✅ This creates POST /broadcasting/auth
Broadcast::routes([
    'middleware' => ['auth:client'], // IMPORTANT: your guard
]);