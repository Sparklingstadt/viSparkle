import { Head, Link, router } from '@inertiajs/react';
import { Check } from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { OrderStatusBadge } from '@/components/order-status-badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index, ship, show } from '@/routes/creator/orders';
import type { OrderStatusValue, Paginated } from '@/types';

type Row = {
    id: number;
    status: OrderStatusValue;
    status_label: string;
    total: number;
    support_amount: number;
    created_at: string;
    buyer_name: string | null;
    shipping_name: string | null;
    can_ship: boolean;
    items: { id: number; product_name: string; quantity: number }[];
};

export default function CreatorOrdersIndex({
    orders,
    status,
    statuses,
    counts,
}: {
    orders: Paginated<Row>;
    status: OrderStatusValue | 'all';
    statuses: { value: OrderStatusValue; label: string }[];
    counts: Record<OrderStatusValue | 'all', number>;
}) {
    // Orders waiting to be shipped come first (the list opens there); "all" comes last.
    const tabs: { value: OrderStatusValue | 'all'; label: string }[] = [
        { value: 'paid', label: '発送待ち' },
        ...statuses.filter((option) => option.value !== 'paid'),
        { value: 'all', label: 'すべて' },
    ];
    const [shipping, setShipping] = useState<number | null>(null);
    // Without a tracking number; it can still be added from the order page.
    const markShipped = (id: number) =>
        router.post(
            ship(id).url,
            {},
            {
                preserveScroll: true,
                onStart: () => setShipping(id),
                onFinish: () => setShipping(null),
            },
        );

    return (
        <>
            <Head title="注文管理" />

            <div className="space-y-6 p-4">
                <Heading title="注文管理" />

                <nav
                    aria-label="状態で絞り込む"
                    className="flex flex-wrap gap-1 border-b"
                >
                    {tabs.map((option) => (
                        <Link
                            key={option.value}
                            href={index({
                                query:
                                    option.value === 'paid'
                                        ? {}
                                        : { status: option.value },
                            })}
                            aria-current={
                                status === option.value ? 'page' : undefined
                            }
                            className={cn(
                                '-mb-px flex items-center gap-2 border-b-[3px] px-3 py-2.5 text-sm font-bold',
                                status === option.value
                                    ? 'border-primary'
                                    : 'border-transparent text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {option.label}
                            <span
                                className={cn(
                                    'min-w-6 rounded-full px-1.5 text-center text-xs',
                                    status === option.value
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted',
                                )}
                            >
                                {counts[option.value]}
                            </span>
                        </Link>
                    ))}
                </nav>

                {status === 'paid' && (
                    <p className="text-sm text-muted-foreground">
                        支払いが終わり、発送を待っている注文です。古い順に並べています。
                    </p>
                )}

                {orders.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        該当する注文はありません。
                    </p>
                ) : (
                    <ul className="space-y-3">
                        {orders.data.map((order) => (
                            <li
                                key={order.id}
                                className="flex flex-wrap items-center gap-4 rounded-2xl border px-5 py-4"
                            >
                                <Link
                                    href={show(order.id)}
                                    className="min-w-0 flex-1 basis-72 space-y-1.5"
                                >
                                    <p className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                        <span className="font-bold text-primary">
                                            注文 #{order.id}
                                        </span>
                                        <span>
                                            {order.shipping_name ??
                                                order.buyer_name ??
                                                '（退会したユーザー）'}
                                        </span>
                                        <span>
                                            · {formatDate(order.created_at)}
                                        </span>
                                        <OrderStatusBadge
                                            status={order.status}
                                            label={order.status_label}
                                        />
                                        {order.support_amount > 0 && (
                                            <span className="rounded-full bg-support/10 px-2 py-0.5 font-bold text-support">
                                                応援{' '}
                                                {formatYen(
                                                    order.support_amount,
                                                )}{' '}
                                                つき
                                            </span>
                                        )}
                                    </p>
                                    <p className="truncate font-bold hover:underline">
                                        {order.items
                                            .map(
                                                (item) =>
                                                    `${item.product_name} × ${item.quantity}`,
                                            )
                                            .join('、')}
                                    </p>
                                </Link>
                                <span className="text-lg font-bold">
                                    {formatYen(order.total)}
                                </span>
                                {order.can_ship && (
                                    <Button
                                        disabled={shipping === order.id}
                                        onClick={() => markShipped(order.id)}
                                    >
                                        <Check />
                                        発送済みにする
                                    </Button>
                                )}
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
