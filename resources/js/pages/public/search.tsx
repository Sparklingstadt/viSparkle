import { Head, Link } from '@inertiajs/react';
import { CategoryLinks } from '@/components/category-links';
import { PostCard } from '@/components/post-card';
import { ProductCard } from '@/components/product-card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { search } from '@/routes';
import { show } from '@/routes/creators';
import type {
    CategorySummary,
    CreatorSummary,
    PostSummary,
    ShopProductSummary,
} from '@/types';

export default function Search({
    q,
    type,
    category,
    categories,
    postCategory,
    postCategories,
    creators,
    products,
    posts,
}: {
    q: string;
    type: string;
    category: string | null;
    categories: CategorySummary[];
    postCategory: string | null;
    postCategories: CategorySummary[];
    creators: CreatorSummary[];
    products: (ShopProductSummary & { creator: CreatorSummary })[];
    posts: PostSummary[];
}) {
    const showProducts =
        q !== '' &&
        (type === 'all' || type === 'products') &&
        categories.length > 0;
    const showProductsSection =
        q !== '' &&
        (type === 'all' || type === 'products') &&
        (products.length > 0 || category !== null);
    const showPostLinks =
        q !== '' &&
        (type === 'all' || type === 'posts') &&
        postCategories.length > 0;
    const showPostsSection =
        q !== '' &&
        (type === 'all' || type === 'posts') &&
        (posts.length > 0 || postCategory !== null);
    const found = creators.length + products.length + posts.length > 0;
    const filtered = category !== null || postCategory !== null;

    return (
        <>
            <Head title={q ? `「${q}」の検索結果` : '検索'} />

            <div className="space-y-10">
                {q === '' ? (
                    <p className="text-sm text-muted-foreground">
                        キーワードを入力してください。
                    </p>
                ) : (
                    <h1 className="text-xl font-bold break-words">
                        「{q}」の検索結果
                    </h1>
                )}

                {q !== '' && !found && !filtered && (
                    <p className="text-sm text-muted-foreground">
                        見つかりませんでした。
                    </p>
                )}

                {creators.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">クリエイター</h2>
                        <ul className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {creators.map((creator) => (
                                <li key={creator.slug}>
                                    <Link
                                        href={show(creator.slug)}
                                        className="flex items-center gap-3 rounded-xl border bg-card p-3 transition-colors hover:border-foreground/30 hover:bg-muted/70"
                                    >
                                        <Avatar className="size-10">
                                            <AvatarImage
                                                src={
                                                    creator.avatar_url ??
                                                    undefined
                                                }
                                                alt=""
                                            />
                                            <AvatarFallback>
                                                {creator.display_name.slice(
                                                    0,
                                                    1,
                                                )}
                                            </AvatarFallback>
                                        </Avatar>
                                        <span className="truncate text-sm font-medium">
                                            {creator.display_name}
                                        </span>
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}

                {showProductsSection && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">商品</h2>
                        {showProducts && (
                            <CategoryLinks
                                categories={categories}
                                current={category}
                                allLabel="すべて"
                                href={(slug) =>
                                    search({
                                        query: {
                                            q,
                                            type,
                                            ...(slug ? { category: slug } : {}),
                                            ...(postCategory
                                                ? {
                                                      post_category:
                                                          postCategory,
                                                  }
                                                : {}),
                                        },
                                    }).url
                                }
                            />
                        )}
                        {products.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                このカテゴリの商品は見つかりませんでした。
                            </p>
                        ) : (
                            <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                                {products.map((product) => (
                                    <ProductCard
                                        key={product.id}
                                        product={product}
                                    />
                                ))}
                            </div>
                        )}
                    </section>
                )}

                {showPostsSection && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">全体公開投稿</h2>
                        {showPostLinks && (
                            <CategoryLinks
                                categories={postCategories}
                                current={postCategory}
                                allLabel="すべて"
                                href={(slug) =>
                                    search({
                                        query: {
                                            q,
                                            type,
                                            ...(category ? { category } : {}),
                                            ...(slug
                                                ? { post_category: slug }
                                                : {}),
                                        },
                                    }).url
                                }
                            />
                        )}
                        {posts.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                このカテゴリの投稿は見つかりませんでした。
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
                )}
            </div>
        </>
    );
}
