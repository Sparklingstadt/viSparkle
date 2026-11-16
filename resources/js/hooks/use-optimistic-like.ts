import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import { login } from '@/routes';

/**
 * A like toggle that flips at once on click; the server's answer replaces it, and a failure puts the old state back.
 * A guest is sent to the login page instead.
 */
export function useOptimisticLike({
    liked: savedLiked,
    count: savedCount,
    storeUrl,
    destroyUrl,
}: {
    liked: boolean;
    count: number;
    storeUrl: string;
    destroyUrl: string;
}) {
    const { auth } = usePage().props;
    const [pending, setPending] = useState<boolean | null>(null);

    const liked = pending ?? savedLiked;
    const count =
        savedCount +
        (pending === null ? 0 : Number(pending) - Number(savedLiked));

    const toggle = () => {
        if (!auth.user) {
            router.visit(login());

            return;
        }

        const options = {
            preserveScroll: true,
            onError: () => toast.error('いいねできませんでした。'),
            onFinish: () => setPending(null),
        };

        setPending(!liked);

        if (liked) {
            router.delete(destroyUrl, options);
        } else {
            router.post(storeUrl, {}, options);
        }
    };

    return { liked, count, pending: pending !== null, toggle };
}
