<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property int $user_id
 * @property Carbon|null $created_at
 */
class PostLike extends Model
{
    public const UPDATED_AT = null;

    /**
     * Ids among $postIds that the viewer has liked, as a lookup set.
     *
     * @param  iterable<int>  $postIds
     * @return array<int, true>
     */
    public static function idsLikedBy(?User $viewer, iterable $postIds): array
    {
        $postIds = collect($postIds)->all();

        if ($viewer === null || $postIds === []) {
            return [];
        }

        $ids = static::query()
            ->where('user_id', $viewer->id)
            ->whereIn('post_id', $postIds)
            ->pluck('post_id')
            ->all();

        return array_fill_keys($ids, true);
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
