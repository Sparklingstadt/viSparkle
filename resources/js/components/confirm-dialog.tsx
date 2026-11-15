import { createContext, useCallback, useContext, useState } from 'react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';

export type ConfirmOptions = {
    /** The question, e.g. 「この商品を削除しますか？」 */
    title: string;
    /** What happens next, when the title alone doesn't say it. */
    description?: string;
    /** The label of the button that goes ahead. */
    confirmLabel?: string;
    /** Shows the button that goes ahead in the destructive colour (deleting, refunding, stopping). */
    destructive?: boolean;
};

type Confirm = (options: ConfirmOptions) => Promise<boolean>;

type Request = ConfirmOptions & { resolve: (confirmed: boolean) => void };

const ConfirmContext = createContext<Confirm | null>(null);

/**
 * Hosts the one confirmation dialog of the app; pages ask through useConfirm().
 */
export function ConfirmProvider({ children }: { children: ReactNode }) {
    // The last request stays after closing, so the dialog keeps its text while it fades out.
    const [request, setRequest] = useState<Request | null>(null);
    const [open, setOpen] = useState(false);

    const confirm = useCallback<Confirm>(
        (options) =>
            new Promise((resolve) => {
                setRequest({ ...options, resolve });
                setOpen(true);
            }),
        [],
    );

    const close = (confirmed: boolean) => {
        if (!open) {
            return;
        }

        request?.resolve(confirmed);
        setOpen(false);
    };

    return (
        <ConfirmContext.Provider value={confirm}>
            {children}
            <Dialog open={open} onOpenChange={(next) => !next && close(false)}>
                <DialogContent>
                    <DialogTitle>{request?.title}</DialogTitle>
                    {request?.description && (
                        <DialogDescription>
                            {request.description}
                        </DialogDescription>
                    )}
                    <DialogFooter className="gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            onClick={() => close(false)}
                        >
                            キャンセル
                        </Button>
                        <Button
                            type="button"
                            variant={
                                request?.destructive ? 'destructive' : 'default'
                            }
                            onClick={() => close(true)}
                            data-test="confirm-dialog-ok"
                        >
                            {request?.confirmLabel ?? 'OK'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </ConfirmContext.Provider>
    );
}

/**
 * Asks the user to confirm in the app's dialog; resolves to true when they go ahead.
 */
export function useConfirm(): Confirm {
    const confirm = useContext(ConfirmContext);

    if (confirm === null) {
        throw new Error('useConfirm() needs a ConfirmProvider above it.');
    }

    return confirm;
}
