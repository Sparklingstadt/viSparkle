import { Head, Link } from '@inertiajs/react';
import { Download, Paperclip } from 'lucide-react';
import { CreatorHeader } from '@/components/creator-header';
import { LockedPost } from '@/components/locked-post';
import { PlanCard } from '@/components/plan-card';
import { SupportButton } from '@/components/support-button';
import { PostRenderer } from '@/components/tiptap/post-renderer';
import { Badge } from '@/components/ui/badge';
import { formatBytes, formatDate, formatYen } from '@/lib/format';
import { edit } from '@/routes/creator/posts';
import type { CreatorProfile, PlanData, PostDetail } from '@/types';

export default function PostPage({
    post,
    creator,
    plans,
    isOwner,
    viewerSupportPrice,
    viewerPlanId,
}: {
    post: PostDetail;
    creator: CreatorProfile;
    plans: PlanData[];
    isOwner: boolean;
    viewerSupportPrice: number | null;
    viewerPlanId: number | null;
}) {
    const unlocking = plans.filter((plan) => plan.price >= post.required_price);

    return (
        <>
            <Head title={post.title} />

            <article className="mx-auto max-w-3xl space-y-6">
                <CreatorHeader creator={creator} compact />

                {post.cover_thumb_url && (
                    <img
                        src={post.cover_thumb_url}
                        alt=""
                        className={`aspect-video w-full rounded-xl object-cover ${post.locked ? 'blur-md' : ''}`}
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
                                                    viewerSupportId={null}
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
                                <p className="text-xs text-muted-foreground">
                                    ダウンロードリンクの有効期限は5分です。期限切れの場合はページを再読み込みしてください。
                                </p>
                            </section>
                        )}
                    </>
                )}
            </article>
        </>
    );
}
