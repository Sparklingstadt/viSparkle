import { Head, Link, router } from '@inertiajs/react';
import { Check, Landmark, Package, PenLine } from 'lucide-react';
import { useState } from 'react';
import EarningsController from '@/actions/App/Http/Controllers/Creator/EarningsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index } from '@/routes/creator/earnings';
import { index as orderIndex } from '@/routes/creator/orders';
import { edit as editPost } from '@/routes/creator/posts';

type AccountStatus = 'none' | 'incomplete' | 'ready';

type ShopMonth = {
    month: string;
    orders: number;
    sales: number;
    refunded: number;
    net: number;
    supports: number;
    support_amount: number;
};

type Month = { month: string; gross: number; fee: number; net: number };

const accountCopy: Record<
    AccountStatus,
    { message: string; button: string; action: 'onboarding' | 'dashboard' }
> = {
    none: {
        message: '支援を受け付けるには、Stripe で受取口座を登録してください。',
        button: 'Stripe で口座を登録する',
        action: 'onboarding',
    },
    incomplete: {
        message: '口座の登録が完了していません。',
        button: '登録を続ける',
        action: 'onboarding',
    },
    ready: {
        message:
            '口座は登録済みです。入金の履歴や振込先の変更は Stripe のダッシュボードで行えます。',
        button: 'Stripe ダッシュボードを開く',
        action: 'dashboard',
    },
};

