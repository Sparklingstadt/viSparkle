import { Head, Link } from '@inertiajs/react';
import { PostCard } from '@/components/post-card';
import { ProductCard } from '@/components/product-card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { show } from '@/routes/creators';
import type { CreatorSummary, PostSummary, ShopProductSummary } from '@/types';

export default function Search({
    q,
    creators,
    products,
    posts,
}: {
    q: string;
    creators: CreatorSummary[];
    products: (ShopProductSummary & { creator: CreatorSummary })[];
    posts: PostSummary[];
}) {
    const found = creators.length + products.length + posts.length > 0;

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

                {q !== '' && !found && (
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

                {products.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">商品</h2>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {products.map((product) => (
                                <ProductCard
                                    key={product.id}
                                    product={product}
                                />
                            ))}
                        </div>
                    </section>
                )}

                {posts.length > 0 && (
                    <section className="space-y-4">
                        <h2 className="text-lg font-semibold">全体公開投稿</h2>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            {posts.map((post) => (
                                <PostCard
                                    key={post.id}
                                    post={post}
                                    showCreator
                                />
                            ))}
                        </div>
                    </section>
                )}
            </div>
        </>
    );
}
