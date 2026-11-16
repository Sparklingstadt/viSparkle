import { Lock } from 'lucide-react';
import { formatYen } from '@/lib/format';
import { Pill } from '@/components/pill';

/** Who can read a post: everyone, or supporters on a plan of at least `price`. */
export function AccessBadge({
    price,
    className,
}: {
    price: number;
    className?: string;
}) {
    return price === 0 ? (
        <Pill tone="success" className={className}>
            全体公開
        </Pill>
    ) : (
        <Pill tone="primary" className={className}>
            <Lock className="size-3" aria-hidden />
            {formatYen(price)} プラン以上
        </Pill>
    );
}
