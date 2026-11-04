<?php

namespace App\Presenters;

use App\Enums\PostFileKind;
use App\Models\Post;
use App\Models\PostFile;
use App\Services\Entitlement;
use App\Services\PostFileUrls;

/**
 * Builds the props a viewer may receive for a post.
 *
 * Gated data (body, file URLs other than the cover thumbnail) is only ever
 * generated after Entitlement::canView() passes; for a locked post the keys
 * are absent rather than empty. Expects `creator` and `cover` eager loaded.
 *
 * $likedIds is the set of post ids the viewer liked (PostLike::idsLikedBy), fetched once per page.
 */
class PostPresenter
{
    public function __construct(private readonly PostFileUrls $urls) {}

    /**
     * @param  array<int, true>  $likedIds
     * @return array<string, mixed>
     */
    public function summary(Post $post, Entitlement $entitlement, array $likedIds = []): array
    {
        return [
            'id' => $post->id,
            'title' => $post->title,
            'excerpt' => $post->excerpt,
            'required_price' => $post->required_price,
            'published_at' => $post->published_at,
            'cover_thumb_url' => $post->cover === null ? null : $this->urls->thumbUrl($post->cover),
            'locked' => ! $entitlement->canView($post),
            'like_count' => $post->like_count,
            'liked' => isset($likedIds[$post->id]),
            'can_like' => $entitlement->canLike($post),
            'creator' => $post->creator->toSummary(),
        ];
    }

    /**
     * @param  array<int, true>  $likedIds
     * @return array<string, mixed>
     */
    public function detail(Post $post, Entitlement $entitlement, array $likedIds = []): array
    {
        $summary = [
            ...$this->summary($post, $entitlement, $likedIds),
            'updated_at' => $post->updated_at,
        ];

        if ($summary['locked']) {
            return $summary;
        }

        $files = $post->files()->orderBy('sort')->orderBy('id')->get();

        return [
            ...$summary,
            'body' => $post->body,
            'image_urls' => (object) $files->where('kind', PostFileKind::Image)
                ->mapWithKeys(fn (PostFile $file) => [$file->id => $this->urls->url($file)])
                ->all(),
            'attachments' => $files->where('kind', PostFileKind::Attachment)->values()
                ->map(fn (PostFile $file) => [
                    'id' => $file->id,
                    'original_name' => $file->original_name,
                    'size' => $file->size,
                    'url' => route('posts.files.show', [$post->creator, $post, $file]),
                ]),
        ];
    }
}
