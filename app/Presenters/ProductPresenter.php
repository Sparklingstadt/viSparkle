<?php

namespace App\Presenters;

use App\Models\Product;
use App\Models\User;

/**
 * Builds the props of a product card. Expects the relations of Product::forCard() eager loaded.
 *
 * $likedIds is the set of product ids the viewer liked (ProductLike::idsLikedBy), fetched once per page.
 */
class ProductPresenter
{
    /**
     * @param  array<int, true>  $likedIds
     * @return array<string, mixed>
     */
    public function card(Product $product, ?User $viewer, array $likedIds = []): array
    {
        return [
            ...$product->toSummary(),
            'creator' => $product->creator->toSummary(),
            'liked' => isset($likedIds[$product->id]),
            // A creator can't like their own goods.
            'can_like' => $product->creator->user_id !== $viewer?->id,
        ];
    }
}
