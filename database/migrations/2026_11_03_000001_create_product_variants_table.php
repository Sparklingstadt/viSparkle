<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stock moves from products to their variants (sizes, designs). Every product keeps at least one variant; a product
 * without kinds has a single variant with no name. Existing products, cart rows and order items move to that variant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name', 50)->nullable();
            $table->unsignedInteger('stock');
            $table->unsignedInteger('sort_order')->default(0);
            $table->softDeletes();
            $table->timestamps();

            $table->index(['product_id', 'sort_order']);
        });

        DB::statement('INSERT INTO product_variants (product_id, name, stock, sort_order, created_at, updated_at)
            SELECT id, NULL, stock, 0, created_at, updated_at FROM products');

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE cart_items SET product_variant_id = (SELECT id FROM product_variants WHERE product_variants.product_id = cart_items.product_id)');
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_variant_id')->nullable(false)->change();
            $table->unique(['user_id', 'product_variant_id']);
            $table->dropUnique(['user_id', 'product_id']);
            $table->dropConstrainedForeignId('product_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->foreignId('product_variant_id')->nullable()->after('product_id')->constrained()->nullOnDelete();
            // Room for 「商品名（種類）」, copied at order time.
            $table->string('product_name', 160)->change();
        });
        DB::statement('UPDATE order_items SET product_variant_id = (SELECT id FROM product_variants WHERE product_variants.product_id = order_items.product_id)
            WHERE product_id IS NOT NULL');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('stock');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->unsignedInteger('stock')->default(0)->after('price');
        });
        DB::statement('UPDATE products SET stock = (SELECT COALESCE(SUM(stock), 0) FROM product_variants
            WHERE product_variants.product_id = products.id AND product_variants.deleted_at IS NULL)');

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('product_variant_id');
        });

        Schema::table('cart_items', function (Blueprint $table) {
            $table->foreignId('product_id')->nullable()->after('user_id')->constrained()->cascadeOnDelete();
        });
        DB::statement('UPDATE cart_items SET product_id = (SELECT product_id FROM product_variants WHERE product_variants.id = cart_items.product_variant_id)');
        // Several variants of one product in a cart collapse to the first.
        DB::statement('DELETE c1 FROM cart_items c1 JOIN cart_items c2
            ON c1.user_id = c2.user_id AND c1.product_id = c2.product_id AND c1.id > c2.id');
        Schema::table('cart_items', function (Blueprint $table) {
            $table->unsignedBigInteger('product_id')->nullable(false)->change();
            $table->unique(['user_id', 'product_id']);
            $table->dropUnique(['user_id', 'product_variant_id']);
            $table->dropConstrainedForeignId('product_variant_id');
        });

        Schema::dropIfExists('product_variants');
    }
};
