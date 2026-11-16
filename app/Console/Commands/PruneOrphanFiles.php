<?php

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\PostFile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

#[Signature('visparkle:prune-orphan-files {--dry-run : Only report what would be deleted}')]
#[Description('Delete stored files that no database row references (older than 24 hours)')]
class PruneOrphanFiles extends Command
{
    private const GRACE_HOURS = 24;

    public function handle(): int
    {
        $deleted = $this->prune('private', 'posts', fn (array $paths) => PostFile::query()
            ->whereIn('path', $paths)->pluck('path')
            ->merge(PostFile::query()->whereIn('thumb_path', $paths)->pluck('thumb_path')));

        $deleted += $this->prune('public', 'plans', fn (array $paths) => Plan::query()
            ->whereIn('cover_path', $paths)->pluck('cover_path'));

        $this->info(($this->option('dry-run') ? 'Would delete' : 'Deleted')." {$deleted} orphan file(s).");

        return self::SUCCESS;
    }

    /**
     * @param  callable(list<string>): Collection<int, string>  $referenced
     */
    private function prune(string $diskName, string $directory, callable $referenced): int
    {
        $disk = Storage::disk($diskName);
        $cutoff = now()->subHours(self::GRACE_HOURS)->getTimestamp();
        $deleted = 0;

        foreach (array_chunk($disk->allFiles($directory), 500) as $paths) {
            $keep = array_flip($referenced($paths)->all());

            foreach ($paths as $path) {
                if (isset($keep[$path]) || $disk->lastModified($path) > $cutoff) {
                    continue;
                }

                $this->line("{$diskName}: {$path}", verbosity: 'v');

                if (! $this->option('dry-run')) {
                    $disk->delete($path);
                }

                $deleted++;
            }
        }

        return $deleted;
    }
}
