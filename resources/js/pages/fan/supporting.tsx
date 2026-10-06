import { Head, Link, router } from '@inertiajs/react';
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
};

export default function Supporting({ supports }: { supports: Row[] }) {
    return (
        <>
            <Head title="支援中のクリエイター" />

            <div className="space-y-6 p-4">
                <Heading title="支援中のクリエイター" />

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
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        if (confirm('支援を停止しますか？')) {
                                            router.delete(
                                                SupportController.destroy.url(
                                                    support.id,
                                                ),
                                                { preserveScroll: true },
                                            );
                                        }
                                    }}
                                >
                                    支援を停止
                                </Button>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Supporting.layout = {
    breadcrumbs: [{ title: '支援中', href: index() }],
};
