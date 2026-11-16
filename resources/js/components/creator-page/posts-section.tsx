import { CategoryLinks } from '@/components/category-links';
import { Pagination } from '@/components/pagination';
import { PostCard } from '@/components/post-card';
import { show } from '@/routes/creators';
import type { CategorySummary, Paginated, PostSummary } from '@/types';

/** The creator's posts tab: the categories, the posts and the pages. */
export function PostsSection({
    creatorSlug,
    posts,
    categories,
    currentCategory,
}: {
    creatorSlug: string;
    posts: Paginated<PostSummary>;
    categories: CategorySummary[];
    currentCategory: string | null;
}) {
    return (
        <section className="space-y-4">
            <h2 className="text-lg font-semibold">投稿</h2>
            {categories.length > 0 && (
                <CategoryLinks
                    categories={categories}
                    current={currentCategory}
                    allLabel="すべて"
                    href={(slug) =>
                        show(creatorSlug, {
                            query: slug ? { post_category: slug } : {},
                        }).url
                    }
                />
            )}
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
    );
}
