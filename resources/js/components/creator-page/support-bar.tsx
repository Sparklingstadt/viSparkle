import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import type { PlanData } from '@/types';

/** On phones, a bar fixed to the bottom that leads to the plans: how many there are and the cheapest price. */
export function SupportBar({
    planCount,
    cheapest,
}: {
    planCount: number;
    cheapest: PlanData;
}) {
    return (
        <div className="fixed inset-x-0 bottom-0 z-40 border-t bg-background/95 px-4 pt-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] backdrop-blur lg:hidden">
            <div className="mx-auto flex max-w-5xl items-center gap-3">
                <div className="min-w-0 flex-1">
                    <p className="text-xs text-muted-foreground">
                        支援プラン {planCount} 件
                    </p>
                    <p className="text-lg font-bold">
                        {formatYen(cheapest.price)}
                        <span className="text-xs font-normal text-muted-foreground">
                            {' '}
                            / 月から
                        </span>
                    </p>
                </div>
                <Button asChild size="lg">
                    <a href="#plans">支援する</a>
                </Button>
            </div>
        </div>
    );
}
