import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import SupportController from '@/actions/App/Http/Controllers/Fan/SupportController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import { show } from '@/routes/creators';
import { terms, tokushoho } from '@/routes/legal';
import type { CreatorSummary } from '@/types';

export default function CheckoutConfirm({
    creator,
    plan,
    renewalDay,
}: {
    creator: CreatorSummary;
    plan: {
        id: number;
        name: string;
        price: number;
        description: string | null;
    };
    renewalDay: string;
}) {
    const [processing, setProcessing] = useState(false);

    const proceed = () =>
        router.post(
            SupportController.store.url({
                creator: creator.slug,
                plan: plan.id,
            }),
            {},
            {
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onError: (errors) =>
                    toast.error(errors.plan ?? '処理に失敗しました。'),
            },
        );

    const rows: [string, string][] = [
        ['クリエイター', creator.display_name],
        ['プラン', plan.name],
        ['金額（税込）', `${formatYen(plan.price)} / 月`],
        [
            'お支払い時期',
            `本日お支払い。以後、毎月${renewalDay}に自動で更新されます。`,
        ],
        ['契約期間', '解約するまで毎月自動更新されます。'],
        [
            '解約方法',
            '「支援中」ページからいつでも停止できます。停止後も次回のお支払い日まで閲覧できます。',
        ],
        [
            '返金',
            'デジタルコンテンツのため、お支払い後の返金は原則としてお受けしていません。',
        ],
    ];

    return (
        <>
            <Head title="お申し込み内容の確認" />

            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <Heading
                    title="お申し込み内容の確認"
                    description="内容をご確認のうえ、お支払いへお進みください。"
                />

                <dl className="divide-y rounded-xl border text-sm">
                    {rows.map(([label, value]) => (
                        <div
                            key={label}
                            className="grid gap-1 p-4 sm:grid-cols-[10rem_1fr]"
                        >
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                </dl>

                <p className="text-sm text-muted-foreground">
                    お申し込みの前に、
                    <Link href={tokushoho()} className="underline">
                        特定商取引法に基づく表記
                    </Link>
                    と
                    <Link href={terms()} className="underline">
                        利用規約
                    </Link>
                    をご確認ください。
                </p>

                <div className="space-y-2">
                    <Button
                        className="w-full"
                        disabled={processing}
                        onClick={proceed}
                    >
                        お支払いへ進む（Stripe）
                    </Button>
                    <Button
                        asChild
                        className="w-full"
                        variant="ghost"
                        size="sm"
                    >
                        <Link href={show(creator.slug)}>戻る</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
