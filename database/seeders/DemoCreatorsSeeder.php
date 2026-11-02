<?php

namespace Database\Seeders;

use App\Billing\FakeGateway;
use App\Enums\PostStatus;
use App\Models\Creator;
use App\Models\User;
use App\Services\Legal\LegalDocuments;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Ten demo creators with plans, posts, images and shop goods (DemoProductsSeeder). Locally their accounts use the password "password"; in
 * production each gets a long random password nobody knows, so the pages show but nobody can log in as them.
 * Locally (fake billing) eight of them get a fake payout account so their plans can be supported; the other two
 * stay "準備中". In production none has a payout account, since a made-up Stripe account would break checkout;
 * `php artisan fanbox:demo-payout-accounts` opens real Stripe test-mode accounts for the eight instead.
 *
 * Uses no model factories (Faker is a dev dependency), so it also runs on production:
 *   php artisan db:seed --class=DemoCreatorsSeeder --force
 */
class DemoCreatorsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * slug, display name, bio, plans [price, name, description], posts [required price, title, excerpt].
     *
     * @var list<array{0: string, 1: string, 2: string, 3: list<array{int, string, string}>, 4: list<array{int, string, string}>}>
     */
    private const CREATORS = [
        ['sakura-illust', '桜井ミナ', "季節の風景と女の子のイラストを描いています。\n月に2回、新作を公開します。", [
            [300, 'ちょっと応援', '高解像度版の壁紙をお届けします'],
            [800, 'しっかり応援', 'ラフと線画の工程を公開します'],
        ], [
            [0, '春の新作を公開しました', '桜並木を歩く女の子を描きました。'],
            [300, '壁紙配布：夜桜', 'スマホ・PC用の壁紙です。'],
            [800, '線画から仕上げまで', '塗りの工程を順番に解説します。'],
        ]],
        ['kuroneko-manga', 'くろねこ堂', '猫と暮らす日常を4コマ漫画にしています。', [
            [200, 'ねこ好き', '毎週の4コマを先行公開'],
        ], [
            [0, '第1話 はじめまして', 'うちの猫を紹介します。'],
            [200, '第2話 箱の中', '新しい箱は必ず入ります。'],
        ]],
        ['pixel-forge', 'ピクセル工房', "ドット絵のキャラクターと背景を制作しています。\nゲーム用素材の配布もしています。", [
            [500, '素材パック', '毎月のドット絵素材パック'],
            [1500, '制作者プラン', '商用利用できる素材とPSDデータ'],
            [3000, 'スポンサー', '新作の名前入りクレジット'],
        ], [
            [0, '新しいタイルセットを作っています', '森のステージ用の背景です。'],
            [500, '素材パック 11月号', 'キャラクター8体と小物20点。'],
            [1500, 'アニメーション用のPSD', 'レイヤー分けしたデータです。'],
            [3000, '次回作の企画書', '構想段階の資料を共有します。'],
        ]],
        ['aoi-music', '青井ソラ', 'ピアノとシンセで作るインストゥルメンタル曲を発表しています。', [
            [400, 'リスナー', '新曲を先行公開'],
            [1000, 'サポーター', '楽譜とステム音源つき'],
        ], [
            [0, '新曲「夜明けの坂道」', '朝の散歩をイメージした曲です。'],
            [400, '先行公開：冬の灯り', '来月公開予定の新曲です。'],
            [1000, '楽譜：夜明けの坂道', 'ピアノ譜をPDFで配布します。'],
            [400, '制作日記 11月', '機材を少し入れ替えました。'],
            [0, 'ライブ配信のお知らせ', '週末にピアノ配信をします。'],
        ]],
        ['tanaka-photo', '田中写真室', '日本各地の街角と海辺を撮っています。', [
            [300, 'フォトファン', '未公開カットをお届け'],
        ], [
            [0, '港町の夕暮れ', '旅先で撮った一枚です。'],
            [300, '未公開カット：雨の路地', 'RAW現像前後の比較つき。'],
            [300, '撮影地メモ：瀬戸内', '撮影した場所の地図とメモです。'],
        ]],
        ['moko-craft', 'もこもこ手芸部', "羊毛フェルトで小さな動物を作っています。\n作り方のレシピも公開中です。", [
            [500, '手芸部員', '毎月の作り方レシピ'],
            [1200, '上級部員', '型紙と動画の解説つき'],
        ], [
            [0, 'しろくまが完成しました', '高さ5cmのしろくまです。'],
            [500, 'レシピ：ひよこ', '材料と手順をまとめました。'],
            [1200, '動画で解説：植毛のコツ', '毛並みをきれいに見せる方法です。'],
        ]],
        ['night-novel', '夜野ツバサ', 'ファンタジー小説を連載しています。毎週金曜更新。', [
            [300, '読者', '最新話を1週間先行公開'],
            [700, '愛読者', '設定資料と番外編'],
            [1500, '後援者', '書き下ろし短編を毎月'],
        ], [
            [0, '第1章 星の降る町', '物語の始まりです。'],
            [300, '第2章 先行公開', '旅立ちの朝。'],
            [700, '設定資料：王国の地図', '物語の舞台をまとめました。'],
            [1500, '書き下ろし短編「灯台守」', '本編の10年前の物語です。'],
            [0, '連載半年のごあいさつ', 'いつも読んでくださりありがとうございます。'],
        ]],
        ['hiro-3d', 'ヒロの3Dラボ', 'Blenderでキャラクターと小物のモデルを作っています。', [
            [600, 'モデラー', '毎月の練習用モデル'],
        ], [
            [0, 'ローポリの家を作りました', '600ポリゴンの小さな家です。'],
            [600, '練習用モデル：ランタン', 'テクスチャつきで配布します。'],
            [600, 'ボーンの入れ方メモ', 'つまずいた点をまとめました。'],
            [0, 'ポートフォリオを更新しました', '最近の作品を追加しました。'],
        ]],
        ['yuki-cooking', 'ゆきの台所', '季節の和食と作り置きのレシピを紹介しています。', [
            [200, 'お試し', '毎週のレシピを1品'],
            [500, '定番', '1週間分の献立表つき'],
        ], [
            [0, '大根と鶏の煮物', '寒い日にぴったりの一品です。'],
            [200, '作り置き：きんぴら3種', '冷蔵で4日もちます。'],
            [500, '11月の献立表', '買い物リストつきです。'],
        ]],
        ['studio-lumen', 'スタジオ・ルーメン', "インディーゲームを2人で開発しています。\n開発の進み具合を毎月お知らせします。", [
            [500, 'テスター', '開発版のスクリーンショット'],
            [2000, 'パトロン', '体験版とクレジット掲載'],
        ], [
            [0, '開発を始めました', '小さな灯台の島を舞台にしたパズルゲームです。'],
            [500, '開発日誌 #3', '新しいステージのスクリーンショット。'],
        ]],
    ];

    /**
     * Demo creators left without a payout account, so their plans show as not yet accepting supports (2 of 10).
     */
    private const NOT_ACCEPTING_SUPPORTS = ['kuroneko-manga', 'hiro-3d'];

    /**
     * Two colours per creator (avatar background / header gradient), keyed by slug.
     *
     * @var array<string, array{string, string}>
     */
    private const PALETTES = [
        'demo-creator' => ['#6366f1', '#ec4899'],
        'sakura-illust' => ['#f472b6', '#fde68a'],
        'kuroneko-manga' => ['#1f2937', '#f59e0b'],
        'pixel-forge' => ['#16a34a', '#0ea5e9'],
        'aoi-music' => ['#2563eb', '#a5b4fc'],
        'tanaka-photo' => ['#b45309', '#fcd34d'],
        'moko-craft' => ['#f9a8d4', '#a7f3d0'],
        'night-novel' => ['#312e81', '#c4b5fd'],
        'hiro-3d' => ['#0f766e', '#f97316'],
        'yuki-cooking' => ['#dc2626', '#fef3c7'],
        'studio-lumen' => ['#0369a1', '#facc15'],
    ];

    /**
     * Safe to re-run: existing creators keep their plans and posts and only get missing images and payout accounts.
     */
    public function run(): void
    {
        foreach (self::CREATORS as $index => [$slug, $name, $bio, $plans, $posts]) {
            if (Creator::where('slug', $slug)->exists()) {
                continue;
            }

            $user = User::create([
                'name' => $name,
                'email' => "{$slug}@example.com",
                'password' => app()->isProduction() ? Str::random(64) : 'password',
            ]);
            $user->forceFill([
                'email_verified_at' => now(),
                'terms_version' => LegalDocuments::termsVersion(),
                'terms_accepted_at' => now(),
            ])->save();

            $creator = $user->creator()->create(['slug' => $slug, 'display_name' => $name, 'bio' => $bio]);

            $this->createContent($creator, $index, $plans, $posts);
        }

        foreach (Creator::whereIn('slug', array_keys(self::PALETTES))->get() as $creator) {
            $this->paintImages($creator, self::PALETTES[$creator->slug]);
        }

        if (config('fanbox.billing.driver') === 'fake' && ! app()->isProduction()) {
            $this->openFakePayoutAccounts();
        }

        $this->call(DemoProductsSeeder::class);
    }

    /**
     * Slugs of all demo creators.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return array_column(self::CREATORS, 0);
    }

    /**
     * Slugs of the demo creators meant to accept supports.
     *
     * @return list<string>
     */
    public static function acceptingSupports(): array
    {
        return array_values(array_diff(array_column(self::CREATORS, 0), self::NOT_ACCEPTING_SUPPORTS));
    }

    private function openFakePayoutAccounts(): void
    {
        foreach (Creator::whereIn('slug', self::acceptingSupports())->get() as $creator) {
            $accountId = "acct_fake_{$creator->slug}";
            $creator->forceFill(['stripe_account_id' => $accountId, 'payouts_enabled' => true, 'details_submitted' => true])->save();
            app(FakeGateway::class)->setAccount($accountId, true, true);
        }
    }

    /**
     * @param  list<array{int, string, string}>  $plans
     * @param  list<array{int, string, string}>  $posts
     */
    private function createContent(Creator $creator, int $index, array $plans, array $posts): void
    {
        foreach ($plans as $sort => [$price, $planName, $description]) {
            $creator->plans()->create(['price' => $price, 'name' => $planName, 'description' => $description, 'sort' => $sort]);
        }

        foreach ($posts as $i => [$price, $title, $excerpt]) {
            $creator->posts()->create([
                'status' => PostStatus::Published,
                'required_price' => $price,
                'title' => $title,
                'excerpt' => $excerpt,
                'published_at' => now()->subDays(30 - $index * 2 - $i)->setTime(12, 0),
                'body' => [
                    'type' => 'doc',
                    'content' => [
                        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [['type' => 'text', 'text' => $title]]],
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $excerpt]]],
                        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'いつも応援してくださり、ありがとうございます。']]],
                    ],
                ],
            ]);
        }
    }

    /**
     * A flat avatar with the slug's initial and a gradient header with soft circles, stored on the public disk.
     *
     * @param  array{string, string}  $palette
     */
    private function paintImages(Creator $creator, array $palette): void
    {
        $updates = [];

        if ($creator->avatar_path === null) {
            $path = "creators/{$creator->id}/avatar/demo.png";
            Storage::disk('public')->put($path, $this->avatar(strtoupper($creator->slug[0]), $palette));
            $updates['avatar_path'] = $path;
        }

        if ($creator->header_path === null) {
            $path = "creators/{$creator->id}/header/demo.png";
            Storage::disk('public')->put($path, $this->header($palette));
            $updates['header_path'] = $path;
        }

        if ($updates !== []) {
            $creator->update($updates);
        }
    }

    /**
     * @param  array{string, string}  $palette
     */
    private function avatar(string $letter, array $palette): string
    {
        $size = 400;
        $image = imagecreatetruecolor($size, $size);
        imagefill($image, 0, 0, $this->color($image, $palette[0]));
        imagefilledellipse($image, 300, 90, 260, 260, $this->color($image, $palette[1], 70));

        // Render the letter with GD's built-in font, then scale it up for a chunky pixel look.
        $glyph = imagecreatetruecolor(9, 15);
        $background = $this->color($glyph, $palette[0]);
        imagefill($glyph, 0, 0, $background);
        imagecolortransparent($glyph, $background);
        imagestring($glyph, 5, 0, 0, $letter, $this->color($glyph, '#ffffff'));
        imagecopyresized($image, $glyph, 128, 80, 0, 0, 162, 270, 9, 15);

        return $this->png($image);
    }

    /**
     * @param  array{string, string}  $palette
     */
    private function header(array $palette): string
    {
        [$width, $height] = [1500, 500];
        $image = imagecreatetruecolor($width, $height);
        [$from, $to] = [$this->rgb($palette[0]), $this->rgb($palette[1])];

        for ($x = 0; $x < $width; $x++) {
            $t = $x / ($width - 1);
            [$r, $g, $b] = array_map(fn (int $a, int $b) => $this->byte((int) round($a + ($b - $a) * $t)), $from, $to);
            imageline($image, $x, 0, $x, $height, (int) imagecolorallocate($image, $r, $g, $b));
        }

        foreach ([[220, 380, 360], [760, 80, 220], [1260, 330, 420], [1050, 470, 160]] as [$cx, $cy, $d]) {
            imagefilledellipse($image, $cx, $cy, $d, $d, $this->color($image, '#ffffff', 100));
        }

        return $this->png($image);
    }

    /**
     * @return array{int<0, 255>, int<0, 255>, int<0, 255>}
     */
    private function rgb(string $hex): array
    {
        return [
            $this->byte((int) hexdec(substr($hex, 1, 2))),
            $this->byte((int) hexdec(substr($hex, 3, 2))),
            $this->byte((int) hexdec(substr($hex, 5, 2))),
        ];
    }

    /**
     * @return int<0, 255>
     */
    private function byte(int $value): int
    {
        return max(0, min(255, $value));
    }

    /**
     * @param  int<0, 127>  $alpha  0 (opaque) to 127 (transparent)
     */
    private function color(\GdImage $image, string $hex, int $alpha = 0): int
    {
        [$r, $g, $b] = $this->rgb($hex);

        return (int) imagecolorallocatealpha($image, $r, $g, $b, $alpha);
    }

    private function png(\GdImage $image): string
    {
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }
}
