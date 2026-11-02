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
        [
            '販売価格',
            '各プランのページ、およびグッズ・デジタル作品の各商品ページに表示しています（税込）。',
        ],
        [
            '商品代金以外の必要料金',
            '支援プランに追加の料金はありません。グッズの購入には送料がかかります（各クリエイターが定める一律の金額で、商品ページと購入前の確認画面に表示します）。デジタル作品に送料はかかりません。ダウンロードや本サービスの利用に必要なインターネット接続の通信料は、お客様のご負担となります。',
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
            '引き渡し時期（グッズ）',
            '支払いの確認後、各クリエイターが発送します（目安は商品ページやストアからのお知らせに記載します）。',
        ],
        [
            '返品・交換（グッズ）',
            'お客様都合による返品・交換は受け付けません。不良品・誤配送の場合は、到着後 7 日以内にクリエイターの連絡先または運営にご連絡ください。',
        ],
        [
            '引き渡し時期（デジタル作品）',
            '支払いの確認後、すぐに注文の画面からダウンロードできます。',
        ],
        [
            '返品・キャンセル（デジタル作品）',
            '商品の性質上、購入後のキャンセル・返金は受け付けません。ファイルが壊れている、または開けない場合は運営にご連絡ください。',
        ],
        [
            '動作環境（デジタル作品）',
            '各作品のページにファイルの種類と大きさを表示しています。',
        ],
        [
            'ダウンロードできる期間（デジタル作品）',
            '作品が公開されているあいだダウンロードできます。作品が非公開になるとダウンロードできなくなるため、購入後は早めに保存してください。',
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
