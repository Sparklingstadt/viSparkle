import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import type { CategorySummary } from '@/types';

export function CategoryLinks({
    categories,
    current = null,
    href,
    allLabel,
}: {
    categories: CategorySummary[];
    current?: string | null;
    href: (slug: string | null) => string;
    allLabel?: string;
}) {
    const items = [
        ...(allLabel ? [{ slug: null, name: allLabel }] : []),
        ...categories,
    ];

    return (
        <nav aria-label="カテゴリ" className="flex flex-wrap gap-2">
            {items.map((item) => {
                const selected = item.slug === current;

                return (
                    <Link
                        key={item.slug ?? 'all'}
                        href={href(item.slug)}
                        aria-current={selected ? 'page' : undefined}
                        className={cn(
                            'rounded-full border px-3 py-1 text-sm transition-colors',
                            selected
                                ? 'border-foreground bg-foreground font-medium text-background'
                                : 'hover:border-foreground/30 hover:bg-muted/70',
                        )}
                    >
                        {item.name}
                    </Link>
                );
            })}
        </nav>
    );
}
