import { Head, Link } from '@inertiajs/react';
import { CategoryLinks } from '@/components/category-links';
import { Carousel } from '@/components/carousel';
import { CreatorCard } from '@/components/creator-card';
import { PostCard } from '@/components/post-card';
import { ProductCarousel } from '@/components/product-carousel';
import type { LikeableProduct } from '@/components/product-like-button';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { home, search } from '@/routes';
import { register as registerCreator } from '@/routes/creator';
import { show as showCategory } from '@/routes/categories';
import { show as showPostCategory } from '@/routes/post-categories';
import type {
    CategorySummary,
    CreatorCard as CreatorCardData,
    PostSummary,
} from '@/types';

type PostSort = 'new' | 'popular';

const sortTabs: { label: string; sort: PostSort }[] = [
    { label: '新着', sort: 'new' },
    { label: '人気', sort: 'popular' },
];

export default function Home({
    posts,
    products,
    recommendedProducts,
    popularCreators,
    categories,
    postCategories,
    sort,
}: {
    posts: PostSummary[];
    products: LikeableProduct[];
    recommendedProducts: LikeableProduct[];
    popularCreators: CreatorCardData[];
    categories: CategorySummary[];
    postCategories: CategorySummary[];
    sort: PostSort;
}) {
    // The hero shows the top four only when every one of them has a header image to fill its tile.
    const heroCreators = popularCreators
        .slice(0, 4)
        .filter((creator) => creator.header_url !== null);

    return (
        <>
            <Head title="ホーム" />

            <div className="space-y-10">
                <section className="grid items-center gap-10 pt-6 md:grid-cols-2">
                    <div className="space-y-5">
                        <p className="text-sm font-bold tracking-wider text-primary">
                            月額でつながる創作の場所
                        </p>
                        <h1 className="text-4xl leading-tight font-black sm:text-5xl">
                            クリエイターを、
                            <br />
                            月額で応援しよう
                        </h1>
                        <p className="leading-relaxed text-muted-foreground">
                            支援すると、プランに応じた限定投稿が見られます。
                            <br />
                            グッズやデジタル作品もまとめて買えます。
                        </p>
                        <div className="flex flex-wrap gap-3 pt-1">
                            <Button asChild size="lg">
                                <Link href={search()}>クリエイターを探す</Link>
                            </Button>
                            <Button asChild size="lg" variant="outline">
                                <Link href={registerCreator()}>
                                    クリエイターとしてはじめる
                                </Link>
                            </Button>
                        </div>
                    </div>
                    {heroCreators.length === 4 && (
                        <div
                            className="hidden grid-cols-2 gap-4 md:grid"
                            aria-hidden
                        >
                            {heroCreators.map((creator, index) => (
                                <div
                                    key={creator.slug}
                                    className={cn(
                                        'overflow-hidden rounded-2xl border bg-card',
                                        index % 2 === 1
                                            ? 'translate-y-6 rotate-2'
                                            : '-rotate-2',
                                    )}
                                >
                                    <div className="h-24 bg-secondary">
                                        {creator.header_url && (
                                            <img
                                                src={creator.header_url}
                                                alt=""
                                                className="size-full object-cover"
                                            />
                                        )}
                                    </div>
                                    <div className="space-y-1 px-3.5 pb-3.5">
                                        <Avatar className="-mt-5 size-11 border-[3px] border-card">
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
                                        <p className="truncate text-sm font-bold">
                                            {creator.display_name}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )}
                </section>

                {popularCreators.length > 0 && (
                    <Carousel
                        title="今週の人気クリエイター"
                        description="直近7日間に新しく支援を始めたファンが多い順です。"
                        trackClassName="scroll-pl-1 pt-1 pl-1"
                    >
                        {popularCreators.map((creator, index) => (
                            <li
                                key={creator.slug}
                                className="w-52 shrink-0 snap-start"
                            >
                                <CreatorCard
                                    creator={creator}
                                    rank={index + 1}
                                />
                            </li>
                        ))}
                    </Carousel>
                )}

                {categories.length > 0 && (
                    <CategoryLinks
                        categories={categories}
                        href={(slug) => showCategory(slug!).url}
                    />
                )}

                {recommendedProducts.length > 0 && (
                    <ProductCarousel
                        title="おすすめの商品"
                        products={recommendedProducts}
                    />
                )}

                {products.length === 0 ? (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">新着商品</h2>
                        <p className="text-sm text-muted-foreground">
                            出品されている商品はまだありません。
                        </p>
                    </section>
                ) : (
                    <ProductCarousel title="新着商品" products={products} />
                )}

                <section className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h2 className="text-lg font-semibold">
                            {sort === 'popular'
                                ? '人気の全体公開投稿'
                                : '新着の全体公開投稿'}
                        </h2>
                        <div
                            className="flex gap-1 rounded-full bg-muted p-1"
                            role="tablist"
                        >
                            {sortTabs.map((tab) => (
                                <Button
                                    key={tab.sort}
                                    size="sm"
                                    variant="ghost"
                                    asChild
                                    className={cn(
                                        'text-muted-foreground',
                                        tab.sort === sort &&
                                            'bg-background text-foreground shadow-xs hover:bg-background',
                                    )}
                                >
                                    <Link
                                        href={home({
                                            query:
                                                tab.sort === 'new'
                                                    ? { sort: 'new' }
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
                    {postCategories.length > 0 && (
                        <CategoryLinks
                            categories={postCategories}
                            href={(slug) => showPostCategory(slug!).url}
                        />
                    )}
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
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
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
