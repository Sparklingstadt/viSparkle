<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\PostLike;
use App\Models\Support;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    fakePrivateDisk();
    $this->creator = Creator::factory()->create();
    $this->fan = User::factory()->create();
});

function likeUrl(Post $post): string
{
    return route('posts.like.store', [$post->creator, $post]);
}

it('likes a post once, however often it is sent', function () {
    $post = Post::factory()->for($this->creator)->published()->create();

    $this->actingAs($this->fan)->post(likeUrl($post))->assertRedirect();
    $this->actingAs($this->fan)->post(likeUrl($post))->assertRedirect();

    expect(PostLike::where('post_id', $post->id)->count())->toBe(1)
        ->and($post->fresh()->like_count)->toBe(1);
});

it('unlikes idempotently without going below zero', function () {
    $post = Post::factory()->for($this->creator)->published()->create();
    $post->like($this->fan);

    $this->actingAs($this->fan)->delete(likeUrl($post))->assertRedirect();
    $this->actingAs($this->fan)->delete(likeUrl($post))->assertRedirect();

    expect(PostLike::count())->toBe(0)
        ->and($post->fresh()->like_count)->toBe(0);
});

it('does not bump updated_at', function () {
    $post = Post::factory()->for($this->creator)->published()->create(['updated_at' => now()->subDay()]);
    $before = $post->updated_at->toDateTimeString();

    $this->actingAs($this->fan)->post(likeUrl($post));
    $this->actingAs($this->fan)->delete(likeUrl($post));

    expect($post->fresh()->updated_at->toDateTimeString())->toBe($before);
});

it('sends guests to the login page', function () {
    $post = Post::factory()->for($this->creator)->published()->create();

    $this->post(likeUrl($post))->assertRedirect(route('login'));
    expect(PostLike::count())->toBe(0);
});

it('requires a verified email', function () {
    $post = Post::factory()->for($this->creator)->published()->create();

    $this->actingAs(User::factory()->unverified()->create())->post(likeUrl($post))->assertRedirect(route('verification.notice'));
    expect(PostLike::count())->toBe(0);
});

it('forbids the author from liking their own post', function () {
    $post = Post::factory()->for($this->creator)->published()->create();

    $this->actingAs($this->creator->user)->post(likeUrl($post))->assertForbidden();
});

it('forbids liking a locked post but lets an old like be removed', function () {
    $post = Post::factory()->for($this->creator)->published()->requiring(500)->create();

    $this->actingAs($this->fan)->post(likeUrl($post))->assertForbidden();

    // Liked while supporting; the support has since ended.
    $post->like($this->fan);
    $this->actingAs($this->fan)->delete(likeUrl($post))->assertRedirect();
    expect($post->fresh()->like_count)->toBe(0);
});

it('lets a supporter on a high enough plan like a paid post', function () {
    $plan = Plan::factory()->for($this->creator)->price(500)->create();
    Support::factory()->forPlan($plan)->create(['user_id' => $this->fan->id]);
    $post = Post::factory()->for($this->creator)->published()->requiring(500)->create();

    $this->actingAs($this->fan)->post(likeUrl($post))->assertRedirect();
    expect($post->fresh()->like_count)->toBe(1);
});

it('404s hidden posts, drafts and the wrong creator', function () {
    $hidden = Post::factory()->for($this->creator)->published()->hidden()->create();
    $draft = Post::factory()->for($this->creator)->draft()->create();
    $post = Post::factory()->for($this->creator)->published()->create();
    $other = Creator::factory()->create();

    $this->actingAs($this->fan)->post(likeUrl($hidden))->assertNotFound();
    $this->actingAs($this->fan)->delete(likeUrl($hidden))->assertNotFound();
    $this->actingAs($this->fan)->post(likeUrl($draft))->assertNotFound();
    $this->actingAs($this->fan)->post(route('posts.like.store', [$other, $post]))->assertNotFound();
});

