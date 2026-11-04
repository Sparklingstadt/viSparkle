import { router, usePage } from '@inertiajs/react';
import { Heart } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import PostLikeController from '@/actions/App/Http/Controllers/Fan/PostLikeController';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import type { PostSummary } from '@/types';

// Flips at once on click; the server's answer replaces it, and a failure puts the old state back.
export function LikeButton({ post }: { post: PostSummary }) {
    const { auth } = usePage().props;
    const [pending, setPending] = useState<boolean | null>(null);

    const liked = pending ?? post.liked;
    const count =
        post.like_count +
        (pending === null ? 0 : Number(pending) - Number(post.liked));
    // An old like can still be taken back after the support ended.
    const disabled = pending !== null || (!post.can_like && !post.liked);

    const toggle = () => {
        if (!auth.user) {
            router.visit(login());

            return;
        }

        const args = { creator: post.creator.slug, post: post.id };
        const options = {
            preserveScroll: true,
            onError: () => toast.error('いいねできませんでした。'),
            onFinish: () => setPending(null),
        };

        setPending(!liked);

        if (liked) {
            router.delete(PostLikeController.destroy.url(args), options);
        } else {
            router.post(PostLikeController.store.url(args), {}, options);
        }
    };

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
                <Heart className={liked ? 'fill-current text-rose-500' : ''} />
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
