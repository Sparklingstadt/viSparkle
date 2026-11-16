<?php

use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\Entitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Plans of 300/500/1000 and three posts: free (0), paid (500), draft (500).
 *
 * @return array{creator: User, plans: array<int, Plan>, posts: array<int, Post>}
 */
function entitlementFixture(): array
{
    $plans = [];
    foreach ([300, 500, 1000] as $price) {
        $plans[$price] = Plan::factory()->price($price)->create();
    }

    return [
        'creator' => User::factory()->creator()->create(),
        'plans' => $plans,
        'posts' => [
            Post::factory()->published()->requiring(0)->create(),
            Post::factory()->published()->requiring(500)->create(),
            Post::factory()->draft()->requiring(500)->create(),
        ],
    ];
}

function viewerFor(string $who, array $fixture): ?User
{
    $plans = $fixture['plans'];
    $supporter = fn (Plan $plan, bool $ended = false) => tap(User::factory()->create(), fn (User $user) => Support::factory()
        ->for($user)->forPlan($plan)->{$ended ? 'ended' : 'active'}()->create());

    return match ($who) {
        'creator' => $fixture['creator'],
        'guest' => null,
        'fan' => User::factory()->create(),
        'low' => $supporter($plans[300]),
        'equal' => $supporter($plans[500]),
        'high' => $supporter($plans[1000]),
        'ended' => $supporter($plans[1000], ended: true),
    };
}

// Expected order: [free published, 500 published, 500 draft]
dataset('viewers', [
    'creator' => ['creator', [true, true, true]],
    'guest' => ['guest', [true, false, false]],
    'no support' => ['fan', [true, false, false]],
    'lower support' => ['low', [true, false, false]],
    'equal support' => ['equal', [true, true, false]],
    'higher support' => ['high', [true, true, false]],
    'ended support' => ['ended', [true, false, false]],
]);

it('decides visibility', function (string $who, array $expected) {
    $fixture = entitlementFixture();
    $entitlement = Entitlement::for(viewerFor($who, $fixture));

    expect(array_map(fn (Post $post) => $entitlement->canView($post), $fixture['posts']))->toBe($expected);
})->with('viewers');

it('authorizes through the post policy', function (string $who, array $expected) {
    $fixture = entitlementFixture();
    $gate = Gate::forUser(viewerFor($who, $fixture));

    expect(array_map(fn (Post $post) => $gate->allows('view', $post), $fixture['posts']))->toBe($expected);
})->with('viewers');

it('uses the support price snapshot, not the current plan price', function () {
    $fixture = entitlementFixture();
    $viewer = viewerFor('equal', $fixture);
    DB::table('plans')->where('id', $fixture['plans'][500]->id)->update(['price' => 100]);

    expect(Entitlement::for($viewer)->canView($fixture['posts'][1]))->toBeTrue();
});

it('exposes the viewer\'s support price', function () {
    $fixture = entitlementFixture();

    expect(Entitlement::for(viewerFor('high', $fixture))->supportPrice())->toBe(1000)
        ->and(Entitlement::for(viewerFor('fan', $fixture))->supportPrice())->toBeNull()
        ->and(Entitlement::for(null)->supportPrice())->toBeNull();
});

it('tells whether the viewer is the creator', function () {
    $fixture = entitlementFixture();

    expect(Entitlement::for($fixture['creator'])->isCreator())->toBeTrue()
        ->and(Entitlement::for(viewerFor('high', $fixture))->isCreator())->toBeFalse()
        ->and(Entitlement::for(null)->isCreator())->toBeFalse();
});

it('loads the support with a single query', function () {
    $user = User::factory()->create();
    Support::factory()->for($user)->forPlan(Plan::factory()->price(500)->create())->active()->create();
    $posts = Post::factory()->count(4)->published()->requiring(500)->create();

    DB::enableQueryLog();
    $entitlement = Entitlement::for($user);
    $visible = $posts->filter(fn (Post $post) => $entitlement->canView($post));

    expect($visible)->toHaveCount(4)->and(DB::getQueryLog())->toHaveCount(1);
});

it('allows only the creator to manage a post', function () {
    $fixture = entitlementFixture();
    $post = $fixture['posts'][1];

    expect(Gate::forUser($fixture['creator'])->allows('manage', $post))->toBeTrue()
        ->and(Gate::forUser(viewerFor('high', $fixture))->allows('manage', $post))->toBeFalse();
});
