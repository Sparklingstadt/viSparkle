import { Link } from '@inertiajs/react';
import { ProductCard } from '@/components/product-card';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { show } from '@/routes/creators';
import type { ShopProductSummary } from '@/types';

export type ShopSort = 'new' | 'popular';

export type CreatorShop = {
    visible: boolean;
    notice: string | null;
    sort: ShopSort;
    products: ShopProductSummary[];
};

const sortTabs: { label: string; sort: ShopSort }[] = [
    { label: '公開順', sort: 'new' },
    { label: '人気順', sort: 'popular' },
];

/** The creator's shop tab: the sort, the shop's notice and the products. */
export function ShopSection({
    creatorSlug,
    shop,
    canBuy,
}: {
    creatorSlug: string;
    shop: CreatorShop;
    canBuy: boolean;
}) {
    return (
        <section className="space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h2 className="text-lg font-semibold">ショップ</h2>
                <div className="flex gap-1" role="tablist">
                    {sortTabs.map((sortTab) => (
                        <Button
                            key={sortTab.sort}
                            size="sm"
                            variant="ghost"
                            asChild
                            className={cn({
                                'bg-muted': sortTab.sort === shop.sort,
                            })}
                        >
                            <Link
                                href={show(creatorSlug, {
                                    query: {
                                        tab: 'shop',
                                        ...(sortTab.sort === 'popular'
                                            ? { sort: 'popular' }
                                            : {}),
                                    },
                                })}
                                role="tab"
                                aria-selected={sortTab.sort === shop.sort}
                                preserveScroll
                                only={['shop']}
                            >
                                {sortTab.label}
                            </Link>
                        </Button>
                    ))}
                </div>
            </div>
            {shop.notice && (
                <p className="rounded-md border bg-muted p-4 text-sm whitespace-pre-wrap">
                    {shop.notice}
                </p>
            )}
            {!canBuy && (
                <p className="text-sm text-muted-foreground">
                    現在購入できません
                </p>
            )}
            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                {shop.products.map((product) => (
                    <ProductCard
                        key={product.id}
                        product={product}
                        creatorSlug={creatorSlug}
                    />
                ))}
            </div>
        </section>
    );
}
