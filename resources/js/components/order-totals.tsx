import { formatYen } from '@/lib/format';
import type { OrderItemData } from '@/types';

export function OrderLines({ items }: { items: OrderItemData[] }) {
    return (
        <ul className="divide-y rounded-xl border">
            {items.map((item) => (
                <li
                    key={item.id}
                    className="flex items-center justify-between gap-4 p-3 text-sm"
                >
                    <span className="min-w-0 truncate">
                        {item.product_name} × {item.quantity}
                    </span>
                    <span className="shrink-0">
                        {formatYen(item.unit_price * item.quantity)}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export function OrderTotals({
    subtotal,
    shippingFee,
    total,
}: {
    subtotal: number;
    shippingFee: number;
    total: number;
}) {
    return (
        <dl className="space-y-1 text-sm">
            <div className="flex justify-between">
                <dt className="text-muted-foreground">小計</dt>
                <dd>{formatYen(subtotal)}</dd>
            </div>
            <div className="flex justify-between">
                <dt className="text-muted-foreground">送料</dt>
                <dd>{formatYen(shippingFee)}</dd>
            </div>
            <div className="flex justify-between border-t pt-1 font-bold">
                <dt>合計</dt>
                <dd>{formatYen(total)}</dd>
            </div>
        </dl>
    );
}
