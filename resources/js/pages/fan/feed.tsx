import { Head, Link } from '@inertiajs/react';
import Heading from '@/components/heading';
import { PostCard } from '@/components/post-card';
import { feed, home } from '@/routes';
import type { Paginated, PostSummary } from '@/types';

export default function Feed({ posts }: { posts: Paginated<PostSummary> }) {
    return (
        <>
            <Head title="フィード" />

            <div className="space-y-6 p-4">
                <Heading
                    title="フィード"
                    description="支援中のクリエイターの新着投稿"
                />

                {posts.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        まだ投稿がありません。
                        <Link
                            href={home()}
                            className="underline underline-offset-4"
                        >
                            クリエイターを探す
                        </Link>
                    </p>
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
