import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { formatYen } from '@/lib/format';
import type { PlanData } from '@/types';

export function PlanCard({
    plan,
    action,
    footer,
}: {
    plan: PlanData;
    action?: ReactNode;
    footer?: ReactNode;
}) {
    return (
        <div className="flex flex-col overflow-hidden rounded-xl border bg-card">
            {plan.cover_url && (
                <img
                    src={plan.cover_url}
                    alt=""
                    className="aspect-[2/1] w-full object-cover"
                />
            )}
            <div className="flex flex-1 flex-col gap-2 p-4">
                <div className="flex items-start justify-between gap-2">
                    <h3 className="font-semibold">{plan.name}</h3>
                    {!plan.is_accepting && (
                        <Badge variant="secondary">受付停止中</Badge>
                    )}
                </div>
                <p className="text-lg font-bold">
                    {formatYen(plan.price)}
                    <span className="text-sm font-normal text-muted-foreground">
                        {' '}
                        税込 / 月
                    </span>
                </p>
                {plan.description && (
                    <p className="text-sm whitespace-pre-wrap text-muted-foreground">
                        {plan.description}
                    </p>
                )}
                {action && <div className="mt-auto pt-2">{action}</div>}
                {footer}
            </div>
        </div>
    );
}
