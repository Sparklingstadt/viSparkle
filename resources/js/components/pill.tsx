import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

/** The colour pairs of the status pills, also used by other tinted marks (the to-do icons on the earnings page). */
export const toneClass = {
    success:
        'bg-emerald-50 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300',
    warn: 'bg-amber-50 text-amber-800 dark:bg-amber-950 dark:text-amber-300',
    primary: 'bg-secondary text-primary',
    muted: 'bg-muted text-muted-foreground',
} as const;

export type Tone = keyof typeof toneClass;

/** A small rounded status label. */
export function Pill({
    tone,
    className,
    children,
}: {
    tone: Tone;
    className?: string;
    children: ReactNode;
}) {
    return (
        <span
            className={cn(
                'inline-flex h-6 items-center gap-1 rounded-full px-2.5 text-xs font-bold',
                toneClass[tone],
                className,
            )}
        >
            {children}
        </span>
    );
}
