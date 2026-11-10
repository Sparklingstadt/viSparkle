import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, ShoppingCart } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import {
    ReportDialog,
    type ReportReasonOption,
} from '@/components/report-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatBytes, formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';
import { login } from '@/routes';
import { index as cartIndex } from '@/routes/cart';
import { store as storeCartItem } from '@/routes/cart/items';
import { show as showCategory } from '@/routes/categories';
import { show } from '@/routes/creators';
import { show as showOrder } from '@/routes/orders';
import type { CategorySummary, CreatorSummary, ProductKind } from '@/types';

export default function ProductPage({
    creator,
    product,
    shipping_fee: shippingFee,
    shop_notice: shopNotice,
    can_buy: canBuy,
    is_owner: isOwner,
    purchased_order_id: purchasedOrderId,
    reportReasons,
}: {
    creator: CreatorSummary;
    product: {
        id: number;
        kind: ProductKind;
        name: string;
        category: CategorySummary | null;
        description: string | null;
        price: number;
        stock: number;
        variants: { id: number; name: string | null; stock: number }[];
        images: string[];
        purchasable: boolean;
        files: { extension: string; size: number }[];
        files_total_size: number;
    };
    purchased_order_id: number | null;
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
    const kinds = product.variants.length > 1;
    // Start on the first kind still in stock (or the first one, when all are gone).
    const [variantId, setVariantId] = useState(
        () =>
            (product.variants.find((v) => v.stock > 0) ?? product.variants[0])
                .id,
    );
    const variant =
        product.variants.find((v) => v.id === variantId) ?? product.variants[0];

    const digital = product.kind === 'digital';
    const soldOut = !digital && variant.stock < 1;
    const unavailable = !canBuy || (digital && !product.purchasable);
    const extensions = [
        ...new Set(product.files.map((file) => file.extension.toUpperCase())),
    ];

    const addToCart = () =>
        router.post(
            storeCartItem().url,
            { product_variant_id: variant.id, quantity },
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

                <div className="grid gap-10 md:grid-cols-2">
                    <div className="space-y-2">
                        <div className="aspect-square overflow-hidden rounded-3xl bg-secondary">
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

                    <div className="space-y-6">
                        <div className="space-y-2">
                            <div className="flex flex-wrap gap-1">
                                {digital && (
                                    <Badge variant="outline">デジタル</Badge>
                                )}
                                {product.category && (
                                    <Badge variant="secondary" asChild>
                                        <Link
                                            href={showCategory(
                                                product.category.slug,
                                            )}
                                        >
                                            {product.category.name}
                                        </Link>
                                    </Badge>
                                )}
                            </div>
                            <h1 className="text-3xl leading-snug font-black">
                                {product.name}
                            </h1>
                        </div>
                        <p className="text-3xl font-bold">
                            {formatYen(product.price)}
                            <span className="text-sm font-normal text-muted-foreground">
                                {' '}
                                税込
                            </span>
                        </p>

                        {kinds && (
                            <div className="space-y-2">
                                <p className="text-sm font-bold">種類</p>
                                <div
                                    role="radiogroup"
                                    aria-label="種類"
                                    className="grid grid-cols-3 gap-2 sm:grid-cols-4"
                                >
                                    {product.variants.map((v) => (
                                        <button
                                            key={v.id}
                                            type="button"
                                            role="radio"
                                            aria-checked={v.id === variant.id}
                                            onClick={() => {
                                                setVariantId(v.id);
                                                setQuantity(1);
                                            }}
                                            className={cn(
                                                'flex min-h-14 flex-col items-center justify-center gap-0.5 rounded-2xl px-2 py-2 text-center transition-colors',
                                                v.id === variant.id
                                                    ? 'border-2 border-primary bg-secondary'
                                                    : v.stock < 1
                                                      ? 'border border-dashed bg-muted/50 text-muted-foreground'
                                                      : 'border hover:border-primary/50',
                                            )}
                                        >
                                            <span
                                                className={cn(
                                                    'text-sm font-bold',
                                                    v.stock < 1 &&
                                                        'line-through',
                                                )}
                                            >
                                                {v.name}
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {v.stock < 1
                                                    ? '売り切れ'
                                                    : v.stock <= 3
                                                      ? `残り ${v.stock}`
                                                      : '在庫あり'}
                                            </span>
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        {shopNotice && (
                            <p className="rounded-md border bg-muted p-3 text-sm whitespace-pre-wrap">
                                {shopNotice}
                            </p>
                        )}

                        {purchasedOrderId !== null ? (
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
                        ) : unavailable ? (
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
                                            max={Math.max(variant.stock, 1)}
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
                        {digital ? (
                            <dl className="grid grid-cols-[auto_1fr] gap-x-5 gap-y-2 rounded-2xl bg-secondary px-5 py-4 text-sm">
                                <div className="contents">
                                    <dt className="text-muted-foreground">
                                        お渡し
                                    </dt>
                                    <dd>ダウンロード販売</dd>
                                </div>
                                <div className="contents">
                                    <dt className="text-muted-foreground">
                                        ファイル
                                    </dt>
                                    <dd>
                                        {product.files.length} 件（合計{' '}
                                        {formatBytes(product.files_total_size)}
                                        ）
                                        {extensions.length > 0 &&
                                            ` ・ ${extensions.join(' / ')}`}
                                    </dd>
                                </div>
                            </dl>
                        ) : (
                            <dl className="grid grid-cols-[auto_1fr] gap-x-5 gap-y-2 rounded-2xl bg-secondary px-5 py-4 text-sm">
                                <div className="contents">
                                    <dt className="text-muted-foreground">
                                        送料
                                    </dt>
                                    <dd>{formatYen(shippingFee)}</dd>
                                </div>
                                <div className="contents">
                                    <dt className="text-muted-foreground">
                                        在庫
                                    </dt>
                                    <dd>
                                        {soldOut
                                            ? '売り切れ'
                                            : `${variant.stock} 点`}
                                    </dd>
                                </div>
                            </dl>
                        )}

                        {product.description && (
                            <section className="space-y-2 border-t pt-5">
                                <h2 className="font-bold">商品について</h2>
                                <p className="leading-relaxed whitespace-pre-wrap">
                                    {product.description}
                                </p>
                            </section>
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
