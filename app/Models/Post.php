<?php

namespace App\Models;

use App\Enums\PostFileKind;
use App\Enums\PostStatus;
use Carbon\CarbonInterface;
use Database\Factories\PostFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $creator_id
 * @property string $title
 * @property array<string, mixed>|null $body
 * @property string|null $excerpt
 * @property int|null $cover_file_id
 * @property int $required_price
 * @property PostStatus $status
 * @property CarbonInterface|null $published_at
 * @property Carbon|null $hidden_at
 * @property string|null $hidden_reason
 * @property int $like_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['title', 'body', 'excerpt', 'cover_file_id', 'required_price', 'status', 'published_at'])]
class Post extends Model
{
    /** @use HasFactory<PostFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'body' => 'array',
            'required_price' => 'integer',
            'status' => PostStatus::class,
            'published_at' => 'datetime',
            'hidden_at' => 'datetime',
            'like_count' => 'integer',
        ];
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('status', PostStatus::Published)
            ->whereNotNull('published_at')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->whereNull('hidden_at');
    }

    /**
     * Idempotent: like_count only moves when the row is actually added.
     */
    public function like(User $user): void
    {
        DB::transaction(function () use ($user) {
            $added = PostLike::query()->insertOrIgnore([
                'post_id' => $this->id,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);

            if ($added > 0) {
                // Bypass Eloquent so a like doesn't bump updated_at.
                static::whereKey($this->id)->toBase()->increment('like_count');
            }
        });
    }

    /**
     * Idempotent: like_count only moves when the row is actually removed.
     */
    public function unlike(User $user): void
    {
        DB::transaction(function () use ($user) {
            if ($this->likes()->where('user_id', $user->id)->delete() > 0) {
                static::whereKey($this->id)->toBase()->decrement('like_count');
            }
        });
    }

    public function isHidden(): bool
    {
        return $this->hidden_at !== null;
    }

    public function isPublished(): bool
    {
        return $this->status === PostStatus::Published && $this->published_at !== null;
    }

    /** @return BelongsTo<Creator, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Creator::class);
    }

    /** @return HasMany<PostFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(PostFile::class);
    }

    /** @return HasMany<PostFile, $this> */
    public function images(): HasMany
    {
        return $this->files()->where('kind', PostFileKind::Image);
    }

    /** @return HasMany<PostFile, $this> */
    public function attachments(): HasMany
    {
        return $this->files()->where('kind', PostFileKind::Attachment)->orderBy('sort')->orderBy('id');
    }

    /** @return HasMany<PostLike, $this> */
    public function likes(): HasMany
    {
        return $this->hasMany(PostLike::class);
    }

    /** @return BelongsTo<PostFile, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(PostFile::class, 'cover_file_id');
    }
}
