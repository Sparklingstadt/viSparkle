import { Head, Link } from '@inertiajs/react';
import { CreatorHeader } from '@/components/creator-header';
import { FollowButton } from '@/components/follow-button';
import { PlanCard } from '@/components/plan-card';
import { PostCard } from '@/components/post-card';
import {
    ReportDialog,
    type ReportReasonOption,
} from '@/components/report-dialog';
import { SupportButton } from '@/components/support-button';
import { show } from '@/routes/creators';
import { show as showProduct } from '@/routes/shop/products';
import type {
    CreatorProfile,
    Paginated,
    PlanData,
    PostSummary,
    ShopProductSummary,
} from '@/types';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';

type ShopSort = 'new' | 'popular';

const shopSortTabs: { label: string; sort: ShopSort }[] = [
    { label: '公開順', sort: 'new' },
    { label: '人気順', sort: 'popular' },
];

export default function CreatorPage({
    creator,
    plans,
    posts,
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
    shop: {
        visible: boolean;
        notice: string | null;
        sort: ShopSort;
        products: ShopProductSummary[];
    };
}) {
    return (
        <>
            <Head title={creator.display_name} />

            <div className="space-y-8">
                <div className="space-y-1">
                    <CreatorHeader creator={creator} />
                    <div className="flex items-center justify-end gap-2">
                        {/* A closed creator takes no new follows; current followers can still unfollow. */}
                        {!isOwner && (!closed || viewerFollows) && (
                            <FollowButton
                                creatorSlug={creator.slug}
                                following={viewerFollows}
                            />
                        )}
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

                {shop.visible && (
                    <nav
                        className="flex gap-2 border-b"
                        aria-label="コンテンツ"
                    >
                        {(
                            [
                                ['posts', '投稿'],
                                ['shop', 'ショップ'],
                            ] as const
                        ).map(([key, label]) => (
                            <Link
                                key={key}
                                href={
                                    key === 'shop'
                                        ? show(creator.slug, {
                                              query: { tab: 'shop' },
                                          })
                                        : show(creator.slug)
                                }
                                preserveScroll
                                aria-current={tab === key ? 'page' : undefined}
                                className={`-mb-px border-b-2 px-3 py-2 text-sm font-medium ${
                                    tab === key
                                        ? 'border-primary'
                                        : 'border-transparent text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {label}
                            </Link>
                        ))}
                    </nav>
                )}

                {tab === 'shop' ? (
                    <section className="space-y-4">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 className="text-lg font-semibold">ショップ</h2>
                            <div className="flex gap-1" role="tablist">
                                {shopSortTabs.map((sortTab) => (
                                    <Button
                                        key={sortTab.sort}
                                        size="sm"
                                        variant="ghost"
                                        asChild
                                        className={cn({
                                            'bg-muted':
                                                sortTab.sort === shop.sort,
                                        })}
                                    >
                                        <Link
                                            href={show(creator.slug, {
                                                query:
                                                    sortTab.sort === 'popular'
                                                        ? {
                                                              tab: 'shop',
                                                              sort: 'popular',
                                                          }
                                                        : { tab: 'shop' },
                                            })}
                                            role="tab"
                                            aria-selected={
                                                sortTab.sort === shop.sort
                                            }
                                            preserveScroll
                                            only={['shop']}
                                        >
                                            {sortTab.label}
                                        </Link>
                                    </Button>
                                ))}
                            </div>
                        </div>
                        {shop.notice && (
                            <p className="rounded-md border bg-muted p-4 text-sm whitespace-pre-wrap">
                                {shop.notice}
                            </p>
                        )}
                        {!canBuy && (
                            <p className="text-sm text-muted-foreground">
                                現在購入できません
                            </p>
                        )}
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {shop.products.map((product) => (
                                <Link
                                    key={product.id}
                                    href={showProduct({
                                        creator: creator.slug,
                                        product: product.id,
                                    })}
                                    className="flex flex-col overflow-hidden rounded-xl border bg-card transition-colors hover:bg-muted/40"
                                >
                                    <div className="aspect-square bg-muted">
                                        {product.cover_url && (
                                            <img
                                                src={product.cover_url}
                                                alt=""
                                                loading="lazy"
                                                className="size-full object-cover"
                                            />
                                        )}
                                    </div>
                                    <div className="space-y-1 p-3">
                                        <p className="line-clamp-2 font-medium">
                                            {product.name}
                                        </p>
                                        <p className="flex items-center gap-2 text-sm">
                                            {formatYen(product.price)}
                                            {product.kind === 'digital' && (
                                                <Badge variant="outline">
                                                    デジタル
                                                </Badge>
                                            )}
                                            {product.sold_out && (
                                                <Badge variant="secondary">
                                                    売り切れ
                                                </Badge>
                                            )}
                                        </p>
                                    </div>
                                </Link>
                            ))}
                        </div>
                    </section>
                ) : (
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
                )}
            </div>
        </>
    );
}
