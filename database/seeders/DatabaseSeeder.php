<?php

namespace Database\Seeders;

use App\Billing\FakeGateway;
use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Demo data for local development. Both accounts use the password "password".
     */
    public function run(): void
    {
        User::factory()->create(['name' => 'ファン太郎', 'email' => 'fan@example.com']);

        $creator = Creator::factory()
            ->for(User::factory()->state(['name' => 'デモクリエイター', 'email' => 'creator@example.com']))
            ->create([
                'slug' => 'demo-creator',
                'display_name' => 'デモクリエイター',
                'bio' => "イラストと漫画を描いています。\n支援してくださると制作の励みになります！",
            ]);

        // A payable demo creator, so the fake checkout works out of the box.
        $creator->forceFill(['stripe_account_id' => 'acct_fake_demo', 'payouts_enabled' => true, 'details_submitted' => true])->save();

        if (config('fanbox.billing.driver') === 'fake') {
            app(FakeGateway::class)->setAccount('acct_fake_demo', true, true);
        }

        foreach ([[300, 'ちょっと応援', '月1回のラフ公開'], [500, 'しっかり応援', '完成イラストの高解像度版'], [1000, '全力応援', '制作過程の動画と素材ファイル']] as $i => [$price, $name, $description]) {
            Plan::factory()->for($creator)->create(['price' => $price, 'name' => $name, 'description' => $description, 'sort' => $i]);
        }

        // Goods for the shop tab. The seeder writes rows directly, so they are published even though selling needs a payable creator.
        $creator->forceFill(['shipping_fee' => 500])->save();

        foreach ([['ステッカーセット', 'イラストのステッカー 5 枚セットです。', 600, 20], ['アクリルスタンド', '人気イラストをアクリルスタンドにしました。', 1800, 5], ['缶バッジ', '直径 57mm の缶バッジです。こちらは完売しました。', 400, 0]] as $i => [$name, $description, $price, $stock]) {
            Product::factory()->for($creator)->create(['name' => $name, 'description' => $description, 'price' => $price, 'stock' => $stock, 'is_published' => true, 'sort_order' => $i]);
        }

        foreach ([[0, 'ご挨拶', 'ページを開設しました！'], [300, '今月のラフ', '今月描いたラフをまとめました。'], [1000, 'メイキング', '完成までの工程を解説します。']] as $i => [$price, $title, $excerpt]) {
            Post::factory()->for($creator)->published()->requiring($price)->create([
                'title' => $title,
                'excerpt' => $excerpt,
                'published_at' => now()->subDays(3 - $i),
                'body' => [
                    'type' => 'doc',
                    'content' => [
                        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $title]]],
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => "{$title}の本文です。支援者の皆さま、いつもありがとうございます。"]]],
                    ],
                ],
            ]);
        }

        $this->call(DemoCreatorsSeeder::class);
    }
}
