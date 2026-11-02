import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { OrderStatusBadge } from '@/components/order-status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatYen } from '@/lib/format';
import { index, show } from '@/routes/creator/orders';
import type { OrderStatusValue, Paginated } from '@/types';

type Row = {
    id: number;
    status: OrderStatusValue;
    status_label: string;
    total: number;
    created_at: string;
    buyer_name: string | null;
    shipping_name: string | null;
    items: { id: number; product_name: string; quantity: number }[];
};

export default function CreatorOrdersIndex({
    orders,
    status,
    statuses,
}: {
    orders: Paginated<Row>;
    status: OrderStatusValue | null;
    statuses: { value: OrderStatusValue; label: string }[];
}) {
    return (
        <>
            <Head title="注文管理" />

            <div className="space-y-6 p-4">
                <Heading title="注文管理" />

                <div className="flex flex-wrap gap-2">
                    <Button
                        asChild
                        size="sm"
                        variant={status === null ? 'default' : 'outline'}
                    >
                        <Link href={index()}>すべて</Link>
                    </Button>
                    {statuses.map((option) => (
                        <Button
                            key={option.value}
                            asChild
                            size="sm"
                            variant={
                                status === option.value ? 'default' : 'outline'
                            }
                        >
                            <Link
                                href={index({
                                    query: { status: option.value },
                                })}
                            >
                                {option.label}
                            </Link>
                        </Button>
                    ))}
                </div>

                {orders.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        該当する注文はありません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {orders.data.map((order) => (
                            <li key={order.id}>
                                <Link
                                    href={show(order.id)}
                                    className="flex items-center justify-between gap-4 p-4 hover:bg-muted/50"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <p className="text-xs text-muted-foreground">
                                            #{order.id} ·{' '}
                                            {formatDate(order.created_at)} ·{' '}
                                            {order.shipping_name ??
                                                order.buyer_name ??
                                                '（退会したユーザー）'}
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

                <div className="flex justify-between">
                    {orders.prev_page_url ? (
                        <Link href={orders.prev_page_url}>← 前へ</Link>
                    ) : (
                        <span />
                    )}
                    {orders.next_page_url && (
                        <Link href={orders.next_page_url}>次へ →</Link>
                    )}
                </div>
            </div>
        </>
    );
}

CreatorOrdersIndex.layout = {
    breadcrumbs: [{ title: '注文管理', href: index() }],
};
