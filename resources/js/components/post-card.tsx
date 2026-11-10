import { Link } from '@inertiajs/react';
import { Heart, Lock } from 'lucide-react';
import { AccessBadge } from '@/components/access-badge';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { formatDate, formatYen } from '@/lib/format';
import { placeholderCover } from '@/lib/placeholder';
import { cn } from '@/lib/utils';
import { show } from '@/routes/posts';
import type { PostSummary } from '@/types';

export function PostCard({
    post,
    showCreator = false,
}: {
    post: PostSummary;
    showCreator?: boolean;
}) {
    return (
        <Link
            href={show({ creator: post.creator.slug, post: post.id })}
            className="group flex flex-col gap-3 rounded-2xl p-1 transition-colors hover:bg-muted/70"
        >
            <div className="relative aspect-video overflow-hidden rounded-xl bg-muted">
                {post.cover_thumb_url ? (
                    <img
                        src={post.cover_thumb_url}
                        alt=""
                        loading="lazy"
                        className={`size-full object-cover ${post.locked ? 'blur-md' : ''}`}
                    />
                ) : (
                    <img
                        src={placeholderCover(post.id)}
                        alt=""
                        loading="lazy"
                        className="size-full object-cover"
                    />
                )}
                {post.locked && (
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-2 bg-black/50 text-white">
                        <Lock className="size-5" aria-hidden />
                        <span className="text-sm font-bold">
                            {formatYen(post.required_price)} プランで読めます
                        </span>
                    </div>
                )}
            </div>
            <div className="flex gap-2.5 px-1 pb-1">
                {showCreator && (
                    <Avatar className="size-8 shrink-0">
                        {post.creator.avatar_url && (
                            <AvatarImage src={post.creator.avatar_url} alt="" />
                        )}
                        <AvatarFallback className="text-xs">
                            {post.creator.display_name.slice(0, 1)}
                        </AvatarFallback>
                    </Avatar>
                )}
                <div className="min-w-0 flex-1 space-y-1.5">
                    <p className="line-clamp-2 leading-snug font-bold">
                        {post.title}
                    </p>
                    <p className="flex flex-wrap items-center gap-x-1 text-xs text-muted-foreground">
                        {showCreator && (
                            <>
                                <span className="truncate">
                                    {post.creator.display_name}
                                </span>
                                <span aria-hidden>·</span>
                            </>
                        )}
                        <span>{formatDate(post.published_at)}</span>
                        {!!post.like_count && (
                            <span
                                className={cn(
                                    'ml-1 inline-flex items-center gap-0.5',
                                    post.liked && 'font-medium text-support',
                                )}
                            >
                                <Heart
                                    aria-label={
                                        post.liked ? 'いいね済み' : 'いいね'
                                    }
                                    className={cn(
                                        'size-3',
                                        post.liked && 'fill-current',
                                    )}
                                />
                                {post.like_count}
                            </span>
                        )}
                    </p>
                    {!post.locked && (
                        <AccessBadge price={post.required_price} />
                    )}
                </div>
            </div>
        </Link>
    );
}
