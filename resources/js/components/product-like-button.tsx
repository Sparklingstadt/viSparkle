import { router, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import ProductLikeController from '@/actions/App/Http/Controllers/Fan/ProductLikeController';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import type { CreatorSummary, ShopProductSummary } from '@/types';

export type LikeableProduct = ShopProductSummary & {
    creator: CreatorSummary;
    liked: boolean;
    can_like: boolean;
};

// Flips at once on click; the server's answer replaces it, and a failure puts the old state back.
export function ProductLikeButton({
    product,
    className,
}: {
    product: LikeableProduct;
    className?: string;
}) {
    const { auth } = usePage().props;
    const [pending, setPending] = useState<boolean | null>(null);

    const liked = pending ?? product.liked;
    const count =
        product.like_count +
        (pending === null ? 0 : Number(pending) - Number(product.liked));

    const toggle = () => {
        if (!auth.user) {
            router.visit(login());

            return;
        }

        const args = { creator: product.creator.slug, product: product.id };
        const options = {
            preserveScroll: true,
            onError: () => toast.error('いいねできませんでした。'),
            onFinish: () => setPending(null),
        };

        setPending(!liked);

        if (liked) {
            router.delete(ProductLikeController.destroy.url(args), options);
        } else {
            router.post(ProductLikeController.store.url(args), {}, options);
        }
    };

    return (
        <Button
            variant="ghost"
            size="sm"
            onClick={toggle}
            disabled={pending !== null || !product.can_like}
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
