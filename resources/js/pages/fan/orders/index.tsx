import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { OrderStatusBadge } from '@/components/order-status-badge';
import { formatDate, formatYen } from '@/lib/format';
import { index, show } from '@/routes/orders';
import type { CreatorSummary, OrderSummary, Paginated } from '@/types';
import { Pagination } from '@/components/pagination';

type Row = OrderSummary & {
    creator: CreatorSummary | null;
};

export default function OrdersIndex({ orders }: { orders: Paginated<Row> }) {
    return (
        <>
            <Head title="注文履歴" />

            <div className="space-y-6 p-4">
                <Heading title="注文履歴" />

                {orders.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        まだ注文はありません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {orders.data.map((order) => (
                            <li key={order.id}>
                                <Link
                                    href={show(order.id)}
                                    className="flex items-center justify-between gap-4 p-4 hover:bg-muted"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <p className="text-xs text-muted-foreground">
                                            {formatDate(order.created_at)}
                                            {order.creator &&
                                                ` · ${order.creator.display_name}`}
                                        </p>
                                        <p className="truncate font-medium">
                                            {order.items
                                                .map(
                                                    (item) =>
                                                        `${item.product_name} × ${item.quantity}`,
                                                )
                                                .join('、')}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 flex-col items-end gap-1">
                                        <OrderStatusBadge
                                            status={order.status}
                                            label={order.status_label}
                                        />
                                        <span className="text-sm">
                                            {formatYen(order.total)}
                                        </span>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}

                <Pagination page={orders} />
            </div>
        </>
    );
}

OrdersIndex.layout = {
    breadcrumbs: [{ title: '注文履歴', href: index() }],
};
