import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type SegmentedTab = {
    key: string;
    label: ReactNode;
    href: string;
    current: boolean;
};

/**
 * Rounded tabs that switch a list in place: each tab reloads only the props in `only`, keeping the scroll position.
 */
export function SegmentedTabs({
    tabs,
    only,
}: {
    tabs: SegmentedTab[];
    only: string[];
}) {
    return (
        <div className="flex gap-1 rounded-full bg-muted p-1" role="tablist">
            {tabs.map((tab) => (
                <Button
                    key={tab.key}
                    size="sm"
                    variant="ghost"
                    asChild
                    className={cn(
                        'text-muted-foreground',
                        tab.current &&
                            'bg-background text-foreground shadow-xs hover:bg-background',
                    )}
                >
                    <Link
                        href={tab.href}
                        role="tab"
                        aria-selected={tab.current}
                        preserveScroll
                        only={only}
                    >
                        {tab.label}
                    </Link>
                </Button>
            ))}
        </div>
    );
}
