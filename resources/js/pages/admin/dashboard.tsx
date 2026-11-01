import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { formatYen } from '@/lib/format';
import { dashboard } from '@/routes/admin';

export default function Dashboard({
    openReports,
    sales30d,
    refunds30d,
    activeSupports,
}: {
    openReports: number;
    sales30d: number;
    refunds30d: number;
    activeSupports: number;
}) {
    const cards = [
        { label: '未処理の通報', value: `${openReports} 件` },
        { label: '直近30日の売上（返金前）', value: formatYen(sales30d) },
        { label: '直近30日の返金', value: formatYen(refunds30d) },
        { label: '有効な支援', value: `${activeSupports} 件` },
    ];

    return (
        <>
            <Head title="管理ダッシュボード" />

            <div className="space-y-6">
                <Heading title="管理ダッシュボード" />

                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    {cards.map((card) => (
                        <div key={card.label} className="rounded-xl border p-4">
                            <p className="text-sm text-muted-foreground">
                                {card.label}
                            </p>
                            <p className="text-2xl font-bold">{card.value}</p>
                        </div>
                    ))}
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [{ title: '管理ダッシュボード', href: dashboard() }],
};
