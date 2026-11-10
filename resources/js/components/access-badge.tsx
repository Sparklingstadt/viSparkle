import { Lock } from 'lucide-react';
import { formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';

/** Who can read a post: everyone, or supporters on a plan of at least `price`. */
export function AccessBadge({
    price,
    className,
}: {
    price: number;
    className?: string;
}) {
    return price === 0 ? (
        <span
            className={cn(
                'inline-flex h-6 items-center rounded-full bg-emerald-50 px-2.5 text-xs font-bold text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
                className,
            )}
        >
            全体公開
        </span>
    ) : (
        <span
            className={cn(
                'inline-flex h-6 items-center gap-1 rounded-full bg-secondary px-2.5 text-xs font-bold text-primary',
                className,
            )}
        >
            <Lock className="size-3" aria-hidden />
            {formatYen(price)} プラン以上
        </span>
    );
}
