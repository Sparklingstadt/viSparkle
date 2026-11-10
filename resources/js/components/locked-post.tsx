import { Lock } from 'lucide-react';
import { formatYen } from '@/lib/format';

/** The panel that replaces a locked post's body: what it takes to read on, with the plans that unlock it as children. */
export function LockedPost({
    requiredPrice,
    supportPrice,
    creatorName,
    children,
}: {
    requiredPrice: number;
    supportPrice: number | null;
    creatorName: string;
    children?: React.ReactNode;
}) {
    return (
        <section className="space-y-5 rounded-3xl bg-secondary p-6 sm:p-8">
            <div className="flex flex-col items-center gap-2 text-center">
                <span className="flex size-12 items-center justify-center rounded-full bg-background text-primary">
                    <Lock className="size-5" aria-hidden />
                </span>
                <h2 className="mt-1 text-xl font-bold">
                    続きは支援すると読めます
                </h2>
                <p className="text-sm text-muted-foreground">
                    {creatorName}さんの月額 {formatYen(requiredPrice)}{' '}
                    以上のプランで読めます。
                </p>
                {supportPrice !== null && (
                    <p className="text-sm text-muted-foreground">
                        今のプラン（{formatYen(supportPrice)}
                        ）から変更すると、すぐに読めます。
                    </p>
                )}
            </div>
            {children}
            <p className="text-center text-xs text-muted-foreground">
                支援はいつでも停止できます。停止しても、支払い済みの期間が終わるまでは読めます。
            </p>
        </section>
    );
}
