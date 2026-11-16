import { Link } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { formatYen } from '@/lib/format';
import { show as showProduct } from '@/routes/shop/products';
import type { CreatorSummary, ShopProductSummary } from '@/types';

/**
 * A product in a grid. On a creator's own page the product carries no creator: pass `creatorSlug` instead, and the
 * creator's name is left out.
 */
export function ProductCard({
    product,
    creatorSlug,
}:
    | {
          product: ShopProductSummary & { creator: CreatorSummary };
          creatorSlug?: never;
      }
    | { product: ShopProductSummary; creatorSlug: string }) {
    const creator = 'creator' in product ? product.creator : null;

    return (
        <Link
            href={showProduct({
                creator: creator?.slug ?? (creatorSlug as string),
                product: product.id,
            })}
            className="flex flex-col overflow-hidden rounded-xl border bg-card transition-colors hover:border-foreground/30 hover:bg-muted/70"
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
                {creator && (
                    <p className="truncate text-xs text-muted-foreground">
                        {creator.display_name}
                    </p>
                )}
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
