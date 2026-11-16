import { Link } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { AccessBadge } from '@/components/access-badge';
import { formatDate, formatYen } from '@/lib/format';
import { placeholderCover } from '@/lib/placeholder';
import { show } from '@/routes/posts';
import type { PostSummary } from '@/types';

export function PostCard({ post }: { post: PostSummary }) {
    return (
        <Link
            href={show(post.id)}
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
                <div className="min-w-0 flex-1 space-y-1.5">
                    <p className="line-clamp-2 leading-snug font-bold">
                        {post.title}
                    </p>
                    <p className="flex flex-wrap items-center gap-x-1 text-xs text-muted-foreground">
                        <span>{formatDate(post.published_at)}</span>
                    </p>
                    {!post.locked && (
                        <AccessBadge price={post.required_price} />
                    )}
                </div>
            </div>
        </Link>
    );
}
