import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { OrderStatusBadge } from '@/components/order-status-badge';
import { OrderLines, OrderTotals } from '@/components/order-totals';
import { formatDate, formatYen } from '@/lib/format';
import { show } from '@/routes/creators';
import { index } from '@/routes/orders';
import type { CreatorSummary, OrderItemData, OrderStatusValue } from '@/types';

type Order = {
    id: number;
    status: OrderStatusValue;
    status_label: string;
    items: OrderItemData[];
    has_physical: boolean;
    subtotal: number;
    shipping_fee: number;
    support_amount: number;
    total: number;
    shipping_name: string | null;
    shipping_postal_code: string | null;
    shipping_address: string | null;
    shipping_phone: string | null;
    tracking_number: string | null;
    refunded_amount: number;
    created_at: string;
    creator: CreatorSummary | null;
    contact_email: string | null;
};

export default function OrderShow({ order }: { order: Order }) {
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
                    {formatDate(order.created_at)}
                    {order.creator && (
                        <>
                            {' · '}
                            <Link
                                href={show(order.creator.slug)}
                                className="underline"
                            >
                                {order.creator.display_name}
                            </Link>
                        </>
                    )}
                </p>

                {order.status === 'pending' && (
                    <p className="rounded-xl border p-4 text-sm">
                        お支払いをお待ちしています。
                    </p>
                )}

                <OrderLines items={order.items} />
                <OrderTotals
                    subtotal={order.subtotal}
                    shippingFee={order.shipping_fee}
                    supportAmount={order.support_amount}
                    total={order.total}
                />

                {order.status === 'refunded' && (
                    <p className="text-sm">
                        返金額: {formatYen(order.refunded_amount)}
                    </p>
                )}

                {order.has_physical && order.shipping_name && (
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
                )}

                {order.tracking_number && (
                    <section className="space-y-1 text-sm">
                        <h2 className="font-medium">お問い合わせ番号</h2>
                        <p>{order.tracking_number}</p>
                    </section>
                )}

                {order.contact_email && (
                    <section className="space-y-1 text-sm">
                        <h2 className="font-medium">お問い合わせ先</h2>
                        <a
                            href={`mailto:${order.contact_email}`}
                            className="underline"
                        >
                            {order.contact_email}
                        </a>
                    </section>
                )}
            </div>
        </>
    );
}

OrderShow.layout = {
    breadcrumbs: [{ title: '注文履歴', href: index() }],
};
