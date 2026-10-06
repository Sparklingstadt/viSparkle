<?php

namespace App\Models;

use App\Enums\PostFileKind;
use Database\Factories\PostFileFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $post_id
 * @property PostFileKind $kind
 * @property string $path
 * @property string|null $thumb_path
 * @property string $original_name
 * @property string $mime
 * @property int $size
 * @property int|null $width
 * @property int|null $height
 * @property int $sort
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['kind', 'path', 'thumb_path', 'original_name', 'mime', 'size', 'width', 'height', 'sort'])]
class PostFile extends Model
{
    /** @use HasFactory<PostFileFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'kind' => PostFileKind::class,
            'size' => 'integer',
            'width' => 'integer',
            'height' => 'integer',
        ];
    }

    /** @return BelongsTo<Post, $this> */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }
}