it('shows the count to everyone', function () {
    $cheap = Plan::factory()->for($this->creator)->price(100)->create();
    $supporter = User::factory()->create();
    Support::factory()->forPlan($cheap)->create(['user_id' => $supporter->id]);
    $former = User::factory()->create();
    Support::factory()->forPlan($cheap)->ended()->create(['user_id' => $former->id]);
    $post = Post::factory()->for($this->creator)->published()->requiring(500)->create();
    $post->like(User::factory()->create());
    $url = route('posts.show', [$this->creator, $post]);

    $this->actingAs($this->creator->user)->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('post.like_count', 1)->where('post.can_like', false));
    // Their plan leaves the post locked, but they still support the creator.
    $this->actingAs($supporter)->get($url)
        ->assertInertia(fn (Assert $page) => $page->where('post.locked', true)->where('post.like_count', 1)->where('post.can_like', false));

    foreach ([$this->fan, $former] as $viewer) {
        $this->actingAs($viewer)->get($url)
            ->assertInertia(fn (Assert $page) => $page->where('post.like_count', 1));
    }
    auth()->logout();
    $this->get($url)->assertInertia(fn (Assert $page) => $page->where('post.like_count', 1));
});

it('tells the viewer whether they liked a post', function () {
    $post = Post::factory()->for($this->creator)->published()->create();
    $post->like($this->fan);

    $this->actingAs($this->fan)->get(route('posts.show', [$this->creator, $post]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('post.liked', true)
            ->where('post.can_like', true)
            ->where('post.like_count', 1));
});

it('marks liked posts in lists with one query', function () {
    $plan = Plan::factory()->for($this->creator)->price(100)->create();
    Support::factory()->forPlan($plan)->create(['user_id' => $this->fan->id]);
    $posts = Post::factory()->for($this->creator)->published()->count(5)->create();
    $posts[1]->like($this->fan);

    DB::enableQueryLog();
    $this->actingAs($this->fan)->get(route('creators.show', $this->creator))
        ->assertInertia(fn (Assert $page) => $page
            ->has('posts.data', 5)
            ->where('posts.data', fn ($data) => collect($data)->where('liked', true)->pluck('id')->all() === [$posts[1]->id]
                && collect($data)->firstWhere('id', $posts[1]->id)['like_count'] === 1));
    $likeQueries = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], 'post_likes'));
    expect($likeQueries)->toHaveCount(1);

    foreach ([route('home'), route('feed')] as $url) {
        $this->actingAs($this->fan)->get($url)->assertInertia(fn (Assert $page) => $page
            ->where(str_contains($url, 'feed') ? 'posts.data' : 'posts', fn ($data) => collect($data)->firstWhere('id', $posts[1]->id)['liked'] === true));
    }
});

it('shows like counts in the creator post list', function () {
    $post = Post::factory()->for($this->creator)->published()->create();
    $post->like($this->fan);

    $this->actingAs($this->creator->user)->get(route('creator.posts.index'))
        ->assertInertia(fn (Assert $page) => $page->where('posts.data.0.like_count', 1));
});

it('takes a deleted user\'s likes off the counts', function () {
    fakeBilling();
    $post = Post::factory()->for($this->creator)->published()->create();
    $post->like($this->fan);
    $post->like(User::factory()->create());

    $this->actingAs($this->fan)->delete(route('profile.destroy'), ['password' => 'password'])->assertRedirect();

    expect($post->fresh()->like_count)->toBe(1)
        ->and(PostLike::count())->toBe(1);
});

it('recounts likes from the rows', function () {
    $post = Post::factory()->for($this->creator)->published()->create();
    $post->like($this->fan);
    Post::whereKey($post->id)->toBase()->update(['like_count' => 7]);

    $this->artisan('visparkle:recount-post-likes')->expectsOutput('Fixed 1 post(s).')->assertSuccessful();

    expect($post->fresh()->like_count)->toBe(1);
});
