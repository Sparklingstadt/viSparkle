<?php

namespace App\Models;

use App\Enums\SupportStatus;
use Database\Factories\CreatorFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $user_id
 * @property string $slug
 * @property string $display_name
 * @property string|null $bio
 * @property string|null $avatar_path
 * @property string|null $header_path
 * @property string|null $stripe_account_id
 * @property bool $payouts_enabled
 * @property bool $details_submitted
 * @property Carbon|null $closed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['slug', 'display_name', 'bio', 'avatar_path', 'header_path'])]
class Creator extends Model
{
    /** @use HasFactory<CreatorFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payouts_enabled' => 'boolean',
            'details_submitted' => 'boolean',
            'closed_at' => 'datetime',
        ];
    }

    /** @param  Builder<self>  $query */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('closed_at')
            ->whereHas('user', fn ($q) => $q->whereNull('suspended_at'));
    }

    /**
     * Open creators a fan can start supporting now: payouts are set up (canAcceptSupports) and a plan is accepting.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function supportable(Builder $query): void
    {
        $query->open()
            ->whereNotNull('stripe_account_id')
            ->where('payouts_enabled', true)
            ->whereHas('plans', fn ($q) => $q->where('is_accepting', true));
    }

    /**
     * Open creators ranked by fans who started (and still keep) a support in the last 7 days.
     * One fan holds at most one active support per creator, so counting supports counts fans.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function weeklyPopular(Builder $query): void
    {
        $newThisWeek = fn ($q) => $q->where('status', SupportStatus::Active)
            ->where('started_at', '>=', now()->subDays(7))
            ->whereColumn('supports.user_id', '!=', 'creators.user_id');

        $query->open()
            ->whereHas('supports', $newThisWeek)
            ->withCount([
                'supports as weekly_supporters_count' => $newThisWeek,
                'supports as active_supporters_count' => fn ($q) => $q->where('status', SupportStatus::Active),
            ])
            ->orderByDesc('weekly_supporters_count')
            ->orderByDesc('active_supporters_count')
            ->orderByDesc('id');
    }

    public function isClosed(): bool
    {
        return $this->closed_at !== null;
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * Resolve by exact slug: MySQL's default collation is case-insensitive, so "/@Alice" would otherwise match "alice".
     * A creator whose owner is suspended does not exist for any route (every {creator} route is public or fan-facing).
     */
    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $creator = parent::resolveRouteBinding($value, $field);

        if (! $creator instanceof self) {
            return $creator;
        }

        if (($field ?? $this->getRouteKeyName()) === 'slug' && $creator->slug !== $value) {
            return null;
        }

        return $creator->user->isSuspended() ? null : $creator;
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar_path === null ? null : Storage::disk('public')->url($this->avatar_path);
    }

    public function headerUrl(): ?string
    {
        return $this->header_path === null ? null : Storage::disk('public')->url($this->header_path);
    }

    /**
     * @return array{slug: string, display_name: string, avatar_url: string|null}
     */
    public function toSummary(): array
    {
        return ['slug' => $this->slug, 'display_name' => $this->display_name, 'avatar_url' => $this->avatarUrl()];
    }

    public function canAcceptSupports(): bool
    {
        return $this->stripe_account_id !== null && (bool) $this->payouts_enabled;
    }

    /**
     * Idempotent: following twice keeps one row.
     */
    public function follow(User $user): void
    {
        Follow::query()->insertOrIgnore([
            'user_id' => $user->id,
            'creator_id' => $this->id,
            'created_at' => now(),
        ]);
    }

    public function unfollow(User $user): void
    {
        $this->follows()->where('user_id', $user->id)->delete();
    }

    public function isFollowedBy(?User $user): bool
    {
        return $user !== null && $this->follows()->where('user_id', $user->id)->exists();
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

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Follow, $this> */
    public function follows(): HasMany
    {
        return $this->hasMany(Follow::class);
    }
}
