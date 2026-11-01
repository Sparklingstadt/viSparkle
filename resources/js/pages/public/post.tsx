import { Head, Link, router } from '@inertiajs/react';
import { Download, Paperclip } from 'lucide-react';
import { useRef } from 'react';
import { CreatorHeader } from '@/components/creator-header';
import { LockedPost } from '@/components/locked-post';
import { PlanCard } from '@/components/plan-card';
import {
    ReportDialog,
    type ReportReasonOption,
} from '@/components/report-dialog';
import { SupportButton } from '@/components/support-button';
import { PostRenderer } from '@/components/tiptap/post-renderer';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { formatBytes, formatDate, formatYen } from '@/lib/format';
import { placeholderCover } from '@/lib/placeholder';
import { edit } from '@/routes/creator/posts';
import type { CreatorProfile, PlanData, PostDetail } from '@/types';

export default function PostPage({
    post,
    creator,
    plans,
    isOwner,
    viewerSupportPrice,
    viewerPlanId,
    viewerSupportId,
    viewerSupportCancelling,
    acceptsSupports,
    closed,
    reportReasons,
    hiddenReason,
}: {
    post: PostDetail;
    creator: CreatorProfile & { id: number };
    plans: PlanData[];
    isOwner: boolean;
    viewerSupportPrice: number | null;
    viewerPlanId: number | null;
    viewerSupportId: number | null;
    viewerSupportCancelling: boolean;
    acceptsSupports: boolean;
    closed: boolean;
    reportReasons: ReportReasonOption[];
    hiddenReason: string | null;
}) {
    const lastRefresh = useRef(0);
    // Signed image URLs expire after 5 minutes; when one fails, fetch fresh ones (at most once a minute).
    const refreshUrls = () => {
        if (Date.now() - lastRefresh.current < 60_000) {
            return;
        }

        lastRefresh.current = Date.now();
        router.reload({ only: ['post'] });
    };

    const unlocking = plans.filter((plan) => plan.price >= post.required_price);

    return (
        <>
            <Head title={post.title} />

            <article className="mx-auto max-w-3xl space-y-6">
                <CreatorHeader creator={creator} compact />

                {hiddenReason !== null && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            運営により非公開になっています（理由: {hiddenReason}
                            ）
                        </AlertDescription>
                    </Alert>
                )}

                {post.cover_thumb_url ? (
                    <img
                        src={post.cover_thumb_url}
                        alt=""
                        onError={refreshUrls}
                        className={`aspect-video w-full rounded-xl object-cover ${post.locked ? 'blur-md' : ''}`}
                    />
                ) : (
                    <img
                        src={placeholderCover(post.id)}
                        alt=""
                        className="aspect-video w-full rounded-xl object-cover"
                    />
                )}

                <header className="space-y-2">
                    <h1 className="text-2xl font-bold">{post.title}</h1>
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <span>{formatDate(post.published_at)}</span>
                        <Badge variant="outline">
                            {post.required_price === 0
                                ? '全体公開'
                                : `${formatYen(post.required_price)}以上の支援者向け`}
                        </Badge>
                        {isOwner && (
                            <Link
                                href={edit(post.id)}
                                className="underline underline-offset-4"
                            >
                                編集する
                            </Link>
                        )}
                    </div>
                </header>

                {post.excerpt && (
                    <p className="whitespace-pre-wrap">{post.excerpt}</p>
                )}

                {post.locked ? (
                    <>
                        <LockedPost
                            requiredPrice={post.required_price}
                            supportPrice={viewerSupportPrice}
                        />
                        {unlocking.length > 0 && (
                            <section id="plans" className="space-y-4">
                                <h2 className="text-lg font-semibold">
                                    この投稿を閲覧できるプラン
                                </h2>
                                <div className="grid gap-4 sm:grid-cols-2">
                                    {unlocking.map((plan) => (
                                        <PlanCard
                                            key={plan.id}
                                            plan={plan}
                                            action={
                                                <SupportButton
                                                    creatorSlug={creator.slug}
                                                    plan={plan}
                                                    viewerPlanId={viewerPlanId}
                                                    viewerSupportId={
                                                        viewerSupportId
                                                    }
                                                    viewerSupportPrice={
                                                        viewerSupportPrice
                                                    }
                                                    viewerSupportCancelling={
                                                        viewerSupportCancelling
                                                    }
                                                    acceptsSupports={
                                                        acceptsSupports
                                                    }
                                                    closed={closed}
                                                />
                                            }
                                        />
                                    ))}
                                </div>
                            </section>
                        )}
                    </>
                ) : (
                    <>
                        <PostRenderer
                            doc={post.body}
                            imageUrls={post.image_urls ?? {}}
                            onImageError={refreshUrls}
                        />
                        {(post.attachments ?? []).length > 0 && (
                            <section className="space-y-2">
                                <h2 className="font-semibold">添付ファイル</h2>
                                <ul className="space-y-1">
                                    {post.attachments!.map((file) => (
                                        <li key={file.id}>
                                            <a
                                                href={file.url}
                                                className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted/50"
                                            >
                                                <span className="flex min-w-0 items-center gap-2">
                                                    <Paperclip className="size-4 shrink-0" />
                                                    <span className="truncate">
                                                        {file.original_name}
                                                    </span>
                                                    <span className="shrink-0 text-muted-foreground">
                                                        {formatBytes(file.size)}
                                                    </span>
                                                </span>
                                                <Download className="size-4 shrink-0" />
                                            </a>
                                        </li>
                                    ))}
                                </ul>
                            </section>
                        )}
                    </>
                )}

                <footer className="flex justify-end">
                    <ReportDialog
                        targetType="post"
                        targetId={post.id}
                        reasons={reportReasons}
                        isOwner={isOwner}
                    />
                </footer>
            </article>
        </>
    );
}
