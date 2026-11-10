import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { formatYen } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { PlanData } from '@/types';

export function PlanCard({
    plan,
    action,
    footer,
    featured = false,
    featuredLabel = 'いちばん多くの投稿が読めます',
}: {
    plan: PlanData;
    action?: ReactNode;
    footer?: ReactNode;
    /** Draws the plan as the one to pick, labelled with `featuredLabel`. */
    featured?: boolean;
    featuredLabel?: string;
}) {
    return (
        <div
            className={cn(
                'flex flex-col overflow-hidden rounded-2xl bg-card',
                featured ? 'border-2 border-primary' : 'border',
            )}
        >
            {plan.cover_url && (
                <img
                    src={plan.cover_url}
                    alt=""
                    className="aspect-[2/1] w-full object-cover"
                />
            )}
            <div className="flex flex-1 flex-col gap-2 p-5">
                {featured && (
                    <span className="self-start rounded-full bg-primary px-2.5 py-0.5 text-xs font-bold text-primary-foreground">
                        {featuredLabel}
                    </span>
                )}
                <div className="flex items-start justify-between gap-2">
                    <h3 className="font-bold">{plan.name}</h3>
                    {!plan.is_accepting && (
                        <Badge variant="secondary">受付停止中</Badge>
                    )}
                </div>
                <p className="text-2xl font-bold">
                    {formatYen(plan.price)}
                    <span className="text-sm font-normal text-muted-foreground">
                        {' '}
                        税込 / 月
                    </span>
                </p>
                {plan.description && (
                    <p className="text-sm leading-relaxed whitespace-pre-wrap text-muted-foreground">
                        {plan.description}
                    </p>
                )}
                {action && <div className="mt-auto pt-2">{action}</div>}
                {footer}
            </div>
        </div>
    );
}
