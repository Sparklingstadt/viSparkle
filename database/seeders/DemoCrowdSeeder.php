<?php

namespace Database\Seeders;

use App\Billing\FakeGateway;
use App\Enums\PostStatus;
use App\Models\Creator;
use Illuminate\Database\Seeder;

/**
 * More demo creators, each with a plan, a free post and a paid post, until the service has fifty. Their accounts are
 * made like DemoCreatorsSeeder's, and this uses no model factories, so it also runs on production.
 * Safe to re-run: a creator who exists already is skipped, and nothing is added past fifty.
 */
class DemoCrowdSeeder extends Seeder
{
    private const TOTAL_CREATORS = 50;

    /**
     * Romanised name for the slug, and the name shown.
     *
     * @var array<string, string>
     */
    private const NAMES = [
        'akari' => 'あかり', 'sora' => 'そら', 'hinata' => 'ひなた', 'minato' => 'みなと', 'tsumugi' => 'つむぎ', 'haru' => 'はる',
        'ren' => 'れん', 'yui' => 'ゆい', 'kaede' => 'かえで', 'nagi' => 'なぎ', 'suzu' => 'すず', 'riku' => 'りく', 'mio' => 'みお', 'nana' => 'なな',
    ];

    /**
     * Slug suffix, the end of the name shown, and the post titles [free, paid].
     *
     * @var array<string, array{string, string, string}>
     */
    private const GENRES = [
        'illust' => ['のイラスト帖', '今月のイラストを公開しました', '線画と塗りのメイキング'],
        'craft' => ['の手しごと工房', '新しい作品ができました', '作り方の手順を解説します'],
        'photo' => ['の写真館', '週末に撮った風景です', 'RAW現像の設定を公開します'],
    ];

    /**
     * @var list<array{string, string}>
     */
    private const PALETTES = [
        ['#7c3aed', '#fde68a'], ['#0891b2', '#fbcfe8'], ['#ea580c', '#fef08a'],
        ['#be123c', '#fecdd3'], ['#4d7c0f', '#d9f99d'], ['#1d4ed8', '#bae6fd'],
    ];

    public function run(): void
    {
        $painter = app(DemoCreatorsSeeder::class);
        $index = 0;

        foreach (self::NAMES as $romaji => $name) {
            foreach (self::GENRES as $genre => [$suffix, $freeTitle, $paidTitle]) {
                $slug = "{$romaji}-{$genre}";
                $index++;

                if (Creator::count() >= self::TOTAL_CREATORS) {
                    return;
                }

                if (Creator::where('slug', $slug)->exists()) {
                    continue;
                }

                $displayName = $name.$suffix;
                $user = DemoCreatorsSeeder::account($displayName, "{$slug}@example.com");
                $creator = $user->creator()->create(['slug' => $slug, 'display_name' => $displayName, 'bio' => "{$displayName}へようこそ。\n制作の様子を発信しています。"]);

                $this->createContent($creator, $freeTitle, $paidTitle);
                $this->openFakePayoutAccount($creator);
                $painter->paintImages($creator, self::PALETTES[$index % count(self::PALETTES)]);
            }
        }
    }

    private function createContent(Creator $creator, string $freeTitle, string $paidTitle): void
    {
        $creator->plans()->create(['price' => 500, 'name' => '応援プラン', 'description' => '限定投稿を読めます', 'sort' => 0]);

        foreach ([[0, $freeTitle], [500, $paidTitle]] as [$price, $title]) {
            $creator->posts()->create([
                'status' => PostStatus::Published,
                'required_price' => $price,
                'title' => $title,
                'excerpt' => "{$creator->display_name}の投稿です。",
                'published_at' => now()->subDays(random_int(1, 25))->setTime(12, 0),
                'body' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $title]]]]],
            ]);
        }
    }

    private function openFakePayoutAccount(Creator $creator): void
    {
        if (config('fanbox.billing.driver') !== 'fake' || app()->isProduction()) {
            return;
        }

        $accountId = "acct_fake_{$creator->slug}";
        $creator->forceFill(['stripe_account_id' => $accountId, 'payouts_enabled' => true, 'details_submitted' => true])->save();
        app(FakeGateway::class)->setAccount($accountId, true, true);
    }
}
