<?php

namespace App\Policies;

use App\Models\Post;
use App\Models\User;
use App\Services\Entitlement;

class PostPolicy
{
    public function view(?User $user, Post $post): bool
    {
        return Entitlement::for($user)->canView($post);
    }

    public function manage(User $user, Post $post): bool
    {
        return Entitlement::for($user)->isAuthorOf($post);
    }
}
