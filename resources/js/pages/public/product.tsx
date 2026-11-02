import { Head, Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    ReportDialog,
    type ReportReasonOption,
} from '@/components/report-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatYen } from '@/lib/format';
import { login } from '@/routes';
import { index as cartIndex } from '@/routes/cart';
import { store as storeCartItem } from '@/routes/cart/items';
import { show } from '@/routes/creators';
import type { CreatorSummary } from '@/types';

export default function ProductPage({
    creator,
    product,
    shipping_fee: shippingFee,
    shop_notice: shopNotice,
    can_buy: canBuy,
    is_owner: isOwner,
    reportReasons,
}: {
    creator: CreatorSummary;
    product: {
        id: number;
        name: string;
        description: string | null;
        price: number;
        stock: number;
        images: string[];
    };
    shipping_fee: number;
    shop_notice: string | null;
    can_buy: boolean;
    is_owner: boolean;
    reportReasons: ReportReasonOption[];
}) {
    const { auth } = usePage().props;
    const [image, setImage] = useState(0);
    const [quantity, setQuantity] = useState(1);
    const [processing, setProcessing] = useState(false);

    const soldOut = product.stock < 1;
    const unavailable = !canBuy;

    const addToCart = () =>
        router.post(
            storeCartItem().url,
            { product_id: product.id, quantity },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: () => toast.success('カートに入れました。'),
                onError: (errors) =>
                    toast.error(
                        errors.cart ??
                            errors.quantity ??
                            '処理に失敗しました。',
                    ),
            },
        );

    return (
        <>
            <Head title={product.name} />

            <div className="mx-auto max-w-4xl space-y-6">
                <Link
                    href={show(creator.slug, { query: { tab: 'shop' } })}
                    className="text-sm text-muted-foreground hover:underline"
                >
                    ← {creator.display_name} さんのショップ
                </Link>

                <div className="grid gap-8 md:grid-cols-2">
                    <div className="space-y-2">
                        <div className="aspect-square overflow-hidden rounded-xl border bg-muted">
                            {product.images[image] && (
                                <img
                                    src={product.images[image]}
                                    alt=""
                                    className="size-full object-cover"
                                />
                            )}
                        </div>
                        {product.images.length > 1 && (
                            <div className="flex flex-wrap gap-2">
                                {product.images.map((url, index) => (
                                    <button
                                        key={url}
                                        type="button"
                                        onClick={() => setImage(index)}
                                        aria-label={`画像 ${index + 1}`}
                                        className={`size-16 overflow-hidden rounded-md border ${
                                            index === image
                                                ? 'ring-2 ring-primary'
                                                : ''
                                        }`}
                                    >
                                        <img
                                            src={url}
                                            alt=""
                                            className="size-full object-cover"
                                        />
                                    </button>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="space-y-4">
                        <h1 className="text-2xl font-bold">{product.name}</h1>
                        <p className="text-2xl font-bold">
                            {formatYen(product.price)}
                            <span className="text-sm font-normal text-muted-foreground">
                                {' '}
                                税込
                            </span>
                        </p>
                        <dl className="space-y-1 text-sm">
                            <div className="flex gap-4">
                                <dt className="w-12 text-muted-foreground">
                                    送料
                                </dt>
                                <dd>{formatYen(shippingFee)}</dd>
                            </div>
                            <div className="flex gap-4">
                                <dt className="w-12 text-muted-foreground">
                                    在庫
                                </dt>
                                <dd>
                                    {soldOut
                                        ? '売り切れ'
                                        : `${product.stock} 点`}
                                </dd>
                            </div>
                        </dl>

                        {product.description && (
                            <p className="text-sm whitespace-pre-wrap">
                                {product.description}
                            </p>
                        )}

                        {shopNotice && (
                            <p className="rounded-md border bg-muted p-3 text-sm whitespace-pre-wrap">
                                {shopNotice}
                            </p>
                        )}

                        {unavailable ? (
                            <Button disabled className="w-full">
                                現在購入できません
                            </Button>
                        ) : !auth.user ? (
                            <Button asChild className="w-full">
                                <Link href={login()}>
                                    ログインしてカートに入れる
                                </Link>
                            </Button>
                        ) : (
                            <div className="space-y-2">
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
                                        max={Math.max(product.stock, 1)}
                                        value={quantity}
                                        onChange={(event) =>
                                            setQuantity(
                                                Math.max(
                                                    1,
                                                    Number(
                                                        event.target.value,
                                                    ) || 1,
                                                ),
                                            )
                                        }
                                        disabled={soldOut || isOwner}
                                        className="w-24"
                                    />
                                </div>
                                <Button
                                    className="w-full"
                                    disabled={soldOut || isOwner || processing}
                                    onClick={addToCart}
                                >
                                    {soldOut ? '売り切れ' : 'カートに入れる'}
                                </Button>
                                {isOwner && (
                                    <p className="text-xs text-muted-foreground">
                                        自分の商品は購入できません。
                                    </p>
                                )}
                                <Button
                                    asChild
                                    variant="ghost"
                                    size="sm"
                                    className="w-full"
                                >
                                    <Link href={cartIndex()}>カートを見る</Link>
                                </Button>
                            </div>
                        )}
                        <div className="flex justify-end">
                            <ReportDialog
                                targetType="product"
                                targetId={product.id}
                                reasons={reportReasons}
                                isOwner={isOwner}
                            />
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
