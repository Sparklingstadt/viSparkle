import { toast } from 'sonner';

const FALLBACK = '処理に失敗しました。';

/**
 * An `onError` handler for a router visit: toasts the first message found under `keys`, or a generic one.
 */
export function toastErrors(...keys: string[]) {
    return (errors: Record<string, string>) => {
        toast.error(keys.map((key) => errors[key]).find(Boolean) ?? FALLBACK);
    };
}
