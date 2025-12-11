<?php

use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast Channels
|--------------------------------------------------------------------------
|
| Here you may register all of the event broadcasting channels that your
| application supports. The given channel authorization callbacks are
| used to check if an authenticated user can listen to the channel.
|
*/

// Private channel for user-specific events (balance updates, order matches)
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

// Public channel for orderbook updates (anyone can listen)
Broadcast::channel('orderbook.{symbol}', function () {
    return true;
});

// Security alerts channel (admin only)
Broadcast::channel('security-alerts', function ($user) {
    return in_array($user->id, config('attack_detection.alerts.admin_user_ids'));
});

// Individual admin security channel
Broadcast::channel('admin.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id &&
           in_array($user->id, config('attack_detection.alerts.admin_user_ids'));
});
