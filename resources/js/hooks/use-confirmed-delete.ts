import { router } from '@inertiajs/react';
import { useConfirm } from '@/components/confirm-dialog';

/**
 * Deletes at `url` (keeping the scroll position) once the user confirms `title` in the app's dialog.
 */
export function useConfirmedDelete() {
    const confirm = useConfirm();

    return async (url: string, title: string) => {
        if (await confirm({ title, confirmLabel: '削除', destructive: true })) {
            router.delete(url, { preserveScroll: true });
        }
    };
}
