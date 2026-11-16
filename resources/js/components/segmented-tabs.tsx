import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export type TabLink = {
    key: string;
    label: ReactNode;
    href: string;
    current: boolean;
    /** A number shown after the label (e.g. how many items the tab lists). */
    count?: number;
};

/**
 * Rounded tabs that switch a list in place, keeping the scroll position. `only` limits the reload to those props.
 */
export function SegmentedTabs({
    label,
    tabs,
    only,
    className,
}: {
    label: string;
    tabs: TabLink[];
    only?: string[];
    className?: string;
}) {
    return (
        <nav
            aria-label={label}
            className={cn(
                'flex gap-1 self-start rounded-full bg-muted p-1',
                className,
            )}
        >
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={tab.current ? 'page' : undefined}
                    preserveScroll
                    only={only}
                    className={cn(
                        'rounded-full px-4 py-1.5 text-sm font-bold transition-colors',
                        tab.current
                            ? 'bg-background shadow-xs'
                            : 'text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                    {tab.count !== undefined && (
                        <>
                            {' '}
                            <span className="font-normal">{tab.count}</span>
                        </>
                    )}
                </Link>
            ))}
        </nav>
    );
}