export default function Earnings({
    account,
    feePercent,
    activeSupporters,
    months,
    shop_months,
    todo,
}: {
    account: { status: AccountStatus };
    feePercent: number;
    activeSupporters: number;
    months: Month[];
    shop_months: ShopMonth[];
    todo: {
        awaitingShipment: number;
        drafts: number;
        latestDraft: { id: number; title: string } | null;
    };
}) {
    const [processing, setProcessing] = useState(false);
    // Both lists start with the current month.
    const thisMonthNet = (months[0]?.net ?? 0) + (shop_months[0]?.net ?? 0);
    const copy = accountCopy[account.status];

    const open = () =>
        router.post(
            EarningsController[copy.action]().url,
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
            },
        );

    return (
        <>
            <Head title="収益" />

            <div className="space-y-6 p-4">
                <Heading
                    title="収益"
                    description={`運営手数料は売上の ${feePercent}% です。売上は返金分を除いた額です。`}
                />

                <div className="grid gap-4 sm:grid-cols-3">
                    <div className="space-y-1 rounded-2xl bg-primary p-5 text-primary-foreground">
                        <p className="text-sm opacity-85">
                            今月の受取額（目安）
                        </p>
                        <p className="text-3xl font-bold">
                            {formatYen(thisMonthNet)}
                        </p>
                        <p className="text-xs opacity-85">
                            支援とショップの合計・手数料差し引き後
                        </p>
                    </div>
                    <div className="space-y-1 rounded-2xl border p-5">
                        <p className="text-sm text-muted-foreground">
                            支援中のファン
                        </p>
                        <p className="text-3xl font-bold">
                            {activeSupporters}
                            <span className="text-sm font-normal text-muted-foreground">
                                {' '}
                                人
                            </span>
                        </p>
                    </div>
                    <div className="space-y-1 rounded-2xl border p-5">
                        <p className="text-sm text-muted-foreground">
                            今月のショップの売上
                        </p>
                        <p className="text-3xl font-bold">
                            {formatYen(shop_months[0]?.sales ?? 0)}
                        </p>
                        {!!shop_months[0]?.supports && (
                            <p className="text-xs font-bold text-support">
                                うち応援 {shop_months[0].supports} 件
                            </p>
                        )}
                    </div>
                </div>

                <section className="space-y-3">
                    <h2 className="text-xl font-bold">やること</h2>
                    <ul className="divide-y overflow-hidden rounded-2xl border">
                        {account.status !== 'ready' && (
                            <TodoRow
                                icon={<Landmark className="size-5" />}
                                tone="warn"
                                title={copy.message}
                                action={
                                    <Button
                                        size="sm"
                                        onClick={open}
                                        disabled={processing}
                                    >
                                        {copy.button}
                                    </Button>
                                }
                            />
                        )}
                        {todo.awaitingShipment > 0 && (
                            <TodoRow
                                icon={<Package className="size-5" />}
                                tone="warn"
                                title={`発送待ちの注文が ${todo.awaitingShipment} 件`}
                                description="支払い済みで、まだ発送済みにしていない注文です。"
                                action={
                                    <Button asChild size="sm" variant="outline">
                                        <Link href={orderIndex()}>
                                            注文を見る
                                        </Link>
                                    </Button>
                                }
                            />
                        )}
                        {todo.latestDraft && (
                            <TodoRow
                                icon={<PenLine className="size-5" />}
                                tone="primary"
                                title={`下書きが ${todo.drafts} 件`}
                                description={`最後に編集した下書き：${todo.latestDraft.title}`}
                                action={
                                    <Button asChild size="sm" variant="outline">
                                        <Link
                                            href={editPost(todo.latestDraft.id)}
                                        >
                                            続きを書く
                                        </Link>
                                    </Button>
                                }
                            />
                        )}
                        {account.status === 'ready' && (
                            <TodoRow
                                icon={<Check className="size-5" />}
                                tone="done"
                                title="受取口座は登録済みです"
                                description="入金の履歴や振込先の変更は Stripe のダッシュボードで行えます。"
                                action={
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={open}
                                        disabled={processing}
                                    >
                                        {copy.button}
                                    </Button>
                                }
                            />
                        )}
                    </ul>
                </section>

                <div className="overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="py-2">月</th>
                                <th className="text-right">売上（税込）</th>
                                <th className="text-right">
                                    運営手数料（目安）
                                </th>
                                <th className="text-right">受取額（目安）</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {months.map((row) => (
                                <tr key={row.month}>
                                    <td className="py-2">{row.month}</td>
                                    <td className="text-right">
                                        {formatYen(row.gross)}
                                    </td>
                                    <td className="text-right">
                                        {formatYen(row.fee)}
                                    </td>
                                    <td className="text-right">
                                        {formatYen(row.net)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                <section className="space-y-3">
                    <h2 className="text-lg font-semibold">ショップの売上</h2>
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="text-left text-muted-foreground">
                                <tr>
                                    <th className="py-2">月</th>
                                    <th className="text-right">注文数</th>
                                    <th className="text-right">売上（税込）</th>
                                    <th className="text-right">うち応援</th>
                                    <th className="text-right">返金</th>
                                    <th className="text-right">
                                        受取額（目安）
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y">
                                {shop_months.map((row) => (
                                    <tr key={row.month}>
                                        <td className="py-2">{row.month}</td>
                                        <td className="text-right">
                                            {row.orders} 件
                                        </td>
                                        <td className="text-right">
                                            {formatYen(row.sales)}
                                        </td>
                                        <td className="text-right">
                                            {row.supports} 件 /{' '}
                                            {formatYen(row.support_amount)}
                                        </td>
                                        <td className="text-right">
                                            {formatYen(row.refunded)}
                                        </td>
                                        <td className="text-right">
                                            {formatYen(row.net)}
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>

                <p className="text-xs text-muted-foreground">
                    手数料は概算です。正確な入金額は Stripe
                    ダッシュボードでご確認ください。決済手数料は運営手数料に含まれます。
                </p>
            </div>
        </>
    );
}

Earnings.layout = {
    breadcrumbs: [{ title: '収益', href: index() }],
};

const toneClass = {
    warn: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    primary: 'bg-secondary text-primary',
    done: 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
} as const;

function TodoRow({
    icon,
    tone,
    title,
    description,
    action,
}: {
    icon: React.ReactNode;
    tone: keyof typeof toneClass;
    title: string;
    description?: string;
    action?: React.ReactNode;
}) {
    return (
        <li className="flex flex-wrap items-center gap-4 px-5 py-4">
            <span
                className={cn(
                    'flex size-10 shrink-0 items-center justify-center rounded-xl',
                    toneClass[tone],
                )}
                aria-hidden
            >
                {icon}
            </span>
            <div className="min-w-0 flex-1 space-y-0.5">
                <p className="font-bold">{title}</p>
                {description && (
                    <p className="text-sm text-muted-foreground">
                        {description}
                    </p>
                )}
            </div>
            {action}
        </li>
    );
}
