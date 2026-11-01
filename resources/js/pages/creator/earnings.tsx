import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import EarningsController from '@/actions/App/Http/Controllers/Creator/EarningsController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import { index } from '@/routes/creator/earnings';

type AccountStatus = 'none' | 'incomplete' | 'ready';

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
}: {
    account: { status: AccountStatus };
    feePercent: number;
    activeSupporters: number;
    months: Month[];
}) {
    const [processing, setProcessing] = useState(false);
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

                <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border p-4">
                    <p className="text-sm">{copy.message}</p>
                    <Button onClick={open} disabled={processing}>
                        {copy.button}
                    </Button>
                </div>

                <div className="rounded-xl border p-4 sm:w-60">
                    <p className="text-sm text-muted-foreground">
                        有効な支援者
                    </p>
                    <p className="text-2xl font-bold">{activeSupporters} 人</p>
                </div>

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
