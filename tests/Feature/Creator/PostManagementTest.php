<?php

use App\Enums\PostFileKind;
use App\Enums\PostStatus;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\PostFile;
use App\Models\Support;
use App\Services\PostFileStorer;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakePrivateDisk();
    $this->creator = Creator::factory()->create();
    Plan::factory()->for($this->creator)->price(500)->create();
    $this->actingAs($this->creator->user);
});

function storedImage(Post $post): PostFile
{
    return app(PostFileStorer::class)->store($post, jpegWithExif(400, 300), PostFileKind::Image);
}

function savePayload(array $overrides = []): array
{
    return ['action' => 'save', 'title' => 'タイトル', 'excerpt' => null, 'body' => null, 'required_price' => 0, 'cover_file_id' => null, ...$overrides];
}

it('lists own posts only', function () {
    Post::factory()->for($this->creator)->count(2)->create();
    Post::factory()->create();

    $this->get(route('creator.posts.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('creator/posts/index')->has('posts.data', 2));
});

it('filters own posts by status and counts each status', function () {
    Post::factory()->for($this->creator)->published()->count(2)->create();
    $draft = Post::factory()->for($this->creator)->draft()->create();
    Post::factory()->draft()->create();

    $this->get(route('creator.posts.index', ['status' => 'draft']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('status', 'draft')
            ->has('posts.data', 1)
            ->where('posts.data.0.id', $draft->id)
            ->where('counts', ['all' => 3, 'published' => 2, 'draft' => 1]));

    $this->get(route('creator.posts.index', ['status' => 'bogus']))
        ->assertInertia(fn (Assert $page) => $page->where('status', null)->has('posts.data', 3));
});

it('creates an empty draft and redirects to the editor', function () {
    $response = $this->post(route('creator.posts.store'));

    $post = $this->creator->posts()->sole();
    $response->assertRedirect(route('creator.posts.edit', $post));
    expect($post->status)->toBe(PostStatus::Draft)->and($post->title)->toBe('無題');
});

it('shows the editor with price options', function () {
    $post = Post::factory()->for($this->creator)->create();
    $image = storedImage($post);

    $this->get(route('creator.posts.edit', $post))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('creator/posts/edit')
            ->where('post.id', $post->id)
            ->where('post.images.0.id', $image->id)
            ->where('priceOptions', [0, 500]));
});

it('saves a draft', function () {
    $post = Post::factory()->for($this->creator)->create();

    $this->put(route('creator.posts.update', $post), savePayload(['title' => '下書き', 'excerpt' => 'あらすじ']))
        ->assertRedirect(route('creator.posts.edit', $post));

    expect($post->fresh())->title->toBe('下書き')->excerpt->toBe('あらすじ')->status->toBe(PostStatus::Draft);
});

it('falls back to 無題 for an empty draft title', function () {
    $post = Post::factory()->for($this->creator)->create();

    $this->put(route('creator.posts.update', $post), savePayload(['title' => '']));

    expect($post->fresh()->title)->toBe('無題');
});

it('requires a title to publish', function () {
    $post = Post::factory()->for($this->creator)->create();

    $this->put(route('creator.posts.update', $post), savePayload(['action' => 'publish', 'title' => '']))
        ->assertSessionHasErrors('title');
});

it('records published_at only on first publish', function () {
    $post = Post::factory()->for($this->creator)->create();

    $this->travelTo(now()->subDay());
    $this->put(route('creator.posts.update', $post), savePayload(['action' => 'publish']));
    $first = $post->fresh()->published_at;
    $this->travelBack();

    $this->put(route('creator.posts.update', $post), savePayload(['action' => 'unpublish']));
    expect($post->fresh()->status)->toBe(PostStatus::Draft);

    $this->put(route('creator.posts.update', $post), savePayload(['action' => 'publish']));
    expect($post->fresh()->status)->toBe(PostStatus::Published)
        ->and($post->fresh()->published_at->equalTo($first))->toBeTrue();
});

it('validates required_price against own plans', function (int $price, bool $ok) {
    $post = Post::factory()->for($this->creator)->create();

    $response = $this->put(route('creator.posts.update', $post), savePayload(['required_price' => $price]));

    $ok ? $response->assertSessionHasNoErrors() : $response->assertSessionHasErrors('required_price');
})->with([
    'free' => [0, true],
    'own plan' => [500, true],
    'not a plan' => [300, false],
]);

it('tells the editor which plans and how many supporters each audience covers', function () {
    $this->creator->plans()->first()->update(['name' => 'しっかり応援']);
    $small = Plan::factory()->for($this->creator)->price(300)->create(['name' => 'ちょっと応援']);
    Support::factory()->forPlan($small)->active()->create();
    Support::factory()->forPlan($this->creator->plans()->where('price', 500)->firstOrFail())->active()->count(2)->create();
    Support::factory()->forPlan($small)->ended()->create();
    $post = Post::factory()->for($this->creator)->create();

    $this->get(route('creator.posts.edit', $post))
        ->assertInertia(fn (Assert $page) => $page->where('audiences', [
            ['price' => 0, 'plans' => [], 'readers' => null],
            ['price' => 300, 'plans' => ['ちょっと応援'], 'readers' => 3],
            ['price' => 500, 'plans' => ['しっかり応援'], 'readers' => 2],
        ]));
});

it('keeps an existing required_price selectable after its plan is gone', function () {
    $post = Post::factory()->for($this->creator)->requiring(500)->create();
    $this->creator->plans()->delete();

    $this->put(route('creator.posts.update', $post), savePayload(['required_price' => 500]))->assertSessionHasNoErrors();
    $this->get(route('creator.posts.edit', $post))->assertInertia(fn (Assert $page) => $page->where('priceOptions', [0, 500]));
});

it('validates the body', function () {
    $post = Post::factory()->for($this->creator)->create();

    $this->put(route('creator.posts.update', $post), savePayload(['body' => ttDoc(['type' => 'iframe'])]))
        ->assertSessionHasErrors('body');
});

it('rejects a body referencing another posts image', function () {
    $post = Post::factory()->for($this->creator)->create();
    $foreign = storedImage(Post::factory()->for($this->creator)->create());

    $this->put(route('creator.posts.update', $post), savePayload(['body' => ttDoc(ttImage($foreign->id))]))
        ->assertSessionHasErrors('body');
});

it('rejects a cover that is not this posts image', function () {
    $post = Post::factory()->for($this->creator)->create();
    $foreign = storedImage(Post::factory()->for($this->creator)->create());

    $this->put(route('creator.posts.update', $post), savePayload(['cover_file_id' => $foreign->id]))
        ->assertSessionHasErrors('cover_file_id');
});

it('prunes images no longer referenced but keeps the cover and attachments', function () {
    $post = Post::factory()->for($this->creator)->create();
    [$kept, $dropped, $cover] = [storedImage($post), storedImage($post), storedImage($post)];
    $attachment = PostFile::factory()->for($post)->attachment()->create();

    $this->put(route('creator.posts.update', $post), savePayload([
        'body' => ttDoc(ttImage($kept->id)),
        'cover_file_id' => $cover->id,
    ]))->assertSessionHasNoErrors();

    expect($post->files()->pluck('id')->sort()->values()->all())
        ->toBe(collect([$kept->id, $cover->id, $attachment->id])->sort()->values()->all())
        ->and($post->fresh()->cover_file_id)->toBe($cover->id);
    Storage::disk('private')->assertMissing($dropped->path);
});

it('deletes a post with its files', function () {
    $post = Post::factory()->for($this->creator)->create();
    $image = storedImage($post);

    $this->delete(route('creator.posts.destroy', $post))->assertRedirect(route('creator.posts.index'));

    expect(Post::find($post->id))->toBeNull();
    Storage::disk('private')->assertMissing([$image->path, $image->thumb_path]);
});

it('returns 404 for another creators post', function (string $method, string $route) {
    $post = Post::factory()->create();

    $this->{$method}(route($route, $post), savePayload())->assertNotFound();
})->with([
    'edit' => ['get', 'creator.posts.edit'],
    'update' => ['put', 'creator.posts.update'],
    'destroy' => ['delete', 'creator.posts.destroy'],
    'upload' => ['post', 'creator.posts.files.store'],
]);

it('preserves whitespace in body text nodes', function () {
    $post = Post::factory()->for($this->creator)->create();
    $body = ttDoc(ttParagraph(
        ttText('太字', [['type' => 'bold']]),
        ttText(' '),
        ttText('斜体', [['type' => 'italic']]),
        ttText(' and more '),
    ));

    $this->put(route('creator.posts.update', $post), savePayload(['body' => $body, 'title' => '  タイトル  ', 'excerpt' => '  抜粋  ']))
        ->assertSessionHasNoErrors();

    expect($post->fresh())->body->toEqual($body)->title->toBe('タイトル')->excerpt->toBe('抜粋');
});
