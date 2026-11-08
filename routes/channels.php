<?php

use App\Models\Admin;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('admin.inbox', function ($user) {
    return $user instanceof Admin;
});
