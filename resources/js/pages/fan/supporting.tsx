import { Head, Link, router } from '@inertiajs/react';
import BillingPortalController from '@/actions/App/Http/Controllers/Fan/BillingPortalController';
import SupportController from '@/actions/App/Http/Controllers/Fan/SupportController';
import Heading from '@/components/heading';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { formatDate, formatYen } from '@/lib/format';
import { show } from '@/routes/creators';
import { index } from '@/routes/supporting';
import type { CreatorSummary } from '@/types';

type Row = {
    id: number;
    creator: CreatorSummary;
    plan_name: string | null;
    price: number;
    started_at: string;
    is_billing: boolean;
    stripe_status: string | null;
    current_period_end: string | null;
    cancel_at_period_end: boolean;
};

const openPortal = () => router.post(BillingPortalController.url());

export default function Supporting({
    supports,
    hasBillingAccount,
}: {
    supports: Row[];
    hasBillingAccount: boolean;
}) {
    return (
        <>
            <Head title="支援中のクリエイター" />

            <div className="space-y-6 p-4">
                <div className="flex items-start justify-between gap-4">
                    <Heading title="支援中のクリエイター" />
                    {hasBillingAccount && (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={openPortal}
                        >
                            お支払い方法・請求履歴
                        </Button>
                    )}
                </div>

                {supports.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        支援中のクリエイターはいません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {supports.map((support) => (
                            <li
                                key={support.id}
                                className="flex items-center justify-between gap-4 p-4"
                            >
                                <Link
                                    href={show(support.creator.slug)}
                                    className="flex min-w-0 items-center gap-3"
                                >
                                    <Avatar>
                                        {support.creator.avatar_url && (
                                            <AvatarImage
                                                src={support.creator.avatar_url}
                                                alt=""
                                            />
                                        )}
                                        <AvatarFallback>
                                            {support.creator.display_name.slice(
                                                0,
                                                1,
                                            )}
                                        </AvatarFallback>
                                    </Avatar>
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {support.creator.display_name}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {support.plan_name ??
                                                '（削除されたプラン）'}{' '}
                                            · {formatYen(support.price)}/月 ·{' '}
                                            {formatDate(support.started_at)}〜
                                        </p>
                                    </div>
                                </Link>
                                <div className="flex shrink-0 flex-col items-end gap-2">
                                    <SupportStatusText support={support} />
                                    {support.stripe_status === 'past_due' && (
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={openPortal}
                                        >
                                            お支払い方法を更新
                                        </Button>
                                    )}
                                    {support.cancel_at_period_end ? (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() =>
                                                router.post(
                                                    SupportController.resume.url(
                                                        support.id,
                                                    ),
                                                    {},
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            再開する
                                        </Button>
                                    ) : (
                                        <Button
                                            variant="outline"
                                            size="sm"
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        support.is_billing
                                                            ? '支援を停止しますか？次回のお支払い日の前日まで閲覧できます。'
                                                            : '支援を停止しますか？',
                                                    )
                                                ) {
                                                    router.delete(
                                                        SupportController.destroy.url(
                                                            support.id,
                                                        ),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
                                        >
                                            支援を停止
                                        </Button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

function SupportStatusText({ support }: { support: Row }) {
    if (support.stripe_status === 'past_due') {
        return (
            <p className="text-xs text-destructive">お支払いに失敗しています</p>
        );
    }

    if (!support.current_period_end) {
        return null;
    }

    return (
        <p className="text-xs text-muted-foreground">
            {support.cancel_at_period_end
                ? `${formatDate(support.current_period_end)}に終了`
                : `次回のお支払い: ${formatDate(support.current_period_end)}`}
        </p>
    );
}

Supporting.layout = {
    breadcrumbs: [{ title: '支援中', href: index() }],
};
