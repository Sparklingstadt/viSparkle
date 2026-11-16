<?php

namespace App\Services;

use App\Models\Post;
use App\Models\Support;
use App\Models\User;

/**
 * The single source of truth for "who may see which post".
 *
 * A post is visible to the creator, or when published and either free or the
 * viewer's active support is at least the required price.
 * The support's price snapshot is used, so changing a plan never changes
 * existing supporters' access.
 */
final class Entitlement
{
    private function __construct(
        private readonly bool $isCreator,
        private readonly ?int $supportPrice,
    ) {}

    public static function for(?User $viewer): self
    {
        if ($viewer === null) {
            return new self(false, null);
        }

        // A user holds at most one active support (supports.current_key), so this is that support's price.
        $price = Support::query()->active()->where('user_id', $viewer->id)->max('price');

        return new self($viewer->isCreator(), $price === null ? null : (int) $price);
    }

    public function canView(Post $post): bool
    {
        if ($this->isCreator) {
            return true;
        }

        if (! $post->isPublished()) {
            return false;
        }

        if ($post->required_price === 0) {
            return true;
        }

        return $this->supportPrice !== null && $this->supportPrice >= $post->required_price;
    }

    public function isCreator(): bool
    {
        return $this->isCreator;
    }

    /** The price of the viewer's active support, or null when they are not supporting. */
    public function supportPrice(): ?int
    {
        return $this->supportPrice;
    }
}
