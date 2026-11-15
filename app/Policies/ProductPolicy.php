<?php

namespace App\Policies;

use App\Models\Product;
use App\Models\User;

class ProductPolicy
{
    /**
     * Only the product's own creator edits it; callers answer anyone else with 404 so its existence stays hidden.
     */
    public function manage(User $user, Product $product): bool
    {
        return $product->creator->user_id === $user->id;
    }
}
