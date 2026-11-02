import { Head, router, useForm } from '@inertiajs/react';
import { toast } from 'sonner';
import OrderController from '@/actions/App/Http/Controllers/Creator/OrderController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { OrderStatusBadge } from '@/components/order-status-badge';
import { OrderLines, OrderTotals } from '@/components/order-totals';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatYen } from '@/lib/format';
import { index } from '@/routes/creator/orders';
import type { OrderItemData, OrderStatusValue } from '@/types';

type Order = {
    id: number;
    status: OrderStatusValue;
    status_label: string;
    items: OrderItemData[];
    subtotal: number;
    shipping_fee: number;
    total: number;
    buyer_name: string | null;
    shipping_name: string | null;
    shipping_postal_code: string | null;
    shipping_address: string | null;
    shipping_phone: string | null;
    tracking_number: string | null;
    refunded_amount: number;
    created_at: string;
    can_ship: boolean;
    can_refund: boolean;
};

export default function CreatorOrderShow({ order }: { order: Order }) {
    const form = useForm({ tracking_number: '' });

    return (
        <>
            <Head title={`注文 ${order.id}`} />

            <div className="max-w-2xl space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <Heading title={`注文番号 ${order.id}`} />
                    <OrderStatusBadge
                        status={order.status}
                        label={order.status_label}
                    />
                </div>

                <p className="text-sm text-muted-foreground">
                    {formatDate(order.created_at)} ·{' '}
                    {order.buyer_name ?? '（退会したユーザー）'}
                </p>

                <OrderLines items={order.items} />
                <OrderTotals
                    subtotal={order.subtotal}
                    shippingFee={order.shipping_fee}
                    total={order.total}
                />

                {order.status === 'refunded' && (
                    <p className="text-sm">
                        返金額: {formatYen(order.refunded_amount)}
                    </p>
                )}

                <section className="space-y-1 text-sm">
                    <h2 className="font-medium">お届け先</h2>
                    <p>{order.shipping_name}</p>
                    <p>
                        {order.shipping_postal_code &&
                            `〒${order.shipping_postal_code} `}
                        {order.shipping_address}
                    </p>
                    {order.shipping_phone && <p>{order.shipping_phone}</p>}
                </section>

                {order.tracking_number && (
                    <section className="space-y-1 text-sm">
                        <h2 className="font-medium">お問い合わせ番号</h2>
                        <p>{order.tracking_number}</p>
                    </section>
                )}

                {order.can_ship && (
                    <form
                        className="space-y-2 border-t pt-6"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(OrderController.ship.url(order.id), {
                                preserveScroll: true,
                                onError: (errors) =>
                                    toast.error(
                                        errors.order ?? '処理に失敗しました。',
                                    ),
                            });
                        }}
                    >
                        <Label htmlFor="tracking_number">
                            お問い合わせ番号（任意）
                        </Label>
                        <Input
                            id="tracking_number"
                            value={form.data.tracking_number}
                            maxLength={100}
                            onChange={(event) =>
                                form.setData(
                                    'tracking_number',
                                    event.target.value,
                                )
                            }
                        />
                        <InputError message={form.errors.tracking_number} />
                        <Button type="submit" disabled={form.processing}>
                            発送済みにする
                        </Button>
                    </form>
                )}

                {order.can_refund && (
                    <div className="border-t pt-6">
                        <Button
                            variant="destructive"
                            onClick={() => {
                                if (
                                    confirm(
                                        'この注文を取り消して全額を返金しますか？在庫は戻ります。',
                                    )
                                ) {
                                    router.post(
                                        OrderController.refund.url(order.id),
                                        {},
                                        {
                                            preserveScroll: true,
                                            onError: (errors) =>
                                                toast.error(
                                                    errors.order ??
                                                        '処理に失敗しました。',
                                                ),
                                        },
                                    );
                                }
                            }}
                        >
                            取り消して返金
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}

CreatorOrderShow.layout = {
    breadcrumbs: [{ title: '注文管理', href: index() }],
};
