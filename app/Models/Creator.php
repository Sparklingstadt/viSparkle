<?php

namespace App\Models;

use Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $slug
 * @property string $display_name
 * @property string|null $bio
 * @property string|null $avatar_path
 * @property string|null $header_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'display_name', 'bio', 'avatar_path', 'header_path'])]
class Creator extends Model
{
    /** @use HasFactory<CreatorFactory> */
    use HasFactory;

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve by exact slug: MySQL's default collation is case-insensitive, so "/@Alice" would otherwise match "alice".
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $creator = parent::resolveRouteBinding($value, $field);

        return $creator !== null && ($field ?? $this->getRouteKeyName()) === 'slug' && $creator->slug !== $value
            ? null
            : $creator;
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<Plan, $this> */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class)->orderBy('sort')->orderBy('price');
    }

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<Support, $this> */
    public function supports(): HasMany
    {
        return $this->hasMany(Support::class);
    }
}
