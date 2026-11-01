<?php

namespace App\Models;

use App\Enums\PostFileKind;
use App\Enums\PostStatus;
use Carbon\CarbonInterface;
use Database\Factories\PostFactory;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

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
 * @property int $view_count
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
            'view_count' => 'integer',
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

    /**
     * Most viewed among posts published in the last 30 days, so old hits don't hold the top forever.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function popular(Builder $query): void
    {
        $query->where('status', PostStatus::Published)
            ->where('published_at', '>=', now()->subDays(30))
            ->orderByDesc('view_count')
            ->orderByDesc('published_at')
            ->orderByDesc('id');
    }

    /**
     * Counts one view per post per session; the author's own views don't count.
     */
    public function recordView(?User $viewer, Session $session): void
    {
        if ($viewer?->id === $this->creator->user_id) {
            return;
        }

        $viewed = $session->get('viewed_posts', []);
        if (in_array($this->id, $viewed, true)) {
            return;
        }

        // Keep only recent ids so the session stays small.
        $session->put('viewed_posts', array_slice([...$viewed, $this->id], -200));
        // Bypass Eloquent so a view doesn't bump updated_at.
        static::whereKey($this->id)->toBase()->increment('view_count');
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function visible(Builder $query): void
    {
        $query->whereNull('hidden_at');
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

    /** @return BelongsTo<PostFile, $this> */
    public function cover(): BelongsTo
    {
        return $this->belongsTo(PostFile::class, 'cover_file_id');
    }
}
