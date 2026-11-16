import { Head } from '@inertiajs/react';
import { CategoryLinks } from '@/components/category-links';
import { ProductCard } from '@/components/product-card';
import { show } from '@/routes/categories';
import type {
    CategorySummary,
    CreatorSummary,
    Paginated,
    ShopProductSummary,
} from '@/types';
import { Pagination } from '@/components/pagination';

export default function Category({
    category,
    categories,
    products,
}: {
    category: CategorySummary;
    categories: CategorySummary[];
    products: Paginated<ShopProductSummary & { creator: CreatorSummary }>;
}) {
    return (
        <>
            <Head title={`${category.name}の商品`} />

            <div className="space-y-6">
                <h1 className="text-xl font-bold break-words">
                    {category.name}
                </h1>

                <CategoryLinks
                    categories={categories}
                    current={category.slug}
                    href={(slug) => show(slug ?? category.slug).url}
                />

                {products.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        このカテゴリの商品はまだありません。
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {products.data.map((product) => (
                            <ProductCard key={product.id} product={product} />
                        ))}
                    </div>
                )}

                <Pagination page={products} />
            </div>
        </>
    );
}
