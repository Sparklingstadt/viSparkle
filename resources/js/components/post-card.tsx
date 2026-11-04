import { Link } from '@inertiajs/react';
import { Heart, Lock } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { formatDate, formatYen } from '@/lib/format';
import { placeholderCover } from '@/lib/placeholder';
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
            className="group flex flex-col overflow-hidden rounded-xl border bg-card transition-colors hover:bg-muted/40"
        >
            <div className="relative aspect-video bg-muted">
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
                    <div className="absolute inset-0 flex items-center justify-center">
                        <Badge className="gap-1">
                            <Lock className="size-3" />
                            {formatYen(post.required_price)}〜
                        </Badge>
                    </div>
                )}
            </div>
            <div className="space-y-1 p-3">
                <p className="line-clamp-2 font-medium">{post.title}</p>
                <p className="text-xs text-muted-foreground">
                    {showCreator && (
                        <>
                            <Avatar className="mr-1 inline-flex size-4 align-[-3px]">
                                {post.creator.avatar_url && (
                                    <AvatarImage
                                        src={post.creator.avatar_url}
                                        alt=""
                                    />
                                )}
                                <AvatarFallback className="text-[10px]">
                                    {post.creator.display_name.slice(0, 1)}
                                </AvatarFallback>
                            </Avatar>
                            {post.creator.display_name}
                            {' · '}
                        </>
                    )}
                    {formatDate(post.published_at)}
                    {!post.locked && post.required_price > 0 && (
                        <> · {formatYen(post.required_price)}〜</>
                    )}
                    {!!post.like_count && (
                        <>
                            {' · '}
                            <Heart className="inline size-3 align-[-1px]" />{' '}
                            {post.like_count}
                        </>
                    )}
                </p>
            </div>
        </Link>
    );
}
