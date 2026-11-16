import { Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, ShoppingCart } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toastErrors } from '@/lib/toast-errors';
import { login } from '@/routes';
import { index as cartIndex } from '@/routes/cart';
import { store as storeCartItem } from '@/routes/cart/items';
import { show as showOrder } from '@/routes/orders';

/**
 * What the viewer can do with the chosen kind: download it again when already bought, log in, or put a quantity in
 * the cart (one, for a digital work).
 */
export function PurchaseBox({
    variantId,
    stock,
    digital,
    soldOut,
    unavailable,
    isOwner,
    purchasedOrderId,
    quantity,
    onQuantityChange,
}: {
    variantId: number;
    stock: number;
    digital: boolean;
    soldOut: boolean;
    unavailable: boolean;
    isOwner: boolean;
    purchasedOrderId: number | null;
    quantity: number;
    onQuantityChange: (quantity: number) => void;
}) {
    const { auth } = usePage().props;
    const [processing, setProcessing] = useState(false);

    const addToCart = () =>
        router.post(
            storeCartItem().url,
            { product_variant_id: variantId, quantity },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => toast.success('カートに入れました。'),
                onError: toastErrors('cart', 'quantity'),
            },
        );

    if (purchasedOrderId !== null) {
        return (
            <div className="space-y-2">
                <p className="flex items-center gap-2 text-sm font-medium">
                    <CheckCircle2 className="size-4" />
                    購入済み
                </p>
                <Button asChild className="w-full">
                    <Link href={showOrder(purchasedOrderId)}>
                        注文の画面でダウンロードする
                    </Link>
                </Button>
            </div>
        );
    }

    if (unavailable) {
        return (
            <Button disabled className="w-full">
                現在購入できません
            </Button>
        );
    }

    if (!auth.user) {
        return (
            <Button asChild className="w-full">
                <Link href={login()}>ログインしてカートに入れる</Link>
            </Button>
        );
    }

    return (
        <div className="space-y-2">
            {!digital && (
                <div className="flex items-center gap-2">
                    <label
                        htmlFor="quantity"
                        className="text-sm text-muted-foreground"
                    >
                        数量
                    </label>
                    <Input
                        id="quantity"
                        type="number"
                        min={1}
                        max={Math.max(stock, 1)}
                        value={quantity}
                        onChange={(event) =>
                            onQuantityChange(
                                Math.max(1, Number(event.target.value) || 1),
                            )
                        }
                        disabled={soldOut || isOwner}
                        className="w-24"
                    />
                </div>
            )}
            <Button
                size="lg"
                className="h-12 w-full text-base"
                disabled={soldOut || isOwner || processing}
                onClick={addToCart}
            >
                <ShoppingCart className="size-5" />
                {soldOut ? '売り切れ' : 'カートに入れる'}
            </Button>
            {isOwner && (
                <p className="text-xs text-muted-foreground">
                    自分の商品は購入できません。
                </p>
            )}
            <Button asChild variant="ghost" size="sm" className="w-full">
                <Link href={cartIndex()}>カートを見る</Link>
            </Button>
        </div>
    );
}
