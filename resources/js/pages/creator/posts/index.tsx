import { Form, Head, Link } from '@inertiajs/react';
import { FilePlus2, Heart } from 'lucide-react';
import PostController from '@/actions/App/Http/Controllers/Creator/PostController';
import { AccessBadge } from '@/components/access-badge';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index } from '@/routes/creator/posts';
import type { Paginated, PostStatus } from '@/types';

type Row = {
    id: number;
    title: string;
    status: PostStatus;
    required_price: number;
    published_at: string | null;
    updated_at: string;
    hidden: boolean;
    like_count: number;
};

type StatusFilter = 'published' | 'draft' | null;

const statusTabs: { label: string; status: StatusFilter }[] = [
    { label: 'すべて', status: null },
    { label: '公開中', status: 'published' },
    { label: '下書き', status: 'draft' },
];

export default function PostsIndex({
    posts,
    status,
    counts,
}: {
    posts: Paginated<Row>;
    status: StatusFilter;
    counts: { all: number; published: number; draft: number };
}) {
    return (
        <>
            <Head title="投稿管理" />

            <div className="flex flex-col gap-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading title="投稿管理" />
                    <Form {...PostController.store.form()}>
                        {({ processing }) => (
                            <Button disabled={processing}>
                                <FilePlus2 />
                                投稿を書く
                            </Button>
                        )}
                    </Form>
                </div>

                <nav
                    aria-label="状態で絞り込む"
                    className="flex gap-1 self-start rounded-full bg-muted p-1"
                >
                    {statusTabs.map((tab) => (
                        <Link
                            key={tab.label}
                            href={index({
                                query: tab.status ? { status: tab.status } : {},
                            })}
                            aria-current={
                                tab.status === status ? 'page' : undefined
                            }
                            className={cn(
                                'rounded-full px-4 py-1.5 text-sm font-bold transition-colors',
                                tab.status === status
                                    ? 'bg-background shadow-xs'
                                    : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {tab.label}{' '}
                            <span className="font-normal">
                                {counts[tab.status ?? 'all']}
                            </span>
                        </Link>
                    ))}
                </nav>

                {posts.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        {status === null
                            ? 'まだ投稿がありません。'
                            : 'この状態の投稿はありません。'}
                    </p>
                ) : (
                    <ul className="divide-y overflow-hidden rounded-2xl border">
                        {posts.data.map((post) => (
                            <li key={post.id}>
                                <Link
                                    href={PostController.edit(post.id)}
                                    className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-4 hover:bg-muted"
                                >
                                    <div className="min-w-0 flex-1 basis-60 space-y-1.5">
                                        <p className="truncate font-bold">
                                            {post.title}
                                        </p>
                                        <div className="flex flex-wrap items-center gap-2 text-xs text-muted-foreground">
                                            <span
                                                className={cn(
                                                    'inline-flex h-6 items-center rounded-full px-2.5 font-bold',
                                                    post.status === 'published'
                                                        ? 'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300'
                                                        : 'bg-muted text-muted-foreground',
                                                )}
                                            >
                                                {post.status === 'published'
                                                    ? '公開中'
                                                    : '下書き'}
                                            </span>
                                            <AccessBadge
                                                price={post.required_price}
                                            />
                                            {post.hidden && (
                                                <Badge variant="destructive">
                                                    非公開（運営）
                                                </Badge>
                                            )}
                                            <span>
                                                {post.status === 'published'
                                                    ? `公開日 ${formatDate(post.published_at)}`
                                                    : `${formatDate(post.updated_at)} に編集`}
                                            </span>
                                        </div>
                                    </div>
                                    <span
                                        className="flex w-14 items-center gap-1 text-sm font-bold text-support"
                                        aria-label={`いいね ${post.like_count}件`}
                                    >
                                        <Heart className="size-3.5 fill-current" />
                                        {post.like_count}
                                    </span>
                                    <span className="inline-flex h-9 items-center rounded-full border px-4 text-sm font-bold">
                                        編集
                                    </span>
                                </Link>
                            </li>
                        ))}
                    </ul>
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

PostsIndex.layout = {
    breadcrumbs: [{ title: '投稿管理', href: index() }],
};
