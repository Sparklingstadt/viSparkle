<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    public function manage(User $user, Plan $plan): bool
    {
        return $user->isCreator();
    }
}
