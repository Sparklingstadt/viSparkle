import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { ProductCard } from '@/components/product-card';
import {
    ProductLikeButton,
    type LikeableProduct,
} from '@/components/product-like-button';
import { home } from '@/routes';
import { index } from '@/routes/liked-products';
import type { Paginated } from '@/types';

export default function LikedProducts({
    products,
}: {
    products: Paginated<LikeableProduct>;
}) {
    return (
        <>
            <Head title="いいねした商品" />

            <div className="space-y-6 p-4">
                <Heading
                    title="いいねした商品"
                    description="新しくいいねした順に表示しています"
                />

                {products.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        いいねした商品はまだありません。
                        <Link
                            href={home()}
                            className="underline underline-offset-4"
                        >
                            商品を探す
                        </Link>
                    </p>
                ) : (
                    <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-4">
                        {products.data.map((product) => (
                            <div key={product.id} className="relative">
                                <ProductCard product={product} />
                                <ProductLikeButton
                                    product={product}
                                    className="absolute right-1.5 bottom-1.5"
                                />
                            </div>
                        ))}
                    </div>
                )}

                <div className="flex justify-between">
                    {products.prev_page_url ? (
                        <Link href={products.prev_page_url}>← 前へ</Link>
                    ) : (
                        <span />
                    )}
                    {products.next_page_url && (
                        <Link href={products.next_page_url}>次へ →</Link>
                    )}
                </div>
            </div>
        </>
    );
}

LikedProducts.layout = {
    breadcrumbs: [{ title: 'いいねした商品', href: index() }],
};
