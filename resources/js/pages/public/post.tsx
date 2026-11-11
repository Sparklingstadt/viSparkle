import { Head, Link, router } from '@inertiajs/react';
import { Download, Paperclip } from 'lucide-react';
import { useRef } from 'react';
import { CreatorHeader } from '@/components/creator-header';
import { LikeButton } from '@/components/like-button';
import { AccessBadge } from '@/components/access-badge';
import { LockedPost } from '@/components/locked-post';
import { PlanCard } from '@/components/plan-card';
import { PostCard } from '@/components/post-card';
import {
    ReportDialog,
    type ReportReasonOption,
} from '@/components/report-dialog';
import { SupportButton } from '@/components/support-button';
import { PostRenderer } from '@/components/tiptap/post-renderer';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { formatBytes, formatDate } from '@/lib/format';
import { placeholderCover } from '@/lib/placeholder';
import { show as showPostCategory } from '@/routes/post-categories';
import { edit } from '@/routes/creator/posts';
import type {
    CreatorProfile,
    PlanData,
    PostDetail,
    PostSummary,
} from '@/types';

export default function PostPage({
    post,
    creator,
    morePosts,
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
    morePosts: PostSummary[];
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

    // Cheapest first: the first one is the plan that unlocks this post for the least.
    const unlocking = plans
        .filter((plan) => plan.price >= post.required_price)
        .sort((a, b) => a.price - b.price);

    return (
        <>
            <Head title={post.title} />

            <article className="mx-auto max-w-3xl space-y-8">
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
                        className={`aspect-video w-full rounded-2xl object-cover ${post.locked ? 'blur-md' : ''}`}
                    />
                ) : (
                    <img
                        src={placeholderCover(post.id)}
                        alt=""
                        className="aspect-video w-full rounded-2xl object-cover"
                    />
                )}

                <header className="space-y-3">
                    <div className="flex flex-wrap items-center gap-2 text-sm text-muted-foreground">
                        <AccessBadge price={post.required_price} />
                        {post.category && (
                            <Badge variant="secondary" asChild>
                                <Link
                                    href={showPostCategory(post.category.slug)}
                                >
                                    {post.category.name}
                                </Link>
                            </Badge>
                        )}
                        {post.published_at && (
                            <span>投稿日 {formatDate(post.published_at)}</span>
                        )}
                        <span>最終更新日 {formatDate(post.updated_at)}</span>
                        {isOwner && (
                            <Link
                                href={edit(post.id)}
                                className="underline underline-offset-4"
                            >
                                編集する
                            </Link>
                        )}
                    </div>
                    <h1 className="text-3xl leading-snug font-black sm:text-4xl">
                        {post.title}
                    </h1>
                </header>

                {post.excerpt && (
                    <p className="text-lg leading-loose whitespace-pre-wrap">
                        {post.excerpt}
                    </p>
                )}

                {post.locked ? (
                    <>
                        {/* Stands in for the first lines of the body, which is never sent to a viewer who can't read it. */}
                        <div
                            aria-hidden
                            className="-mb-4 space-y-3.5 [mask-image:linear-gradient(to_bottom,black,transparent)]"
                        >
                            {['w-full', 'w-11/12', 'w-full', 'w-3/4'].map(
                                (width, index) => (
                                    <div
                                        key={index}
                                        className={`h-3.5 rounded-full bg-muted-foreground/15 ${width}`}
                                    />
                                ),
                            )}
                        </div>
                        <LockedPost
                            requiredPrice={post.required_price}
                            supportPrice={viewerSupportPrice}
                            creatorName={creator.display_name}
                        >
                            {unlocking.length > 0 && (
                                <div id="plans" className="grid gap-3">
                                    {unlocking.map((plan, index) => (
                                        <PlanCard
                                            key={plan.id}
                                            plan={plan}
                                            featured={
                                                unlocking.length > 1 &&
                                                index === 0
                                            }
                                            featuredLabel="この投稿がいちばん安く読めます"
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
                            )}
                        </LockedPost>
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
                                                className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm hover:bg-muted"
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

                <footer className="flex items-center justify-between gap-4 border-y py-4">
                    <LikeButton post={post} />
                    <ReportDialog
                        targetType="post"
                        targetId={post.id}
                        reasons={reportReasons}
                        isOwner={isOwner}
                    />
                </footer>

                {morePosts.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-xl font-bold">
                            {creator.display_name}さんのほかの投稿
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {morePosts.map((other) => (
                                <PostCard key={other.id} post={other} />
                            ))}
                        </div>
                    </section>
                )}
            </article>
        </>
    );
}
