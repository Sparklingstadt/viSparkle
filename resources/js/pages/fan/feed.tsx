import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { PostCard } from '@/components/post-card';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { feed, home } from '@/routes';
import type { Paginated, PostSummary } from '@/types';

type FeedTab = 'all' | 'supporting';

const feedTabs: { label: string; tab: FeedTab }[] = [
    { label: 'すべて', tab: 'all' },
    { label: '支援中', tab: 'supporting' },
];

export default function Feed({
    posts,
    tab,
}: {
    posts: Paginated<PostSummary>;
    tab: FeedTab;
}) {
    return (
        <>
            <Head title="フィード" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-end justify-between gap-2">
                    <Heading
                        title="フィード"
                        description="支援中・フォロー中のクリエイターの新着投稿"
                    />
                    <div className="flex gap-1" role="tablist">
                        {feedTabs.map((item) => (
                            <Button
                                key={item.tab}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn({
                                    'bg-muted': item.tab === tab,
                                })}
                            >
                                <Link
                                    href={feed({
                                        query:
                                            item.tab === 'supporting'
                                                ? { tab: 'supporting' }
                                                : {},
                                    })}
                                    role="tab"
                                    aria-selected={item.tab === tab}
                                    preserveScroll
                                    only={['posts', 'tab']}
                                >
                                    {item.label}
                                </Link>
                            </Button>
                        ))}
                    </div>
                </div>

                {posts.data.length === 0 ? (
                    tab === 'supporting' ? (
                        <p className="text-sm text-muted-foreground">
                            支援中のクリエイターの投稿はまだありません。
                        </p>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            まだ投稿がありません。
                            <Link
                                href={home()}
                                className="underline underline-offset-4"
                            >
                                クリエイターを探す
                            </Link>
                        </p>
                    )
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                        {posts.data.map((post) => (
                            <PostCard key={post.id} post={post} showCreator />
                        ))}
                    </div>
                )}

                <div className="flex justify-between">
                    {posts.prev_page_url ? (
                        <Link href={posts.prev_page_url}>← 前へ</Link>
                    ) : (
                        <span />
                    )}
                    {posts.next_page_url && (
                        <Link href={posts.next_page_url}>次へ →</Link>
                    )}
                </div>
            </div>
        </>
    );
}

Feed.layout = {
    breadcrumbs: [{ title: 'フィード', href: feed() }],
};
