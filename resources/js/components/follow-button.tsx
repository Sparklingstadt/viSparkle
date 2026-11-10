import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import FollowController from '@/actions/App/Http/Controllers/Fan/FollowController';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';

// Flips at once on click; the server's answer replaces it, and a failure puts the old state back.
export function FollowButton({
    creatorSlug,
    following,
}: {
    creatorSlug: string;
    following: boolean;
}) {
    const { auth } = usePage().props;
    const [pending, setPending] = useState<boolean | null>(null);

    const followed = pending ?? following;

    const toggle = () => {
        if (!auth.user) {
            router.visit(login());

            return;
        }

        const args = { creator: creatorSlug };
        const options = {
            preserveScroll: true,
            onError: () =>
                toast.error(
                    followed
                        ? 'フォローを外せませんでした。'
                        : 'フォローできませんでした。',
                ),
            onFinish: () => setPending(null),
        };

        setPending(!followed);

        if (followed) {
            router.delete(FollowController.destroy.url(args), options);
        } else {
            router.post(FollowController.store.url(args), {}, options);
        }
    };

    return (
        <Button
            variant={followed ? 'secondary' : 'outline'}
            onClick={toggle}
            disabled={pending !== null}
            aria-pressed={followed}
            data-test="follow-button"
        >
            {followed ? 'フォロー中' : 'フォロー'}
        </Button>
    );
}
