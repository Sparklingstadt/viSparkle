import { Download } from 'lucide-react';
import { Badge } from '@/components/ui/badge';
import { formatBytes, formatYen } from '@/lib/format';
import type { OrderItemData, OrderItemDownloads } from '@/types';

function Downloads({ downloads }: { downloads: OrderItemDownloads }) {
    if (downloads.refusal !== null) {
        return (
            <p className="text-xs text-muted-foreground">{downloads.refusal}</p>
        );
    }

    return (
        <ul className="space-y-1">
            {downloads.files.map((file) => (
                <li key={file.id}>
                    <a
                        href={file.url}
                        className="flex items-center gap-2 rounded-md border px-3 py-2 hover:bg-muted"
                    >
                        <Download className="size-4 shrink-0" />
                        <span className="min-w-0 flex-1 truncate">
                            {file.original_name}
                        </span>
                        <span className="shrink-0 text-xs text-muted-foreground">
                            {formatBytes(file.size)}
                        </span>
                        <span className="shrink-0 text-xs underline">
                            ダウンロード
                        </span>
                    </a>
                </li>
            ))}
        </ul>
    );
}

export function OrderLines({ items }: { items: OrderItemData[] }) {
    return (
        <ul className="divide-y rounded-xl border">
            {items.map((item) => {
                const digital = item.product_kind === 'digital';

                return (
                    <li key={item.id} className="space-y-2 p-3 text-sm">
                        <div className="flex items-center justify-between gap-4">
                            <span className="flex min-w-0 items-center gap-2">
                                <span className="truncate">
                                    {item.product_name}
                                    {!digital && ` × ${item.quantity}`}
                                </span>
                                {digital && (
                                    <Badge variant="outline">デジタル</Badge>
                                )}
                            </span>
                            <span className="shrink-0">
                                {formatYen(item.unit_price * item.quantity)}
                            </span>
                        </div>
                        {item.downloads && (
                            <Downloads downloads={item.downloads} />
                        )}
                    </li>
                );
            })}
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
