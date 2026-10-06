<?php

use App\Enums\PostFileKind;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\PostFileStorer;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => fakePrivateDisk());

it('renders the creator page', function () {
    $creator = Creator::factory()->create(['bio' => '自己紹介文']);
    Post::factory()->for($creator)->published()->count(2)->create();
    Post::factory()->for($creator)->draft()->create();

    $this->get(route('creators.show', $creator))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/creator')
            ->where('creator.slug', $creator->slug)
            ->where('creator.bio', '自己紹介文')
            ->has('posts.data', 2)
            ->where('isOwner', false));
});

it('lists only accepting plans to visitors and all plans to the owner', function () {
    $creator = Creator::factory()->create();
    Plan::factory()->for($creator)->price(500)->create();
    Plan::factory()->for($creator)->price(1000)->notAccepting()->create();

    $this->get(route('creators.show', $creator))->assertInertia(fn (Assert $page) => $page->has('plans', 1));
    $this->actingAs($creator->user)->get(route('creators.show', $creator))
        ->assertInertia(fn (Assert $page) => $page->has('plans', 2)->where('isOwner', true));
});

it('keeps a supporters current plan visible even when it stopped accepting', function () {
    $creator = Creator::factory()->create();
    $plan = Plan::factory()->for($creator)->price(1000)->notAccepting()->create();
    $user = User::factory()->create();
    Support::factory()->for($user)->forPlan($plan)->active()->create();

    $this->actingAs($user)->get(route('creators.show', $creator))
        ->assertInertia(fn (Assert $page) => $page->has('plans', 1)->where('viewerSupportPrice', 1000)->where('viewerPlanId', $plan->id));
});

it('renders with a constant number of queries regardless of post count', function () {
    $creator = Creator::factory()->create();
    $storer = app(PostFileStorer::class);
    $makePosts = function (int $count) use ($creator, $storer) {
        Post::factory()->for($creator)->published()->requiring(0)->count($count)->create()->each(function (Post $post) use ($storer) {
            $post->update(['cover_file_id' => $storer->store($post, jpegWithExif(20, 20), PostFileKind::Image)->id]);
        });
    };

    $count = function () use ($creator) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->get(route('creators.show', $creator))->assertOk();

        return count(DB::getQueryLog());
    };

    $makePosts(3);
    $few = $count();
    $makePosts(12);
    $many = $count();

    expect($many)->toBe($few);
});

it('shows newest free posts and creators on the home page', function () {
    $free = Post::factory()->published()->create();
    Post::factory()->published()->requiring(500)->create();
    Post::factory()->draft()->create();

    $this->get(route('home'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('public/home')
            ->has('posts', 1)
            ->where('posts.0.id', $free->id)
            ->has('creators', 3));
});
