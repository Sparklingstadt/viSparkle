<?php

namespace App\Services;

use App\Enums\SupportStatus;
use App\Models\Creator;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;

/**
 * The single source of truth for "who may see which post".
 *
 * A post is visible to its author, or when published and either free or the
 * viewer's active support for that creator is at least the required price.
 * The support's price snapshot is used, so changing a plan never changes
 * existing supporters' access.
 */
final class Entitlement
{
    /**
     * @param  array<int, int>  $supportPrices  creator_id => support price snapshot
     */
    private function __construct(
        private readonly ?int $viewerCreatorId,
        private readonly array $supportPrices,
    ) {}

    public static function for(?User $viewer): self
    {
        if ($viewer === null) {
            return new self(null, []);
        }

        // One query: the viewer's active supports plus their own creator id (own = 1).
        $rows = Support::query()
            ->select(['creator_id', 'price'])
            ->selectRaw('0 as own')
            ->where('user_id', $viewer->id)
            ->where('status', SupportStatus::Active)
            ->unionAll(
                Creator::query()->select(['id as creator_id'])->selectRaw('0 as price, 1 as own')->where('user_id', $viewer->id)
            )
            ->toBase()
            ->get();

        $ownCreatorId = null;
        $prices = [];

        foreach ($rows as $row) {
            if ((int) $row->own === 1) {
                $ownCreatorId = (int) $row->creator_id;
            } else {
                $prices[(int) $row->creator_id] = max((int) $row->price, $prices[(int) $row->creator_id] ?? 0);
            }
        }

        return new self($ownCreatorId, $prices);
    }

    public function canView(Post $post): bool
    {
        if ($this->isAuthorOf($post)) {
            return true;
        }

        if (! $post->isPublished()) {
            return false;
        }

        if ($post->required_price === 0) {
            return true;
        }

        $price = $this->supportPriceFor($post->creator_id);

        return $price !== null && $price >= $post->required_price;
    }

    public function isAuthorOf(Post $post): bool
    {
        return $this->viewerCreatorId !== null && $post->creator_id === $this->viewerCreatorId;
    }

    public function supportPriceFor(int $creatorId): ?int
    {
        return $this->supportPrices[$creatorId] ?? null;
    }
}
