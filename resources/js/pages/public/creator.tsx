import { Head } from '@inertiajs/react';
import { CreatorHeader } from '@/components/creator-header';
import { FollowButton } from '@/components/follow-button';
import { PlanCard } from '@/components/plan-card';
import {
    ReportDialog,
    type ReportReasonOption,
} from '@/components/report-dialog';
import { SupportButton } from '@/components/support-button';
import { show } from '@/routes/creators';
import type {
    CategorySummary,
    CreatorProfile,
    Paginated,
    PlanData,
    PostSummary,
} from '@/types';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { summarizePlans } from '@/components/creator-page/plan-summary';
import { PostsSection } from '@/components/creator-page/posts-section';
import {
    ShopSection,
    type CreatorShop,
} from '@/components/creator-page/shop-section';
import { SupportBar } from '@/components/creator-page/support-bar';
import { UnderlineTabs } from '@/components/underline-tabs';

export default function CreatorPage({
    creator,
    plans,
    posts,
    postCategory,
    postCategories,
    isOwner,
    viewerSupportPrice,
    viewerPlanId,
    viewerSupportId,
    viewerSupportCancelling,
    viewerFollows,
    acceptsSupports,
    closed,
    reportReasons,
    tab,
    can_buy: canBuy,
    shop,
}: {
    creator: CreatorProfile & { id: number };
    plans: PlanData[];
    posts: Paginated<PostSummary>;
    postCategory: string | null;
    postCategories: CategorySummary[];
    isOwner: boolean;
    viewerSupportPrice: number | null;
    viewerPlanId: number | null;
    viewerSupportId: number | null;
    viewerSupportCancelling: boolean;
    viewerFollows: boolean;
    acceptsSupports: boolean;
    closed: boolean;
    reportReasons: ReportReasonOption[];
    tab: 'posts' | 'shop';
    can_buy: boolean;
    shop: CreatorShop;
}) {
    const {
        accepting: acceptingPlans,
        cheapest: cheapestPlan,
        featuredId: featuredPlanId,
    } = summarizePlans(plans);
    // A visitor who could start supporting right now gets a "支援する" next to the name and, on phones, a bar fixed to the bottom.
    const showSupportBar =
        !isOwner &&
        !closed &&
        acceptsSupports &&
        viewerSupportId === null &&
        acceptingPlans.length > 0;

    return (
        <>
            <Head title={creator.display_name} />

            <div className={cn('space-y-8', showSupportBar && 'pb-24 lg:pb-0')}>
                <div className="space-y-2">
                    <CreatorHeader
                        creator={creator}
                        action={
                            <>
                                {/* A closed creator takes no new follows; current followers can still unfollow. */}
                                {!isOwner && (!closed || viewerFollows) && (
                                    <FollowButton
                                        creatorSlug={creator.slug}
                                        following={viewerFollows}
                                    />
                                )}
                                {showSupportBar && (
                                    <Button asChild>
                                        <a href="#plans">支援する</a>
                                    </Button>
                                )}
                            </>
                        }
                    />
                    <div className="flex items-center justify-end gap-2">
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

                <div className="grid gap-10 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <section
                        id="plans"
                        className="scroll-mt-6 space-y-4 lg:col-start-2 lg:row-start-1"
                    >
                        <h2 className="text-xl font-bold">支援プラン</h2>
                        {plans.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                現在受け付けているプランはありません。
                            </p>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-1">
                                {plans.map((plan) => (
                                    <PlanCard
                                        key={plan.id}
                                        plan={plan}
                                        featured={plan.id === featuredPlanId}
                                        action={
                                            // A closed creator takes no new supports; current supporters can still manage theirs.
                                            isOwner ||
                                            (closed &&
                                                viewerSupportId ===
                                                    null) ? null : (
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
                        <p className="text-xs leading-relaxed text-muted-foreground">
                            支援はいつでも停止できます。停止しても、支払い済みの期間が終わるまでは読めます。
                        </p>
                    </section>

                    <div className="min-w-0 space-y-6 lg:col-start-1 lg:row-start-1">
                        {shop.visible && (
                            <UnderlineTabs
                                label="コンテンツ"
                                tabs={[
                                    {
                                        key: 'posts',
                                        label: '投稿',
                                        href: show.url(creator.slug),
                                        current: tab === 'posts',
                                    },
                                    {
                                        key: 'shop',
                                        label: 'ショップ',
                                        href: show.url(creator.slug, {
                                            query: { tab: 'shop' },
                                        }),
                                        current: tab === 'shop',
                                    },
                                ]}
                            />
                        )}

                        {tab === 'shop' ? (
                            <ShopSection
                                creatorSlug={creator.slug}
                                shop={shop}
                                canBuy={canBuy}
                            />
                        ) : (
                            <PostsSection
                                creatorSlug={creator.slug}
                                posts={posts}
                                categories={postCategories}
                                currentCategory={postCategory}
                            />
                        )}
                    </div>
                </div>
            </div>

            {showSupportBar && cheapestPlan && (
                <SupportBar
                    planCount={acceptingPlans.length}
                    cheapest={cheapestPlan}
                />
            )}
        </>
    );
}
