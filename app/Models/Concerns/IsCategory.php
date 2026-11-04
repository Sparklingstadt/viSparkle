<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * Shared behaviour of the product and post categories: an ordered, archivable list.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $sort_order
 * @property Carbon|null $archived_at
 */
trait IsCategory
{
    /** @return array<string, string> */
    protected function categoryCasts(): array
    {
        return [
            'sort_order' => 'integer',
            'archived_at' => 'datetime',
        ];
    }

    /** @param  Builder<static>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('archived_at');
    }

    /** @param  Builder<static>  $query */
    #[Scope]
    protected function ordered(Builder $query): void
    {
        $query->orderBy('sort_order')->orderBy('id');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /** A new category goes last. */
    public static function nextSortOrder(): int
    {
        $max = static::query()->max('sort_order');

        return $max === null ? 0 : (int) $max + 1;
    }

    /**
     * Active categories in display order, shared by the home, search and category pages.
     *
     * @return list<array{id: int, name: string, slug: string}>
     */
    public static function activeSummaries(): array
    {
        return array_values(static::query()->active()->ordered()->get()->map->toSummary()->all());
    }

    /**
     * @return array{id: int, name: string, slug: string}
     */
    public function toSummary(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'slug' => $this->slug];
    }
}
