import { Head } from '@inertiajs/react';
import { CreatorHeader } from '@/components/creator-header';
import { PlanCard } from '@/components/plan-card';
import { PostCard } from '@/components/post-card';
import { SupportButton } from '@/components/support-button';
import type {
    CreatorProfile,
    Paginated,
    PlanData,
    PostSummary,
    SupportPanel,
} from '@/types';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';
import { Pagination } from '@/components/pagination';

/**
 * The creator's page, which is the site's top page: who they are, the plans to support, and the posts.
 */
export default function Home({
    creator,
    plans,
    posts,
    isCreator,
    viewerSupportPrice,
    viewerPlanId,
    viewerSupportId,
    viewerSupportCancelling,
}: SupportPanel & {
    creator: CreatorProfile;
    posts: Paginated<PostSummary>;
}) {
    const acceptingPlans = plans.filter((plan) => plan.is_accepting);
    const cheapestPlan = acceptingPlans.reduce<PlanData | null>(
        (min, plan) => (min === null || plan.price < min.price ? plan : min),
        null,
    );
    // The top accepting plan reads every post the others do and more, so it is the one drawn as featured.
    const featuredPlanId =
        acceptingPlans.length > 1
            ? acceptingPlans.reduce((max, plan) =>
                  plan.price > max.price ? plan : max,
              ).id
            : null;
    // A visitor who could start supporting right now gets a "支援する" next to the name and, on phones, a bar fixed to the bottom.
    const showSupportBar =
        !isCreator && viewerSupportId === null && acceptingPlans.length > 0;

    return (
        <>
            <Head title={creator.name} />

            <div className={cn('space-y-8', showSupportBar && 'pb-24 lg:pb-0')}>
                <div className="space-y-2">
                    <CreatorHeader
                        creator={creator}
                        action={
                            showSupportBar && (
                                <Button asChild>
                                    <a href="#plans">支援する</a>
                                </Button>
                            )
                        }
                    />
                </div>

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
                                            isCreator ? null : (
                                                <SupportButton
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

                    <section className="min-w-0 space-y-4 lg:col-start-1 lg:row-start-1">
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
                        <Pagination page={posts} />
                    </section>
                </div>
            </div>

            {showSupportBar && cheapestPlan && (
                <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur lg:hidden">
                    <div className="mx-auto flex max-w-5xl items-center gap-3">
                        <div className="min-w-0 flex-1">
                            <p className="text-xs text-muted-foreground">
                                支援プラン {acceptingPlans.length} 件
                            </p>
                            <p className="text-lg font-bold">
                                {formatYen(cheapestPlan.price)}
                                <span className="text-xs font-normal text-muted-foreground">
                                    {' '}
                                    / 月から
                                </span>
                            </p>
                        </div>
                        <Button asChild size="lg">
                            <a href="#plans">支援する</a>
                        </Button>
                    </div>
                </div>
            )}
        </>
    );
}
