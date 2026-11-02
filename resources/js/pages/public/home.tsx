import { Head, Link } from '@inertiajs/react';
import { PostCard } from '@/components/post-card';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { home } from '@/routes';
import { show } from '@/routes/creators';
import type { CreatorSummary, PostSummary } from '@/types';

type PostSort = 'new' | 'popular';

const sortTabs: { label: string; sort: PostSort }[] = [
    { label: '新着', sort: 'new' },
    { label: '人気', sort: 'popular' },
];

export default function Home({
    posts,
    creators,
    popularCreators,
    sort,
}: {
    posts: PostSummary[];
    creators: CreatorSummary[];
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
                    <h2 className="text-lg font-semibold">新着クリエイター</h2>
                    {creators.length === 0 ? (
                        <p className="text-sm text-muted-foreground">
                            支援を受け付けているクリエイターはまだいません。
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
