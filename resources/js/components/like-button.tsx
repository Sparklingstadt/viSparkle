import { Heart } from 'lucide-react';
import PostLikeController from '@/actions/App/Http/Controllers/Fan/PostLikeController';
import { Button } from '@/components/ui/button';
import { useOptimisticLike } from '@/hooks/use-optimistic-like';
import type { PostSummary } from '@/types';

export function LikeButton({ post }: { post: PostSummary }) {
    const args = { creator: post.creator.slug, post: post.id };
    const { liked, count, pending, toggle } = useOptimisticLike({
        liked: post.liked,
        count: post.like_count,
        storeUrl: PostLikeController.store.url(args),
        destroyUrl: PostLikeController.destroy.url(args),
    });
    // An old like can still be taken back after the support ended.
    const disabled = pending || (!post.can_like && !post.liked);

    return (
        <div className="flex items-center gap-2">
            <Button
                variant="outline"
                size="sm"
                onClick={toggle}
                disabled={disabled}
                aria-pressed={liked}
                aria-label={liked ? 'いいねを取り消す' : 'いいねする'}
                data-test="like-button"
            >
                <Heart className={liked ? 'fill-current text-support' : ''} />
                <span>{count}</span>
            </Button>
            {post.locked && !post.liked && (
                <span className="text-xs text-muted-foreground">
                    支援すると、いいねできます
                </span>
            )}
        </div>
    );
}
