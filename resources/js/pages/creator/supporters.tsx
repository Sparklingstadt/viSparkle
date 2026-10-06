import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { formatDate, formatYen } from '@/lib/format';
import { index } from '@/routes/creator/supporters';
import type { Paginated, PlanData } from '@/types';

type Row = {
    id: number;
    name: string;
    plan_name: string | null;
    price: number;
    started_at: string;
};

export default function Supporters({
    plans,
    supporters,
}: {
    plans: PlanData[];
    supporters: Paginated<Row>;
}) {
    return (
        <>
            <Head title="支援者" />

            <div className="space-y-6 p-4">
                <Heading title="支援者" />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {plans.map((plan) => (
                        <div key={plan.id} className="rounded-xl border p-4">
                            <p className="text-sm text-muted-foreground">
                                {plan.name}（{formatYen(plan.price)}）
                            </p>
                            <p className="text-2xl font-bold">
                                {plan.active_supporters_count} 人
                            </p>
                        </div>
                    ))}
                </div>

                {supporters.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        まだ支援者はいません。
                    </p>
                ) : (
                    <table className="w-full text-sm">
                        <thead className="text-left text-muted-foreground">
                            <tr>
                                <th className="py-2">名前</th>
                                <th>プラン</th>
                                <th>開始日</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {supporters.data.map((row) => (
                                <tr key={row.id}>
                                    <td className="py-2">{row.name}</td>
                                    <td>
                                        {row.plan_name ??
                                            '（削除されたプラン）'}{' '}
                                        {formatYen(row.price)}
                                    </td>
                                    <td>{formatDate(row.started_at)}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}

                <div className="flex justify-between">
                    {supporters.prev_page_url ? (
                        <Link href={supporters.prev_page_url}>← 前へ</Link>
                    ) : (
                        <span />
                    )}
                    {supporters.next_page_url && (
                        <Link href={supporters.next_page_url}>次へ →</Link>
                    )}
                </div>
            </div>
        </>
    );
}

Supporters.layout = {
    breadcrumbs: [{ title: '支援者', href: index() }],
};
