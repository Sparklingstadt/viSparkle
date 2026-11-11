<?php

namespace Database\Seeders;

use App\Enums\PostFileKind;
use App\Models\Creator;
use App\Models\Post;
use App\Services\PostFileStorer;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * Gives every demo post without a cover one drawn by DemoProductPainter in its creator's colours and motif. The image
 * goes through PostFileStorer like an upload, so it lands on the private disk with its thumbnail.
 *
 * The colours are read back from the creator's demo header (a left-to-right gradient of the two), which also covers
 * the creators of DemoCrowdSeeder. Safe to re-run: posts that have a cover are skipped.
 */
class DemoPostCoversSeeder extends Seeder
{
    public function run(PostFileStorer $storer): void
    {
        Post::whereNull('cover_file_id')
            ->whereHas('creator', fn ($query) => $query->whereNotNull('header_path'))
            ->with('creator')
            ->lazyById()
            ->each(function (Post $post) use ($storer) {
                $painter = new DemoProductPainter($post->creator->slug, $this->palette($post->creator));
                $path = tempnam(sys_get_temp_dir(), 'cover');
                file_put_contents($path, $painter->cover($post->title));

                try {
                    $file = $storer->store($post, new UploadedFile($path, 'cover.png', 'image/png', null, true), PostFileKind::Image);
                    $post->update(['cover_file_id' => $file->id]);
                } finally {
                    @unlink($path);
                }
            });
    }

    /**
     * The two colours of the creator's header, sampled at its top corners (the soft circles stay clear of them).
     *
     * @return array{string, string}
     */
    private function palette(Creator $creator): array
    {
        $image = @imagecreatefromstring((string) Storage::disk('public')->get((string) $creator->header_path));

        if ($image === false) {
            return ['#6d28d9', '#f9a8d4'];
        }

        $hex = function (int $x) use ($image): string {
            $rgb = imagecolorat($image, $x, 2);

            return sprintf('#%02x%02x%02x', ($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF);
        };

        return [$hex(2), $hex(imagesx($image) - 3)];
    }
}
