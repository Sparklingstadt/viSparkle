import { Form, Head, Link } from '@inertiajs/react';
import InputError from '@/components/input-error';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { formatDate, formatYen } from '@/lib/format';
import { index as reportsIndex } from '@/routes/admin/reports';
import { update } from '@/routes/admin/posts';
import type { PostStatus } from '@/types';

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

type ReportRow = {
    id: number;
    reporter_name: string | null;
    reason_label: string;
    body: string | null;
    status: 'open' | 'resolved' | 'dismissed';
    created_at: string;
};

const statusLabels: Record<ReportRow['status'], string> = {
    open: '未処理',
    resolved: '対応済み',
    dismissed: '却下',
};

export default function AdminPost({
    post,
    reports,
}: {
    post: ModeratedPost;
    reports: ReportRow[];
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
                        <Badge variant="outline">
                            {post.status === 'published' ? '公開中' : '下書き'}
                        </Badge>
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

                {post.hidden ? (
                    <section className="space-y-3">
                        <Alert>
                            <AlertDescription>
                                {formatDate(post.hidden_at)}{' '}
                                に非公開にしました（理由: {post.hidden_reason}）
                            </AlertDescription>
                        </Alert>
                        <Form
                            {...update.form(post.id)}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <>
                                    <input
                                        type="hidden"
                                        name="hidden"
                                        value="0"
                                    />
                                    <Button disabled={processing}>
                                        公開に戻す
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                ) : (
                    <Form
                        {...update.form(post.id)}
                        options={{ preserveScroll: true }}
                        resetOnSuccess
                        className="space-y-3"
                    >
                        {({ processing, errors }) => (
                            <>
                                <input type="hidden" name="hidden" value="1" />
                                <div className="grid gap-2">
                                    <Label htmlFor="hide-reason">
                                        非公開にする理由（作者に表示されます）
                                    </Label>
                                    <textarea
                                        id="hide-reason"
                                        name="reason"
                                        rows={3}
                                        required
                                        maxLength={500}
                                        className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                    />
                                    <InputError message={errors.reason} />
                                </div>
                                <Button
                                    type="submit"
                                    variant="destructive"
                                    disabled={processing}
                                >
                                    非公開にする
                                </Button>
                            </>
                        )}
                    </Form>
                )}

                <section className="space-y-2">
                    <h2 className="font-medium">この投稿への通報</h2>
                    {reports.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            通報はありません。
                        </p>
                    ) : (
                        <ul className="divide-y rounded-xl border">
                            {reports.map((report) => (
                                <li
                                    key={report.id}
                                    className="space-y-1 p-4 text-sm"
                                >
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="rounded-full border px-2 py-0.5 text-xs">
                                            {statusLabels[report.status]}
                                        </span>
                                        <span className="font-medium">
                                            {report.reason_label}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {formatDate(report.created_at)} /{' '}
                                            {report.reporter_name ??
                                                '（退会済み）'}
                                        </span>
                                    </div>
                                    {report.body && (
                                        <p className="whitespace-pre-wrap">
                                            {report.body}
                                        </p>
                                    )}
                                </li>
                            ))}
                        </ul>
                    )}
                    <Link
                        href={reportsIndex()}
                        className="text-sm underline underline-offset-4"
                    >
                        通報一覧へ
                    </Link>
                </section>
            </div>
        </>
    );
}

AdminPost.layout = {
    breadcrumbs: [{ title: '通報', href: reportsIndex() }],
};
