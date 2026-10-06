import { Head, Link } from '@inertiajs/react';
import { PostCard } from '@/components/post-card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { show } from '@/routes/creators';
import type { CreatorSummary, PostSummary } from '@/types';

export default function Home({
    posts,
    creators,
}: {
    posts: PostSummary[];
    creators: CreatorSummary[];
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

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">新着クリエイター</h2>
                    {creators.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            まだクリエイターがいません。
                        </p>
                    ) : (
                        <div className="flex flex-wrap gap-4">
                            {creators.map((creator) => (
                                <Link
                                    key={creator.slug}
                                    href={show(creator.slug)}
                                    className="flex w-24 flex-col items-center gap-2 text-center"
                                >
                                    <Avatar className="size-16">
                                        {creator.avatar_url && (
                                            <AvatarImage
                                                src={creator.avatar_url}
                                                alt=""
                                            />
                                        )}
                                        <AvatarFallback>
                                            {creator.display_name.slice(0, 1)}
                                        </AvatarFallback>
                                    </Avatar>
                                    <span className="line-clamp-2 text-sm">
                                        {creator.display_name}
                                    </span>
                                </Link>
                            ))}
                        </div>
                    )}
                </section>

                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">
                        新着の全体公開投稿
                    </h2>
                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                        {posts.map((post) => (
                            <PostCard key={post.id} post={post} showCreator />
                        ))}
                    </div>
                </section>
            </div>
        </>
    );
}
