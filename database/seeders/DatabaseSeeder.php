<?php

namespace Database\Seeders;

use App\Models\Creator;
use App\Models\Plan;
use App\Models\Post;
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

        foreach ([[300, 'ちょっと応援', '月1回のラフ公開'], [500, 'しっかり応援', '完成イラストの高解像度版'], [1000, '全力応援', '制作過程の動画と素材ファイル']] as $i => [$price, $name, $description]) {
            Plan::factory()->for($creator)->create(['price' => $price, 'name' => $name, 'description' => $description, 'sort' => $i]);
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
    }
}
