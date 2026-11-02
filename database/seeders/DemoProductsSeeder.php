<?php

namespace Database\Seeders;

use App\Models\Creator;
use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Goods for the shop tab of every demo creator. Each sells two acrylic stands, a random cheki (3–6 designs), and
 * can badges (2–5 designs as kinds); an acrylic charm, a pass case, a tapestry and a T-shirt (S / M / L / フリー)
 * each turn up by chance. The chances and stock come from a random engine seeded by the slug, so a creator gets the
 * same goods on every run.
 *
 * Rows are written directly, so they are published even for a creator who cannot sell yet. Uses no model factories,
 * so it also runs on production. Safe to re-run: a creator who has products already is left alone.
 */
class DemoProductsSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * The chance (in percent) that each optional item is on sale.
     */
    private const OPTIONAL_CHANCE = 50;

    public function run(): void
    {
        $slugs = ['demo-creator', ...DemoCreatorsSeeder::slugs()];

        foreach (Creator::whereIn('slug', $slugs)->whereDoesntHave('products', fn ($query) => $query->withTrashed())->get() as $creator) {
            $this->stock($creator);
        }
    }

    private function stock(Creator $creator): void
    {
        $random = new Randomizer(new Mt19937(crc32($creator->slug)));
        $name = $creator->display_name;

        // Drawn every time, so the goods below come out the same whether or not the fee is set already.
        $shippingFee = [370, 500, 520, 600][$random->getInt(0, 3)];

        if ($creator->shipping_fee === 0) {
            $creator->forceFill(['shipping_fee' => $shippingFee])->save();
        }

        $chekiKinds = $random->getInt(3, 6);
        $badgeKinds = $random->getInt(2, 5);

        // name, description, price, kinds as [name, stock] (a single kind with no name for an item without kinds)
        $items = [
            ['アクリルスタンドA', "{$name}の描き下ろしイラストのアクリルスタンドです。高さ約15cm。", 1800, [[null, $random->getInt(0, 20)]]],
            ['アクリルスタンドB', "{$name}の描き下ろしイラストのアクリルスタンドです。Aとは別の絵柄です。高さ約15cm。", 1800, [[null, $random->getInt(0, 20)]]],
            ["ランダムチェキ（全{$chekiKinds}種）", "全{$chekiKinds}種のうち1枚をランダムでお届けします。絵柄は選べません。", 600, [[null, $random->getInt(10, 50)]]],
            ['缶バッジ', '直径57mmの缶バッジです。デザインを選んでください。', 500, $this->kinds($random, array_map(fn (int $i) => "デザイン{$i}", range(1, $badgeKinds)), 0, 15)],
        ];

        if ($this->chance($random)) {
            $items[] = ['アクリルチャーム', 'ボールチェーンつきのアクリルチャームです。約5cm。', 800, [[null, $random->getInt(0, 20)]]];
        }

        if ($this->chance($random)) {
            $items[] = ['パスケース', 'リールつきのパスケースです。ICカードを入れたまま使えます。', 1500, [[null, $random->getInt(0, 10)]]];
        }

        if ($this->chance($random)) {
            $items[] = ['タペストリー', 'B2サイズのタペストリーです。', 4500, [[null, $random->getInt(0, 5)]]];
        }

        if ($this->chance($random)) {
            $items[] = ['Tシャツ', "{$name}のロゴ入りTシャツです。綿100%。", 3500, $this->kinds($random, ['S', 'M', 'L', 'フリー'], 0, 8)];
        }

        foreach ($items as $sort => [$productName, $description, $price, $kinds]) {
            $product = new Product(['name' => $productName, 'description' => $description, 'price' => $price, 'is_published' => true, 'sort_order' => $sort]);
            $creator->products()->save($product);

            foreach ($kinds as $i => [$kind, $stock]) {
                $product->variants()->create(['name' => $kind, 'stock' => $stock, 'sort_order' => $i]);
            }
        }
    }

    /**
     * @param  list<string>  $names
     * @return list<array{string, int}>
     */
    private function kinds(Randomizer $random, array $names, int $min, int $max): array
    {
        return array_map(fn (string $name) => [$name, $random->getInt($min, $max)], $names);
    }

    private function chance(Randomizer $random): bool
    {
        return $random->getInt(1, 100) <= self::OPTIONAL_CHANCE;
    }
}
