<?php

namespace App\Support;

use App\Models\User;

class AdminAccess
{
    public function canAccess(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        $allowedEmails = (array) config('admin.allowed_emails', []);

        if ($allowedEmails === []) {
            return (bool) config('admin.allow_all_in_local', true)
                && app()->environment('local');
        }

        return in_array(strtolower($user->email), $allowedEmails, true);
    }
}
