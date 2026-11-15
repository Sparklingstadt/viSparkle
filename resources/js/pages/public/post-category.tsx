import { Head } from '@inertiajs/react';
import { CategoryLinks } from '@/components/category-links';
import { PostCard } from '@/components/post-card';
import { show } from '@/routes/post-categories';
import type { CategorySummary, Paginated, PostSummary } from '@/types';
import { Pagination } from '@/components/pagination';

export default function PostCategory({
    category,
    categories,
    posts,
}: {
    category: CategorySummary;
    categories: CategorySummary[];
    posts: Paginated<PostSummary>;
}) {
    return (
        <>
            <Head title={`${category.name}の投稿`} />

            <div className="space-y-6">
                <h1 className="text-xl font-bold break-words">
                    {category.name}
                </h1>

                <CategoryLinks
                    categories={categories}
                    current={category.slug}
                    href={(slug) => show(slug ?? category.slug).url}
                />

                {posts.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        このカテゴリの投稿はまだありません。
                    </p>
                ) : (
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        {posts.data.map((post) => (
                            <PostCard key={post.id} post={post} showCreator />
                        ))}
                    </div>
                )}

                <Pagination page={posts} />
            </div>
        </>
    );
}
