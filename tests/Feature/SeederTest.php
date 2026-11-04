<?php

use App\Enums\ProductKind;
use App\Models\Creator;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoCreatorsSeeder;
use Database\Seeders\DemoProductsSeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(fn () => fakePrivateDisk());

it('seeds demo accounts, plans and posts', function () {
    Storage::fake('public');
    $gateway = fakeBilling();
    $this->seed();

    $creator = Creator::where('slug', 'demo-creator')->sole();

    expect(User::where('email', 'fan@example.com')->sole()->email_verified_at)->not->toBeNull()
        ->and($creator->user->email)->toBe('creator@example.com')
        ->and($creator->plans()->pluck('price')->all())->toBe([300, 500, 1000])
        ->and($creator->canAcceptSupports())->toBeTrue()
        ->and($gateway->retrieveAccount('acct_fake_demo')->payoutsEnabled)->toBeTrue()
        ->and($creator->posts()->published()->pluck('required_price')->sort()->values()->all())->toBe([0, 300, 1000]);
});

it('seeds ten more demo creators with 1-3 plans and 2-5 posts each', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();

    $others = Creator::where('slug', '!=', 'demo-creator')->with(['plans', 'posts'])->get();

    expect($others)->toHaveCount(10);

    foreach ($others as $creator) {
        $prices = $creator->plans->pluck('price');

        expect($prices->count())->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
            ->and($prices->unique()->count())->toBe($prices->count())
            ->and($creator->posts->count())->toBeGreaterThanOrEqual(2)->toBeLessThanOrEqual(5)
            ->and($creator->posts->every(fn ($post) => $post->isPublished()))->toBeTrue()
            // Every locked post must be unlockable by one of the creator's plans.
            ->and($creator->posts->pluck('required_price')->diff([0, ...$prices])->all())->toBe([]);
    }
});

it('lets eight of the ten demo creators accept supports locally', function () {
    Storage::fake('public');
    $gateway = fakeBilling();
    $this->seed();

    $others = Creator::where('slug', '!=', 'demo-creator')->get();

    expect($others->filter->canAcceptSupports()->count())->toBe(8)
        ->and($others->reject->canAcceptSupports()->pluck('slug')->sort()->values()->all())->toBe(['hiro-3d', 'kuroneko-manga'])
        ->and($gateway->retrieveAccount('acct_fake_sakura-illust')->payoutsEnabled)->toBeTrue();
});

it('gives every demo creator an avatar and a header image', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();

    foreach (Creator::all() as $creator) {
        expect($creator->avatar_path)->not->toBeNull()
            ->and($creator->header_path)->not->toBeNull();

        Storage::disk('public')->assertExists([$creator->avatar_path, $creator->header_path]);

        expect(getimagesizefromstring(Storage::disk('public')->get($creator->avatar_path))[0])->toBe(400)
            ->and(getimagesizefromstring(Storage::disk('public')->get($creator->header_path))[0])->toBe(1500);
    }
});

it('gives demo accounts an unknown password in production', function () {
    Storage::fake('public');
    app()->detectEnvironment(fn () => 'production');

    // The exact command used on Laravel Cloud.
    $this->artisan('db:seed', ['--class' => DemoCreatorsSeeder::class, '--force' => true])->assertSuccessful();

    $user = User::where('email', 'sakura-illust@example.com')->sole();

    expect(Creator::count())->toBe(10)
        ->and(Creator::whereNotNull('stripe_account_id')->count())->toBe(0)
        ->and(Hash::check('password', $user->password))->toBeFalse()
        ->and($user->email_verified_at)->not->toBeNull();
});

it('keeps the known demo password locally', function () {
    Storage::fake('public');

    $this->seed(DemoCreatorsSeeder::class);

    expect(Hash::check('password', User::where('email', 'sakura-illust@example.com')->sole()->password))->toBeTrue();
});

it('can be re-run without duplicating creators or replacing images', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();
    $avatar = Creator::where('slug', 'sakura-illust')->sole()->avatar_path;

    $this->seed(DemoCreatorsSeeder::class);

    expect(Creator::count())->toBe(11)
        ->and(Creator::where('slug', 'sakura-illust')->sole()->avatar_path)->toBe($avatar);
});

