import { Link } from '@inertiajs/react';
import type { TabLink } from '@/components/segmented-tabs';
import { cn } from '@/lib/utils';

/**
 * Tabs on a line under a page's heading, for switching between the page's sections or lists.
 */
export function UnderlineTabs({
    label,
    tabs,
}: {
    label: string;
    tabs: TabLink[];
}) {
    return (
        <nav aria-label={label} className="flex flex-wrap gap-1 border-b">
            {tabs.map((tab) => (
                <Link
                    key={tab.key}
                    href={tab.href}
                    aria-current={tab.current ? 'page' : undefined}
                    preserveScroll
                    className={cn(
                        '-mb-px flex items-center gap-2 border-b-[3px] px-3 py-2.5 text-sm font-bold',
                        tab.current
                            ? 'border-primary'
                            : 'border-transparent text-muted-foreground hover:text-foreground',
                    )}
                >
                    {tab.label}
                    {tab.count !== undefined && (
                        <span
                            className={cn(
                                'min-w-6 rounded-full px-1.5 text-center text-xs',
                                tab.current
                                    ? 'bg-primary text-primary-foreground'
                                    : 'bg-muted',
                            )}
                        >
                            {tab.count}
                        </span>
                    )}
                </Link>
            ))}
        </nav>
    );
}
