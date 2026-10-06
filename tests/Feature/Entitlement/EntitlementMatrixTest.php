<?php

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Support;
use App\Models\User;
use App\Services\Entitlement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Creator with 300/500/1000 plans and three posts: free (0), paid (500), draft (500).
 *
 * @return array{creator: Creator, plans: array<int, Plan>, posts: array<int, Post>}
 */
function entitlementFixture(): array
{
    $creator = Creator::factory()->create();
    $plans = [];
    foreach ([300, 500, 1000] as $price) {
        $plans[$price] = Plan::factory()->for($creator)->price($price)->create();
    }

    return [
        'creator' => $creator,
        'plans' => $plans,
        'posts' => [
            Post::factory()->for($creator)->published()->requiring(0)->create(),
            Post::factory()->for($creator)->published()->requiring(500)->create(),
            Post::factory()->for($creator)->draft()->requiring(500)->create(),
        ],
    ];
}

function viewerFor(string $who, array $fixture): ?User
{
    $plans = $fixture['plans'];
    $supporter = fn (Plan $plan, bool $ended = false) => tap(User::factory()->create(), fn (User $user) => Support::factory()
        ->for($user)->forPlan($plan)->{$ended ? 'ended' : 'active'}()->create());

    return match ($who) {
        'author' => $fixture['creator']->user,
        'guest' => null,
        'fan' => User::factory()->create(),
        'low' => $supporter($plans[300]),
        'equal' => $supporter($plans[500]),
        'high' => $supporter($plans[1000]),
        'ended' => $supporter($plans[1000], ended: true),
        'other' => $supporter(Plan::factory()->price(1000)->create()),
    };
}

// Expected order: [free published, 500 published, 500 draft]
dataset('viewers', [
    'author' => ['author', [true, true, true]],
    'guest' => ['guest', [true, false, false]],
    'no support' => ['fan', [true, false, false]],
    'lower support' => ['low', [true, false, false]],
    'equal support' => ['equal', [true, true, false]],
    'higher support' => ['high', [true, true, false]],
    'ended support' => ['ended', [true, false, false]],
    'other creator' => ['other', [true, false, false]],
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

it('exposes the support price for a creator', function () {
    $fixture = entitlementFixture();
    $entitlement = Entitlement::for(viewerFor('high', $fixture));

    expect($entitlement->supportPriceFor($fixture['creator']->id))->toBe(1000)
        ->and($entitlement->supportPriceFor(999999))->toBeNull()
        ->and(Entitlement::for(null)->supportPriceFor($fixture['creator']->id))->toBeNull();
});

it('loads supports with a single query', function () {
    $user = User::factory()->create();
    $posts = collect();
    foreach (range(1, 3) as $i) {
        $plan = Plan::factory()->price(500)->create();
        Support::factory()->for($user)->forPlan($plan)->active()->create();
        $posts->push(...Post::factory()->count(4)->for($plan->creator)->published()->requiring(500)->create());
    }

    DB::enableQueryLog();
    $entitlement = Entitlement::for($user);
    $visible = $posts->filter(fn (Post $post) => $entitlement->canView($post));

    expect($visible)->toHaveCount(12)->and(DB::getQueryLog())->toHaveCount(1);
});

it('allows only the author to manage a post', function () {
    $fixture = entitlementFixture();
    $post = $fixture['posts'][1];

    expect(Gate::forUser($fixture['creator']->user)->allows('manage', $post))->toBeTrue()
        ->and(Gate::forUser(viewerFor('high', $fixture))->allows('manage', $post))->toBeFalse();
});
