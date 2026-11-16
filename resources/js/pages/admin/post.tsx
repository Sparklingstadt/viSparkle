import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { formatDate, formatYen } from '@/lib/format';
import { index as reportsIndex } from '@/routes/admin/reports';
import { update } from '@/routes/admin/posts';
import type { PostStatus } from '@/types';
import { PostStatusBadge } from '@/components/post-status-badge';
import { ModerationPanel } from '@/components/admin/moderation-panel';
import { ReportList, type ItemReport } from '@/components/admin/report-list';

type ModeratedPost = {
    id: number;
    title: string;
    excerpt: string | null;
    status: PostStatus;
    required_price: number;
    published_at: string | null;
    hidden: boolean;
    hidden_at: string | null;
    hidden_reason: string | null;
    creator: { name: string; slug: string };
    url: string;
};

export default function AdminPost({
    post,
    reports,
}: {
    post: ModeratedPost;
    reports: ItemReport[];
}) {
    return (
        <>
            <Head title="投稿の管理" />

            <div className="max-w-3xl space-y-6">
                <Heading title="投稿の管理" />

                <section className="space-y-2 rounded-xl border p-4 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-base font-medium">
                            {post.title}
                        </span>
                        <PostStatusBadge status={post.status} />
                        {post.hidden && (
                            <Badge variant="destructive">非公開（運営）</Badge>
                        )}
                    </div>
                    <p className="text-muted-foreground">
                        {post.creator.name}（@{post.creator.slug}） /{' '}
                        {post.required_price === 0
                            ? '全体公開'
                            : `${formatYen(post.required_price)}以上`}{' '}
                        / 公開日 {formatDate(post.published_at)}
                    </p>
                    {post.excerpt && (
                        <p className="whitespace-pre-wrap">{post.excerpt}</p>
                    )}
                    <a
                        href={post.url}
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-4"
                    >
                        公開ページを開く
                    </a>
                </section>

                <ModerationPanel
                    hidden={post.hidden}
                    hiddenAt={post.hidden_at}
                    hiddenReason={post.hidden_reason}
                    form={update.form(post.id)}
                />

                <ReportList title="この投稿への通報" reports={reports} />
            </div>
        </>
    );
}

AdminPost.layout = {
    breadcrumbs: [{ title: '通報', href: reportsIndex() }],
};
