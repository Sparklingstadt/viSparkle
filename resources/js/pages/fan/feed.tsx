import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { PostCard } from '@/components/post-card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { feed, home } from '@/routes';
import { show } from '@/routes/creators';
import type { CreatorSummary, Paginated, PostSummary } from '@/types';

type FeedTab = 'all' | 'supporting';

const feedTabs: { label: string; tab: FeedTab }[] = [
    { label: 'すべて', tab: 'all' },
    { label: '支援中', tab: 'supporting' },
];

export default function Feed({
    posts,
    tab,
    creators,
}: {
    posts: Paginated<PostSummary>;
    tab: FeedTab;
    creators: (CreatorSummary & { supporting: boolean; has_new: boolean })[];
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
                    <div
                        className="flex gap-1 rounded-full bg-muted p-1"
                        role="tablist"
                    >
                        {feedTabs.map((item) => (
                            <Button
                                key={item.tab}
                                size="sm"
                                variant="ghost"
                                asChild
                                className={cn(
                                    'text-muted-foreground',
                                    item.tab === tab &&
                                        'bg-background text-foreground shadow-xs hover:bg-background',
                                )}
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

                {creators.length > 0 && (
                    <ul
                        aria-label="支援中・フォロー中のクリエイター"
                        className="flex gap-4 overflow-x-auto pb-1"
                    >
                        {creators.map((creator) => (
                            <li key={creator.slug} className="shrink-0">
                                <Link
                                    href={show(creator.slug)}
                                    className="flex w-20 flex-col items-center gap-1.5 text-center"
                                >
                                    <span
                                        className={cn(
                                            'relative rounded-full p-0.5',
                                            creator.supporting
                                                ? 'ring-2 ring-support'
                                                : 'ring-1 ring-border',
                                        )}
                                    >
                                        <Avatar className="size-14">
                                            {creator.avatar_url && (
                                                <AvatarImage
                                                    src={creator.avatar_url}
                                                    alt=""
                                                />
                                            )}
                                            <AvatarFallback>
                                                {creator.display_name.slice(
                                                    0,
                                                    1,
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        {creator.has_new && (
                                            <span className="absolute top-0 right-0 size-3.5 rounded-full border-2 border-background bg-primary">
                                                <span className="sr-only">
                                                    新着あり
                                                </span>
                                            </span>
                                        )}
                                    </span>
                                    <span className="line-clamp-2 text-xs">
                                        {creator.display_name}
                                    </span>
                                    <span
                                        className={cn(
                                            'text-[10px] font-bold',
                                            creator.supporting
                                                ? 'text-support'
                                                : 'text-muted-foreground',
                                        )}
                                    >
                                        {creator.supporting
                                            ? '支援中'
                                            : 'フォロー中'}
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
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
