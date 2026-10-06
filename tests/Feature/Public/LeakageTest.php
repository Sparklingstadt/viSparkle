<?php

use App\Enums\PostFileKind;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\PostFileStorer;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakePrivateDisk();
    $this->creator = Creator::factory()->create();
    $this->plan = Plan::factory()->for($this->creator)->price(500)->create();
    $this->post = Post::factory()->for($this->creator)->published()->requiring(500)->create(['excerpt' => 'PUBLIC-EXCERPT']);

    $storer = app(PostFileStorer::class);
    $this->image = $storer->store($this->post, jpegWithExif(400, 300), PostFileKind::Image);
    $this->cover = $storer->store($this->post, jpegWithExif(400, 300), PostFileKind::Image);
    $this->attachment = $storer->store($this->post, UploadedFile::fake()->createWithContent('secret.zip', 'zip'), PostFileKind::Attachment);
    $this->post->update([
        'cover_file_id' => $this->cover->id,
        'body' => ttDoc(ttParagraph(ttText('SECRET-BODY-TEXT')), ttImage($this->image->id)),
    ]);
});

function propsJson($response): string
{
    return json_encode($response->viewData('page')['props'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}

function assertNoLeak(string $json, $test): void
{
    expect($json)->not->toContain('SECRET-BODY-TEXT')
        ->not->toContain($test->image->path)
        ->not->toContain($test->image->thumb_path)
        ->not->toContain($test->attachment->path)
        ->not->toContain('secret.zip')
        ->not->toContain($test->cover->path.'?');
}

it('hides body and files on the post page from non-supporters', function (?Closure $viewer) {
    $user = $viewer?->call($this);
    if ($user) {
        $this->actingAs($user);
    }

    $response = $this->get(route('posts.show', [$this->creator, $this->post]))->assertOk();

    assertNoLeak(propsJson($response), $this);
    expect(propsJson($response))->toContain('PUBLIC-EXCERPT')->toContain($this->cover->thumb_path);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('public/post')
        ->where('post.locked', true)
        ->missing('post.body')
        ->missing('post.image_urls')
        ->missing('post.attachments'));
})->with([
    'guest' => [null],
    'logged in, no support' => [fn () => User::factory()->create()],
    'lower support' => [function () {
        $user = User::factory()->create();
        Support::factory()->for($user)->forPlan(Plan::factory()->for($this->creator)->price(300)->create())->active()->create();

        return $user;
    }],
]);

it('hides body and files on the creator page list', function () {
    $response = $this->get(route('creators.show', $this->creator))->assertOk();

    assertNoLeak(propsJson($response), $this);
    $response->assertInertia(fn (Assert $page) => $page->where('posts.data.0.locked', true)->missing('posts.data.0.body'));
});

it('never includes post bodies in list pages even for supporters', function () {
    $user = User::factory()->create();
    Support::factory()->for($user)->forPlan($this->plan)->active()->create();

    $response = $this->actingAs($user)->get(route('creators.show', $this->creator))->assertOk();

    expect(propsJson($response))->not->toContain('SECRET-BODY-TEXT');
    $response->assertInertia(fn (Assert $page) => $page->where('posts.data.0.locked', false));
});

it('shows the full post to a qualifying supporter', function () {
    $user = User::factory()->create();
    Support::factory()->for($user)->forPlan($this->plan)->active()->create();

    $response = $this->actingAs($user)->get(route('posts.show', [$this->creator, $this->post]))->assertOk();

    expect(propsJson($response))->toContain('SECRET-BODY-TEXT');
    $response->assertInertia(fn (Assert $page) => $page
        ->where('post.locked', false)
        ->where('post.body.content.0.content.0.text', 'SECRET-BODY-TEXT')
        ->where("post.image_urls.{$this->image->id}", fn (string $url) => str_contains($url, $this->image->path))
        ->where('post.attachments.0.original_name', 'secret.zip')
        ->where('post.attachments.0.url', fn (string $url) => str_ends_with($url, '&cd=1')));
});

it('shows the full post to its author', function () {
    $this->actingAs($this->creator->user)
        ->get(route('posts.show', [$this->creator, $this->post]))
        ->assertInertia(fn (Assert $page) => $page->where('post.locked', false)->has('post.body'));
});
