import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { formatYen } from '@/lib/format';
import { show as showProduct } from '@/routes/shop/products';
import type { CreatorSummary, ShopProductSummary } from '@/types';

export function ProductCard({
    product,
}: {
    product: ShopProductSummary & { creator: CreatorSummary };
}) {
    return (
        <Link
            href={showProduct({
                creator: product.creator.slug,
                product: product.id,
            })}
            className="flex flex-col overflow-hidden rounded-xl border bg-card transition-colors hover:bg-muted/40"
        >
            <div className="aspect-square bg-muted">
                {product.cover_url && (
                    <img
                        src={product.cover_url}
                        alt=""
                        loading="lazy"
                        className="size-full object-cover"
                    />
                )}
            </div>
            <div className="space-y-1 p-3">
                <p className="line-clamp-2 font-medium">{product.name}</p>
                <p className="truncate text-xs text-muted-foreground">
                    {product.creator.display_name}
                </p>
                <p className="flex items-center gap-2 text-sm">
                    {formatYen(product.price)}
                    {product.kind === 'digital' && (
                        <Badge variant="outline">デジタル</Badge>
                    )}
                    {product.sold_out && (
                        <Badge variant="secondary">売り切れ</Badge>
                    )}
                </p>
            </div>
        </Link>
    );
}
