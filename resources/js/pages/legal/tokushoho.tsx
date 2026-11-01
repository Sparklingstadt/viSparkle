import { Head, Link } from '@inertiajs/react';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { terms } from '@/routes/legal';

type Seller = {
    seller_name: string | null;
    responsible_person: string | null;
    address: string | null;
    phone: string | null;
    email: string | null;
};

export default function Tokushoho({
    ready,
    seller,
}: {
    ready: boolean;
    seller: Seller;
}) {
    const rows: [string, React.ReactNode][] = [
        ['販売事業者', seller.seller_name],
        ['運営統括責任者', seller.responsible_person],
        ['所在地', seller.address],
        ['電話番号', seller.phone],
        ['メールアドレス', seller.email],
        ['販売価格', '各プランのページに表示しています（税込）。'],
        [
            '商品代金以外の必要料金',
            'ありません。なお、本サービスの利用に必要なインターネット接続の通信料はお客様のご負担となります。',
        ],
        [
            '支払方法',
            'クレジットカード等、Stripe による決済でお支払いいただけます。',
        ],
        [
            '支払時期',
            'お申し込み時に初回分をお支払いいただき、以後は毎月お申し込み日と同じ日に自動で更新され、お支払いいただきます。',
        ],
        [
            '提供時期',
            '決済の完了後、すぐにプランの限定投稿をご覧いただけます。',
        ],
        [
            '解約',
            '「支援中」の画面からいつでも支援を停止できます。停止後も、次回のお支払い日までは引き続きご覧いただけます。',
        ],
        [
            '返品・返金',
            'デジタルコンテンツの性質上、お支払い後の返金は原則としてお受けしていません。ただし、運営者が必要と認めた場合はこの限りではありません。',
        ],
        [
            '動作環境',
            'Google Chrome、Safari、Microsoft Edge、Firefox の最新版でのご利用を推奨します。JavaScript と Cookie を有効にしてください。',
        ],
    ];

    return (
        <>
            <Head title="特定商取引法に基づく表記" />

            <article className="mx-auto max-w-3xl space-y-6">
                <h1 className="text-2xl font-bold">特定商取引法に基づく表記</h1>

                {!ready && (
                    <Alert>
                        <AlertDescription>
                            事業者情報は準備中です。
                        </AlertDescription>
                    </Alert>
                )}

                <dl className="divide-y rounded-lg border text-sm">
                    {rows.map(([label, value]) => (
                        <div
                            key={label}
                            className="grid gap-1 p-4 sm:grid-cols-[12rem_1fr] sm:gap-4"
                        >
                            <dt className="font-medium">{label}</dt>
                            <dd className="whitespace-pre-line text-muted-foreground">
                                {value ?? '—'}
                            </dd>
                        </div>
                    ))}
                </dl>

                <p className="text-sm text-muted-foreground">
                    支援プランの契約条件の詳細は
                    <Link href={terms()} className="underline">
                        利用規約
                    </Link>
                    をご確認ください。
                </p>
            </article>
        </>
    );
}
