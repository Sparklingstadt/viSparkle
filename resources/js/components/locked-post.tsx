import { Lock } from 'lucide-react';
import { formatYen } from '@/lib/format';

export function LockedPost({
    requiredPrice,
    supportPrice,
}: {
    requiredPrice: number;
    supportPrice: number | null;
}) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-xl border border-dashed p-8 text-center">
            <Lock className="size-8 text-muted-foreground" />
            <p className="font-medium">
                月額 {formatYen(requiredPrice)} 以上のプランで閲覧できます
            </p>
            {supportPrice !== null && (
                <p className="text-sm text-muted-foreground">
                    現在のプラン（{formatYen(supportPrice)}
                    ）からプランを変更すると閲覧できます。
                </p>
            )}
            <a href="#plans" className="text-sm underline underline-offset-4">
                プランを見る
            </a>
        </div>
    );
}
