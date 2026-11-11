<?php

namespace App\Policies;

use App\Models\Address;
use App\Models\User;

class AddressPolicy
{
    public function manage(User $user, Address $address): bool
    {
        return $address->user_id === $user->id;
    }
}
