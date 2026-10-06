<?php

namespace Database\Factories;

use App\Enums\PostFileKind;
use App\Models\Post;
use App\Models\PostFile;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PostFile>
 */
class PostFileFactory extends Factory
{
    public function definition(): array
    {
        $ulid = Str::lower((string) Str::ulid());

        return [
            'post_id' => Post::factory(),
            'kind' => PostFileKind::Image,
            'path' => "creators/0/posts/0/{$ulid}.jpg",
            'thumb_path' => "creators/0/posts/0/{$ulid}_thumb.jpg",
            'original_name' => 'image.jpg',
            'mime' => 'image/jpeg',
            'size' => 1024,
            'width' => 800,
            'height' => 600,
            'sort' => 0,
        ];
    }

    public function attachment(): static
    {
        return $this->state(function () {
            $ulid = Str::lower((string) Str::ulid());

            return [
                'kind' => PostFileKind::Attachment,
                'path' => "creators/0/posts/0/{$ulid}.zip",
                'thumb_path' => null,
                'original_name' => 'files.zip',
                'mime' => 'application/zip',
                'width' => null,
                'height' => null,
            ];
        });
    }
}
