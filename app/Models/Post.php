<?php

namespace App\Models;

use App\Enums\PostFileKind;
use App\Enums\PostStatus;
use Database\Factories\PostFactory;
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
 * @property Carbon|null $published_at
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
