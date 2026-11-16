<?php

namespace Database\Seeders;

use App\Enums\PostFileKind;
use App\Models\Post;
use App\Services\PostFileStorer;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;

/**
 * Gives every demo post without a cover one drawn by DemoCoverPainter. The image goes through PostFileStorer like an
 * upload, so it lands on the private disk with its thumbnail. Safe to re-run: posts that have a cover are skipped.
 */
class DemoPostCoversSeeder extends Seeder
{
    public function run(PostFileStorer $storer): void
    {
        $painter = new DemoCoverPainter(['#6d28d9', '#f9a8d4']);

        Post::whereNull('cover_file_id')
            ->lazyById()
            ->each(function (Post $post) use ($storer, $painter) {
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
}
