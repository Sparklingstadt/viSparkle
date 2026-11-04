import { Form, Head, Link } from '@inertiajs/react';
import { FilePlus2, Heart } from 'lucide-react';
import PostController from '@/actions/App/Http/Controllers/Creator/PostController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatDate, formatYen } from '@/lib/format';
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

export default function PostsIndex({ posts }: { posts: Paginated<Row> }) {
    return (
        <>
            <Head title="投稿管理" />

            <div className="space-y-6 p-4">
                <div className="flex items-center justify-between gap-4">
                    <Heading title="投稿管理" />
                    <Form {...PostController.store.form()}>
                        {({ processing }) => (
                            <Button disabled={processing}>
                                <FilePlus2 />
                                新規作成
                            </Button>
                        )}
                    </Form>
                </div>

                {posts.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        まだ投稿がありません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {posts.data.map((post) => (
                            <li key={post.id}>
                                <Link
                                    href={PostController.edit(post.id)}
                                    className="flex items-center justify-between gap-4 p-4 hover:bg-muted"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {post.title}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {post.status === 'published'
                                                ? `公開日 ${formatDate(post.published_at)}`
                                                : `更新日 ${formatDate(post.updated_at)}`}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 items-center gap-2">
                                        <span
                                            className="flex items-center gap-1 text-xs text-muted-foreground"
                                            aria-label={`いいね ${post.like_count}件`}
                                        >
                                            <Heart className="size-3" />
                                            {post.like_count}
                                        </span>
                                        {post.hidden && (
                                            <Badge variant="destructive">
                                                非公開（運営）
                                            </Badge>
                                        )}
                                        <Badge variant="outline">
                                            {post.required_price === 0
                                                ? '全体公開'
                                                : `${formatYen(post.required_price)}〜`}
                                        </Badge>
                                        <Badge
                                            variant={
                                                post.status === 'published'
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {post.status === 'published'
                                                ? '公開中'
                                                : '下書き'}
                                        </Badge>
                                    </div>
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
