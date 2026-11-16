import { Heart } from 'lucide-react';
import ProductLikeController from '@/actions/App/Http/Controllers/Fan/ProductLikeController';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useOptimisticLike } from '@/hooks/use-optimistic-like';
import type { CreatorSummary, ShopProductSummary } from '@/types';

export type LikeableProduct = ShopProductSummary & {
    creator: CreatorSummary;
    liked: boolean;
    can_like: boolean;
};

export function ProductLikeButton({
    product,
    className,
}: {
    product: LikeableProduct;
    className?: string;
}) {
    const args = { creator: product.creator.slug, product: product.id };
    const { liked, count, pending, toggle } = useOptimisticLike({
        liked: product.liked,
        count: product.like_count,
        storeUrl: ProductLikeController.store.url(args),
        destroyUrl: ProductLikeController.destroy.url(args),
    });

    return (
        <Button
            variant="ghost"
            size="sm"
            onClick={toggle}
            disabled={pending || !product.can_like}
            aria-pressed={liked}
            aria-label={`${liked ? 'いいねを取り消す' : 'いいねする'}（${count}件）`}
            data-test="product-like-button"
            className={cn('h-7 gap-1 px-2 disabled:opacity-100', className)}
        >
            <Heart className={liked ? 'fill-current text-support' : ''} />
            <span>{count}</span>
        </Button>
    );
}
