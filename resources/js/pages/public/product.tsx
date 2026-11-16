import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import {
    ReportDialog,
    type ReportReasonOption,
} from '@/components/report-dialog';
import { Badge } from '@/components/ui/badge';
import { formatBytes, formatYen } from '@/lib/format';
import { show as showCategory } from '@/routes/categories';
import { show } from '@/routes/creators';
import type { CategorySummary, CreatorSummary, ProductKind } from '@/types';
import { ImageGallery } from '@/components/product-page/image-gallery';
import { PurchaseBox } from '@/components/product-page/purchase-box';
import { SpecList } from '@/components/product-page/spec-list';
import {
    VariantPicker,
    type ProductVariantOption,
} from '@/components/product-page/variant-picker';

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
        variants: ProductVariantOption[];
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
    const [quantity, setQuantity] = useState(1);
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
                    <ImageGallery images={product.images} />

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
                            <VariantPicker
                                variants={product.variants}
                                value={variant.id}
                                onChange={(id) => {
                                    setVariantId(id);
                                    setQuantity(1);
                                }}
                            />
                        )}

                        {shopNotice && (
                            <p className="rounded-md border bg-muted p-3 text-sm whitespace-pre-wrap">
                                {shopNotice}
                            </p>
                        )}

                        <PurchaseBox
                            variantId={variant.id}
                            stock={variant.stock}
                            digital={digital}
                            soldOut={soldOut}
                            unavailable={unavailable}
                            isOwner={isOwner}
                            purchasedOrderId={purchasedOrderId}
                            quantity={quantity}
                            onQuantityChange={setQuantity}
                        />
                        <SpecList
                            items={
                                digital
                                    ? [
                                          ['お渡し', 'ダウンロード販売'],
                                          [
                                              'ファイル',
                                              `${product.files.length} 件（合計 ${formatBytes(product.files_total_size)}）${extensions.length > 0 ? ` ・ ${extensions.join(' / ')}` : ''}`,
                                          ],
                                      ]
                                    : [
                                          ['送料', formatYen(shippingFee)],
                                          [
                                              '在庫',
                                              soldOut
                                                  ? '売り切れ'
                                                  : `${variant.stock} 点`,
                                          ],
                                      ]
                            }
                        />

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
