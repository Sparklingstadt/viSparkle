import { Head, Link, router } from '@inertiajs/react';
import { Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { formatYen } from '@/lib/format';
import { confirm } from '@/routes/cart';
import { destroy, update } from '@/routes/cart/items';
import { show } from '@/routes/creators';
import { show as showProduct } from '@/routes/shop/products';
import type { CartGroup, CartLine } from '@/types';

const onError = (errors: Record<string, string>) =>
    toast.error(errors.cart ?? errors.quantity ?? '処理に失敗しました。');

function Line({ group, item }: { group: CartGroup; item: CartLine }) {
    const digital = item.kind === 'digital';
    const soldOut = !digital && item.stock < item.quantity;

    return (
        <li className="flex gap-3 p-4">
            <div className="size-16 shrink-0 overflow-hidden rounded-md bg-muted">
                {item.cover_url && (
                    <img
                        src={item.cover_url}
                        alt=""
                        className="size-full object-cover"
                    />
                )}
            </div>
            <div className="min-w-0 flex-1 space-y-1">
                {item.listed ? (
                    <Link
                        href={showProduct({
                            creator: group.creator.slug,
                            product: item.product_id,
                        })}
                        className="font-medium hover:underline"
                    >
                        {item.name}
                    </Link>
                ) : (
                    <p className="font-medium">{item.name}</p>
                )}
                <p className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                    {formatYen(item.price)}
                    {digital && <Badge variant="outline">デジタル</Badge>}
                    {!item.listed && (
                        <Badge variant="destructive">現在購入できません</Badge>
                    )}
                    {item.listed && soldOut && (
                        <Badge variant="destructive">
                            {item.stock < 1 ? '売り切れ' : '在庫が足りません'}
                        </Badge>
                    )}
                </p>
            </div>
            <div className="flex items-start gap-2">
                {!digital && (
                    <Input
                        type="number"
                        min={1}
                        defaultValue={item.quantity}
                        aria-label={`${item.name} の数量`}
                        className="w-20"
                        onBlur={(event) => {
                            const quantity = Math.max(
                                1,
                                Number(event.target.value) || 1,
                            );

                            if (quantity === item.quantity) {
                                return;
                            }

                            router.put(
                                update(item.id).url,
                                { quantity },
                                {
                                    preserveScroll: true,
                                    onError: (errors) => {
                                        event.target.value = String(
                                            item.quantity,
                                        );
                                        onError(errors);
                                    },
                                },
                            );
                        }}
                    />
                )}
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label={`${item.name} を削除`}
                    onClick={() =>
                        router.delete(destroy(item.id).url, {
                            preserveScroll: true,
                            onError,
                        })
                    }
                >
                    <Trash2 />
                </Button>
            </div>
        </li>
    );
}

export default function Cart({ groups }: { groups: CartGroup[] }) {
    return (
        <>
            <Head title="カート" />

            <div className="mx-auto max-w-3xl space-y-6 p-4">
                <Heading title="カート" />

                {groups.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        カートに商品が入っていません。
                    </p>
                )}

                {groups.map((group) => (
                    <section
                        key={group.creator.slug}
                        className="overflow-hidden rounded-xl border"
                    >
                        <h2 className="border-b bg-muted/40 p-4 font-semibold">
                            <Link
                                href={show(group.creator.slug, {
                                    query: { tab: 'shop' },
                                })}
                                className="hover:underline"
                            >
                                {group.creator.display_name}
                            </Link>
                        </h2>
                        <ul className="divide-y">
                            {group.items.map((item) => (
                                <Line key={item.id} group={group} item={item} />
                            ))}
                        </ul>
                        <div className="space-y-3 border-t p-4">
                            <dl className="space-y-1 text-sm">
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        小計
                                    </dt>
                                    <dd>{formatYen(group.subtotal)}</dd>
                                </div>
                                <div className="flex justify-between">
                                    <dt className="text-muted-foreground">
                                        送料
                                    </dt>
                                    <dd>{formatYen(group.shipping_fee)}</dd>
                                </div>
                                <div className="flex justify-between text-base font-bold">
                                    <dt>合計</dt>
                                    <dd>{formatYen(group.total)}</dd>
                                </div>
                            </dl>
                            {group.purchasable ? (
                                <Button asChild className="w-full">
                                    <Link href={confirm(group.creator.slug)}>
                                        この商品を購入
                                    </Link>
                                </Button>
                            ) : (
                                <Button disabled className="w-full">
                                    現在購入できません
                                </Button>
                            )}
                        </div>
                    </section>
                ))}
            </div>
        </>
    );
}
