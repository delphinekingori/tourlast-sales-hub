<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
| Each person listens only to their own private channel, the same one Laravel
| uses for database notifications.
*/
Broadcast::channel('App.Models.User.{id}', fn (User $user, string $id): bool => (int) $user->id === (int) $id);
