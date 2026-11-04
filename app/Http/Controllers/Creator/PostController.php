<?php

namespace App\Http\Controllers\Creator;

use App\Enums\PostFileKind;
use App\Enums\PostStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Creator\UpdatePostRequest;
use App\Models\Post;
use App\Models\PostCategory;
use App\Models\PostFile;
use App\Services\PostFileStorer;
use App\Services\PostFileUrls;
use App\Services\Tiptap\TiptapDocumentValidator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PostController extends Controller
{
    public function __construct(
        private readonly PostFileStorer $storer,
        private readonly PostFileUrls $urls,
    ) {}

    public function index(Request $request): Response
    {
        $posts = $request->user()->creator->posts()
            ->latest('updated_at')
            ->paginate(20)
            ->through(fn (Post $post) => [
                'id' => $post->id,
                'title' => $post->title,
                'status' => $post->status,
                'required_price' => $post->required_price,
                'published_at' => $post->published_at,
                'updated_at' => $post->updated_at,
                'hidden' => $post->isHidden(),
                'like_count' => $post->like_count,
            ]);

        return Inertia::render('creator/posts/index', ['posts' => $posts]);
    }

    public function store(Request $request): RedirectResponse
    {
        $post = $request->user()->creator->posts()->create([
            'title' => '無題',
            'status' => PostStatus::Draft,
            'required_price' => 0,
        ]);

        return to_route('creator.posts.edit', $post);
    }

    public function edit(Request $request, Post $post): Response
    {
        $this->authorizeOwner($request, $post);

        $files = $post->files()->orderBy('sort')->orderBy('id')->get();

        return Inertia::render('creator/posts/edit', [
            'post' => [
                'id' => $post->id,
                'title' => $post->title,
                'excerpt' => $post->excerpt,
                'body' => $post->body,
                'required_price' => $post->required_price,
                'status' => $post->status,
                'published_at' => $post->published_at,
                'cover_file_id' => $post->cover_file_id,
                'category_id' => $post->post_category_id,
                'hidden' => $post->isHidden(),
                'hidden_reason' => $post->hidden_reason,
                'images' => $files->where('kind', PostFileKind::Image)->values()->map(fn (PostFile $file) => $this->fileData($file)),
                'attachments' => $files->where('kind', PostFileKind::Attachment)->values()->map(fn (PostFile $file) => $this->fileData($file)),
            ],
            'priceOptions' => UpdatePostRequest::priceOptions($post),
            'categories' => self::categoryOptions($post->post_category_id),
            'creatorSlug' => $request->user()->creator->slug,
        ]);
    }

    public function update(UpdatePostRequest $request, Post $post): RedirectResponse
    {
        $this->authorizeOwner($request, $post);

        $action = $request->validated('action');
        $title = trim((string) $request->validated('title'));
        $excerpt = trim((string) $request->validated('excerpt'));

        $post->fill([
            'title' => $title === '' ? '無題' : $title,
            'excerpt' => $excerpt === '' ? null : $excerpt,
            'body' => $request->validated('body'),
            'required_price' => $request->validated('required_price'),
            'cover_file_id' => $request->validated('cover_file_id'),
            // Empty-string conversion is off for this endpoint (see bootstrap/app.php), so the form's "未設定" arrives as ''.
            'post_category_id' => $request->validated('post_category_id') ?: null,
        ]);

        if ($action === 'publish') {
            $post->status = PostStatus::Published;
            $post->published_at ??= now();
        } elseif ($action === 'unpublish') {
            $post->status = PostStatus::Draft;
        }

        $post->save();

        $this->pruneUnreferencedImages($post);

        Inertia::flash('toast', ['type' => 'success', 'message' => match ($action) {
            'publish' => '投稿を公開しました。',
            'unpublish' => '投稿を非公開にしました。',
            default => '保存しました。',
        }]);

        return to_route('creator.posts.edit', $post);
    }

    public function destroy(Request $request, Post $post): RedirectResponse
    {
        $this->authorizeOwner($request, $post);

        DB::transaction(function () use ($post) {
            $post->update(['cover_file_id' => null]);
            $post->files()->get()->each(fn (PostFile $file) => $this->storer->delete($file));
            $post->delete();
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => '投稿を削除しました。']);

        return to_route('creator.posts.index');
    }

    /**
     * Active categories in order; a post whose category was archived since keeps it, listed last.
     *
     * @return list<array{id: int, name: string, archived: bool}>
     */
    private static function categoryOptions(?int $currentId): array
    {
        $options = PostCategory::query()->active()->ordered()->get()
            ->map(fn (PostCategory $category) => ['id' => $category->id, 'name' => $category->name, 'archived' => false])
            ->all();

        $current = $currentId === null || in_array($currentId, array_column($options, 'id'), true)
            ? null
            : PostCategory::query()->find($currentId);

        if ($current !== null) {
            $options[] = ['id' => $current->id, 'name' => $current->name, 'archived' => true];
        }

        return array_values($options);
    }

    private function pruneUnreferencedImages(Post $post): void
    {
        $keep = app(TiptapDocumentValidator::class)->imageIds($post->body ?? []);

        if ($post->cover_file_id !== null) {
            $keep[] = $post->cover_file_id;
        }

        $post->images()->whereNotIn('id', $keep)->get()->each(fn (PostFile $file) => $this->storer->delete($file));
    }

    /**
     * @return array<string, mixed>
     */
    private function fileData(PostFile $file): array
    {
        return [
            'id' => $file->id,
            'kind' => $file->kind,
            'url' => $this->urls->url($file),
            'thumb_url' => $this->urls->thumbUrl($file),
            'original_name' => $file->original_name,
            'size' => $file->size,
        ];
    }

    private function authorizeOwner(Request $request, Post $post): void
    {
        abort_unless($request->user()->can('manage', $post), 404);
    }
}
