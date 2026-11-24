<?php

use Illuminate\Support\Facades\Broadcast;

// Each user's private channel; only that user may subscribe.
Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});
