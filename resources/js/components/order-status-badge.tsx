import { Badge } from '@/components/ui/badge';
import type { OrderStatusValue } from '@/types';

const variants: Record<OrderStatusValue, 'default' | 'secondary' | 'outline'> =
    {
        pending: 'outline',
        paid: 'default',
        shipped: 'secondary',
        canceled: 'outline',
        refunded: 'outline',
    };

export function OrderStatusBadge({
    status,
    label,
}: {
    status: OrderStatusValue;
    label: string;
}) {
    return <Badge variant={variants[status]}>{label}</Badge>;
}