it('seeds the same shop goods for every demo creator on each run', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();

    $creators = Creator::with('products.variants')->get();
    $optional = ['アクリルチャーム', 'パスケース', 'タペストリー', 'Tシャツ'];

    expect($creators)->toHaveCount(11)
        ->and(Creator::where('slug', 'demo-creator')->sole()->shipping_fee)->toBe(500);

    foreach ($creators as $creator) {
        $names = $creator->products->pluck('name');
        $byName = $creator->products->keyBy('name');
        $cheki = $creator->products->first(fn ($product) => str_starts_with($product->name, 'ランダムチェキ'));

        expect($names->take(2)->all())->toBe(['アクリルスタンドA', 'アクリルスタンドB'])
            ->and($cheki->name)->toMatch('/^ランダムチェキ（全[3-6]種）$/')
            ->and($cheki->variants)->toHaveCount(1)
            ->and($byName['缶バッジ']->variants->count())->toBeBetween(2, 5)
            ->and($byName['缶バッジ']->variants->first()->name)->toBe('デザイン1')
            ->and($names->diff(['アクリルスタンドA', 'アクリルスタンドB', $cheki->name, '缶バッジ', 'ラフ画集', ...$optional]))->toBeEmpty()
            ->and($creator->products->every->is_published)->toBeTrue()
            ->and($creator->shipping_fee)->toBeGreaterThan(0);

        if ($byName->has('Tシャツ')) {
            expect($byName['Tシャツ']->variants->pluck('name')->all())->toBe(['S', 'M', 'L', 'フリー']);
        }
    }

    // Some optional goods turn up and some do not.
    $optionalCount = $creators->sum(fn ($creator) => $creator->products->whereIn('name', $optional)->count());
    expect($optionalCount)->toBeGreaterThan(0)->toBeLessThan(11 * count($optional));

    $before = $creators->mapWithKeys(fn ($creator) => [$creator->slug => $creator->products->map(fn ($product) => [$product->name, $product->variants->pluck('stock')->all()])->all()]);

    $this->seed(DemoProductsSeeder::class);
    expect(Product::count())->toBe($creators->sum(fn ($creator) => $creator->products->count()));

    // Seeded again from scratch, every creator gets the very same goods.
    Product::withTrashed()->get()->each->forceDelete();
    $this->seed(DemoProductsSeeder::class);
    $after = Creator::with('products.variants')->get()->mapWithKeys(fn ($creator) => [$creator->slug => $creator->products->map(fn ($product) => [$product->name, $product->variants->pluck('stock')->all()])->all()]);

    expect($after->all())->toBe($before->all());
});

it('seeds a digital work with a file on the private disk for every demo creator', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();

    $works = Product::query()->where('kind', ProductKind::Digital)->with(['files', 'variants'])->get();

    expect($works)->toHaveCount(11);

    foreach ($works as $work) {
        $file = $work->files->sole();

        expect($work->name)->toBe('ラフ画集')
            ->and($work->is_published)->toBeTrue()
            ->and($work->stock)->toBe(0)
            ->and($work->variants)->toHaveCount(1)
            ->and($work->isPurchasable())->toBeTrue()
            ->and($file->path)->toStartWith("creators/{$work->creator_id}/products/{$work->id}/");
        Storage::disk('private')->assertExists($file->path);
    }
});

it('draws a picture of its own for every demo product, once', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();

    $products = Product::with('images')->get();
    $pictures = $products->map(fn (Product $product) => Storage::disk('public')->get($product->images->sole()->path));

    expect($products)->not->toBeEmpty()
        ->and($pictures->unique())->toHaveCount($products->count())
        ->and(getimagesizefromstring((string) $pictures->first()))->toMatchArray([0 => 800, 1 => 800, 'mime' => 'image/png']);

    $this->seed(DemoProductsSeeder::class);

    expect(Product::has('images', '>', 1)->exists())->toBeFalse();
});

it('draws pictures for demo goods that were seeded without them', function () {
    Storage::fake('public');
    fakeBilling();
    $this->seed();

    $product = Product::whereHas('creator', fn ($query) => $query->where('slug', 'kuroneko-manga'))->where('name', 'Tシャツ')->first()
        ?? Product::whereHas('creator', fn ($query) => $query->where('slug', 'kuroneko-manga'))->firstOrFail();
    $product->images()->delete();

    $this->seed(DemoProductsSeeder::class);

    $path = $product->images()->sole()->path;
    expect($path)->toBe("creators/{$product->creator_id}/products/demo-{$product->id}.png");
    Storage::disk('public')->assertExists($path);
});

it('dates demo goods so that the creators mix in the new arrivals', function () {
    Storage::fake('public');
    Storage::fake('private');

    $this->seed();

    $dates = Product::query()->orderBy('id')->pluck('created_at', 'id')->map->toIso8601String();
    $newest = Product::query()->latest()->latest('id')->limit(12)->pluck('creator_id');

    expect($newest->unique()->count())->toBeGreaterThan(3)
        ->and(Product::query()->where('created_at', '>', now())->exists())->toBeFalse();

    $this->seed(DemoProductsSeeder::class);

    expect(Product::query()->orderBy('id')->pluck('created_at', 'id')->map->toIso8601String()->all())->toBe($dates->all());
});
