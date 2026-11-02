import { Head, Link } from '@inertiajs/react';
import { PostCard } from '@/components/post-card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show } from '@/routes/creators';
import { show as showProduct } from '@/routes/shop/products';
import type { CreatorSummary, PostSummary, ShopProductSummary } from '@/types';

type PostSort = 'new' | 'popular';

const sortTabs: { label: string; sort: PostSort }[] = [
    { label: '新着', sort: 'new' },
    { label: '人気', sort: 'popular' },
];

export default function Home({
    posts,
    products,
    popularCreators,
    sort,
}: {
    posts: PostSummary[];
    products: (ShopProductSummary & { creator: CreatorSummary })[];
    popularCreators: CreatorSummary[];
    sort: PostSort;
}) {
    return (
        <>
            <Head title="ホーム" />

            <div className="space-y-10">
                <section className="space-y-2 py-6 text-center">
                    <h1 className="text-3xl font-bold">
                        クリエイターを、月額で応援しよう
                    </h1>
                    <p className="text-muted-foreground">
                        支援すると、プランに応じた限定投稿を見られます。
                    </p>
                </section>

                {popularCreators.length > 0 && (
                    <section className="space-y-4">
                        <div className="space-y-1">
                            <h2 className="text-lg font-semibold">
                                今週の人気クリエイター
                            </h2>
                            <p className="text-sm text-muted-foreground">
                                直近7日間に新しく支援を始めたファンが多い順です。
                            </p>
                        </div>
                        <ol className="flex gap-4 overflow-x-auto pb-2 sm:flex-wrap sm:overflow-visible">
                            {popularCreators.map((creator, index) => (
                                <li key={creator.slug} className="shrink-0">
                                    <Link
                                        href={show(creator.slug)}
                                        className="flex w-24 flex-col items-center gap-2 text-center"
                                    >
                                        <div className="relative">
                                            <Avatar className="size-16">
                                                {creator.avatar_url && (
                                                    <AvatarImage
                                                        src={creator.avatar_url}
                                                        alt=""
                                                    />
                                                )}
                                                <AvatarFallback>
                                                    {creator.display_name.slice(
                                                        0,
                                                        1,
                                                    )}
                                                </AvatarFallback>
                                            </Avatar>
                                            <span className="absolute -top-1 -left-1 flex size-6 items-center justify-center rounded-full bg-primary text-xs font-bold text-primary-foreground">
                                                {index + 1}
                                            </span>
                                        </div>
                                        <span className="line-clamp-2 text-sm">
                                            {creator.display_name}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ol>
                    </section>
                )}

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">新着商品</h2>
                    {products.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            出品されている商品はまだありません。
                        </p>
                    ) : (
                        <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                            {products.map((product) => (
                                <Link
                                    key={product.id}
                                    href={showProduct({
                                        creator: product.creator.slug,
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
                                        <p className="truncate text-xs text-muted-foreground">
                                            {product.creator.display_name}
                                        </p>
                                        <p className="flex items-center gap-2 text-sm">
                                            {formatYen(product.price)}
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
                    )}
                </section>

                <section className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold">
                            {sort === 'popular'
                                ? '人気の全体公開投稿'
                                : '新着の全体公開投稿'}
                        </h2>
                        <div className="flex gap-1" role="tablist">
                            {sortTabs.map((tab) => (
                                <Button
                                    key={tab.sort}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn({
                                        'bg-muted': tab.sort === sort,
                                    })}
                                >
                                    <Link
                                        href={home({
                                            query:
                                                tab.sort === 'popular'
                                                    ? { sort: 'popular' }
                                                    : {},
                                        })}
                                        role="tab"
                                        aria-selected={tab.sort === sort}
                                        preserveScroll
                                        only={['posts', 'sort']}
                                    >
                                        {tab.label}
                                    </Link>
                                </Button>
                            ))}
                        </div>
                    </div>
                    {sort === 'popular' && (
                        <p className="text-sm text-muted-foreground">
                            直近30日に公開された投稿を、閲覧数の多い順に表示しています。
                        </p>
                    )}
                    {posts.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            まだ投稿がありません。
                        </p>
                    ) : (
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {posts.map((post) => (
                                <PostCard
                                    key={post.id}
                                    post={post}
                                    showCreator
                                />
                            ))}
                        </div>
                    )}
                </section>
            </div>
        </>
    );
}
