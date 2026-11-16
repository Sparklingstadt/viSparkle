import { Head, Link } from '@inertiajs/react';
import { PostCard } from '@/components/post-card';
import { Button } from '@/components/ui/button';
import { register as registerCreator } from '@/routes/creator';
import type { PostSummary } from '@/types';

export default function Home({ posts }: { posts: PostSummary[] }) {
    return (
        <>
            <Head title="ホーム" />

            <div className="space-y-10">
                <section className="space-y-5 pt-6">
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
                    </p>
                    <div className="flex flex-wrap gap-3 pt-1">
                        <Button asChild size="lg" variant="outline">
                            <Link href={registerCreator()}>
                                クリエイターとしてはじめる
                            </Link>
                        </Button>
                    </div>
                </section>

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">
                        新着の全体公開投稿
                    </h2>
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
