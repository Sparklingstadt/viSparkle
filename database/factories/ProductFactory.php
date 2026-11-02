<?php

namespace Database\Factories;

use App\Enums\ProductKind;
use App\Models\Creator;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;
use WeakMap;

/**
 * Products get a single variant with no name. A `stock` attribute (5 by default) becomes that variant's stock;
 * withVariants() gives the product named kinds instead.
 *
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /** @var WeakMap<Product, int>|null */
    private static ?WeakMap $stocks = null;

    public function definition(): array
    {
        return [
            'creator_id' => Creator::factory(),
            'kind' => ProductKind::Physical,
            'name' => fake()->words(3, true),
            'description' => fake()->realText(120),
            'price' => 1500,
            'stock' => 5,
            'is_published' => true,
            'sort_order' => 0,
        ];
    }

    public function configure(): static
    {
        return $this
            ->afterMaking(function (Product $product) {
                self::$stocks ??= new WeakMap;
                self::$stocks[$product] = (int) $product->getAttributes()['stock'];
                $product->offsetUnset('stock');
            })
            ->afterCreating(function (Product $product) {
                $product->variants()->create(['stock' => self::$stocks[$product] ?? 5]);
                $product->unsetRelation('variants');
            });
    }

    public function soldOut(): static
    {
        return $this->state(fn () => ['stock' => 0]);
    }

    /**
     * A digital work: no stock (its single variant holds 0) and no files until some are added.
     */
    public function digital(): static
    {
        return $this->state(fn () => ['kind' => ProductKind::Digital, 'stock' => 0]);
    }

    public function unpublished(): static
    {
        return $this->state(fn () => ['is_published' => false]);
    }

    /**
     * Named kinds with their stock in place of the single variant, e.g. ['S' => 2, 'M' => 0].
     *
     * @param  array<string, int>  $stocks
     */
    public function withVariants(array $stocks): static
    {
        return $this->afterCreating(function (Product $product) use ($stocks) {
            $product->variants()->forceDelete();

            foreach (array_keys($stocks) as $sort => $name) {
                $product->variants()->create(['name' => $name, 'stock' => $stocks[$name], 'sort_order' => $sort]);
            }

            $product->unsetRelation('variants');
        });
    }
}
