import { Head, Link } from '@inertiajs/react';
import { CreatorHeader } from '@/components/creator-header';
import { PlanCard } from '@/components/plan-card';
import { PostCard } from '@/components/post-card';
import {
    ReportDialog,
    type ReportReasonOption,
} from '@/components/report-dialog';
import { SupportButton } from '@/components/support-button';
import type { CreatorProfile, Paginated, PlanData, PostSummary } from '@/types';

export default function CreatorPage({
    creator,
    plans,
    posts,
    isOwner,
    viewerSupportPrice,
    viewerPlanId,
    viewerSupportId,
    viewerSupportCancelling,
    acceptsSupports,
    closed,
    reportReasons,
}: {
    creator: CreatorProfile & { id: number };
    plans: PlanData[];
    posts: Paginated<PostSummary>;
    isOwner: boolean;
    viewerSupportPrice: number | null;
    viewerPlanId: number | null;
    viewerSupportId: number | null;
    viewerSupportCancelling: boolean;
    acceptsSupports: boolean;
    closed: boolean;
    reportReasons: ReportReasonOption[];
}) {
    return (
        <>
            <Head title={creator.display_name} />

            <div className="space-y-8">
                <div className="space-y-1">
                    <CreatorHeader creator={creator} />
                    <div className="flex justify-end">
                        <ReportDialog
                            targetType="creator"
                            targetId={creator.id}
                            reasons={reportReasons}
                            isOwner={isOwner}
                        />
                    </div>
                </div>

                {closed && (
                    <p className="rounded-md border bg-muted p-4 text-sm">
                        このクリエイターは活動を終了しました。
                    </p>
                )}

                <section id="plans" className="space-y-4">
                    <h2 className="text-lg font-semibold">支援プラン</h2>
                    {plans.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            現在受け付けているプランはありません。
                        </p>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {plans.map((plan) => (
                                <PlanCard
                                    key={plan.id}
                                    plan={plan}
                                    action={
                                        // A closed creator takes no new supports; current supporters can still manage theirs.
                                        isOwner ||
                                        (closed &&
                                            viewerSupportId === null) ? null : (
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
                                        )
                                    }
                                />
                            ))}
                        </div>
                    )}
                </section>

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">投稿</h2>
                    {posts.data.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            まだ投稿がありません。
                        </p>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {posts.data.map((post) => (
                                <PostCard key={post.id} post={post} />
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
                </section>
            </div>
        </>
    );
}
