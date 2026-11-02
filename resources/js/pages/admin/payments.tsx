import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate, formatYen } from '@/lib/format';
import { refund as refundOrder } from '@/routes/admin/orders';
import { index, refund } from '@/routes/admin/payments';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    paid_at: string;
    creator: { name: string; slug: string } | null;
    supporter: { name: string; email: string } | null;
    amount: number;
    refunded_amount: number;
    refunded: boolean;
    disputed: boolean;
    support_active: boolean;
};

type OrderRow = {
    id: number;
    paid_at: string | null;
    creator: { name: string; slug: string } | null;
    buyer: { name: string; email: string } | null;
    total: number;
    has_digital: boolean;
    has_physical: boolean;
    status: string;
    status_label: string;
    refundable: boolean;
};

export default function Payments({
    payments,
    orders,
    filters,
}: {
    payments: Paginated<Row>;
    orders: Paginated<OrderRow>;
    filters: { creator: string | null; user: string | null };
}) {
    const [refunding, setRefunding] = useState<Row | null>(null);
    const [cancelSupport, setCancelSupport] = useState(false);

    const openRefund = (payment: Row) => {
        setCancelSupport(false);
        setRefunding(payment);
    };

    const refundOrderRow = (order: OrderRow) => {
        if (
            !window.confirm(
                `注文 #${order.id}（${formatYen(order.total)}）を返金しますか？`,
            )
        ) {
            return;
        }

        router.post(
            refundOrder(order.id).url,
            {},
            {
                preserveScroll: true,
                onError: (errors) =>
                    toast.error(errors.order ?? '処理に失敗しました。'),
            },
        );
    };

    return (
        <>
            <Head title="支払い" />

            <div className="space-y-6">
                <Heading title="支払い" />

                <Form
                    {...index.form()}
                    className="flex flex-wrap items-end gap-3"
                >
                    <div className="grid gap-1">
                        <Label htmlFor="filter-creator">
                            クリエイター（slug）
                        </Label>
                        <Input
                            id="filter-creator"
                            name="creator"
                            defaultValue={filters.creator ?? ''}
                        />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="filter-user">
                            支援者（メールアドレス）
                        </Label>
                        <Input
                            id="filter-user"
                            name="user"
                            type="email"
                            defaultValue={filters.user ?? ''}
                        />
                    </div>
                    <Button type="submit" variant="secondary">
                        絞り込む
                    </Button>
                    {(filters.creator || filters.user) && (
                        <Button variant="ghost" asChild>
                            <Link href={index()}>解除</Link>
                        </Button>
                    )}
                </Form>

                {payments.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        支払いはありません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {payments.data.map((payment) => (
                            <li
                                key={payment.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div className="space-y-1 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {formatYen(payment.amount)}
                                        </span>
                                        {payment.refunded && (
                                            <Badge variant="secondary">
                                                返金済み
                                            </Badge>
                                        )}
                                        {payment.disputed && (
                                            <Badge variant="destructive">
                                                異議申し立て
                                            </Badge>
                                        )}
                                        <span className="text-muted-foreground">
                                            {formatDate(payment.paid_at)}
                                        </span>
                                    </div>
                                    <p>
                                        {payment.creator?.name ??
                                            '（削除済み）'}
                                        {' ← '}
                                        {payment.supporter
                                            ? `${payment.supporter.name}（${payment.supporter.email}）`
                                            : '（退会済み）'}
                                    </p>
                                    {payment.refunded_amount > 0 &&
                                        !payment.refunded && (
                                            <p className="text-xs text-muted-foreground">
                                                返金額{' '}
                                                {formatYen(
                                                    payment.refunded_amount,
                                                )}
                                            </p>
                                        )}
                                </div>

                                {!payment.refunded && (
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        onClick={() => openRefund(payment)}
                                    >
                                        返金
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="flex justify-between">
                    {payments.prev_page_url ? (
                        <Link href={payments.prev_page_url}>← 前へ</Link>
                    ) : (
                        <span />
                    )}
                    {payments.next_page_url && (
                        <Link href={payments.next_page_url}>次へ →</Link>
                    )}
                </div>

                <section className="space-y-3">
                    <h2 className="font-medium">ショップの注文</h2>
                    {orders.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            注文はありません。
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {orders.data.map((order) => (
                                <li
                                    key={order.id}
                                    className="flex flex-wrap items-center justify-between gap-3 p-4"
                                >
                                    <div className="space-y-1 text-sm">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span className="font-medium">
                                                注文 #{order.id}
                                            </span>
                                            <span className="font-medium">
                                                {formatYen(order.total)}
                                            </span>
                                            <Badge
                                                variant={
                                                    order.status === 'refunded'
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                            >
                                                {order.status_label}
                                            </Badge>
                                            {order.has_physical && (
                                                <Badge variant="outline">
                                                    グッズ
                                                </Badge>
                                            )}
                                            {order.has_digital && (
                                                <Badge variant="outline">
                                                    デジタル
                                                </Badge>
                                            )}
                                            <span className="text-muted-foreground">
                                                {formatDate(order.paid_at)}
                                            </span>
                                        </div>
                                        <p className="text-muted-foreground">
                                            {order.creator
                                                ? `${order.creator.name}（@${order.creator.slug}）`
                                                : '（削除済み）'}{' '}
                                            ←{' '}
                                            {order.buyer
                                                ? `${order.buyer.name}（${order.buyer.email}）`
                                                : '（退会済み）'}
                                        </p>
                                    </div>
                                    {order.refundable && (
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={() =>
                                                refundOrderRow(order)
                                            }
                                        >
                                            返金
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
                </section>
            </div>

            <Dialog
                open={refunding !== null}
                onOpenChange={(open) => !open && setRefunding(null)}
            >
                <DialogContent>
                    {refunding && (
                        <>
                            <DialogTitle>返金する</DialogTitle>
                            <DialogDescription>
                                {formatYen(
                                    refunding.amount -
                                        refunding.refunded_amount,
                                )}
                                を全額返金します。クリエイターへの送金と運営手数料も取り消されます。元に戻せません。
                            </DialogDescription>

                            <Form
                                {...refund.form(refunding.id)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setRefunding(null)}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="cancel_support"
                                            value={cancelSupport ? '1' : '0'}
                                        />
                                        {refunding.support_active && (
                                            <div className="flex items-center gap-2">
                                                <Checkbox
                                                    id="cancel-support"
                                                    checked={cancelSupport}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        setCancelSupport(
                                                            checked === true,
                                                        )
                                                    }
                                                />
                                                <Label htmlFor="cancel-support">
                                                    支援も解約する
                                                </Label>
                                            </div>
                                        )}
                                        <InputError message={errors.payment} />
                                        <InputError
                                            message={errors.cancel_support}
                                        />
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                >
                                                    キャンセル
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                返金する
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

Payments.layout = {
    breadcrumbs: [{ title: '支払い', href: index() }],
};
