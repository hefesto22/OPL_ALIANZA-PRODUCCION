<?php

namespace App\Listeners;

use Illuminate\Auth\Events\Login;

class RecordUserLogin
{
    /**
     * Handle the event.
     */
    public function handle(Login $event): void
    {
        /** @var \App\Models\User $user */
        $user = $event->user;
        $user->recordLogin(
            request()->ip()
        );
    }
}
