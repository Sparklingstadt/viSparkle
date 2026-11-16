import type { ReactNode } from 'react';

/** The facts under the buy button (delivery, files, shipping, stock), as label and value rows. */
export function SpecList({ items }: { items: [string, ReactNode][] }) {
    return (
        <dl className="grid grid-cols-[auto_1fr] gap-x-5 gap-y-2 rounded-2xl bg-secondary px-5 py-4 text-sm">
            {items.map(([label, value]) => (
                <div key={label} className="contents">
                    <dt className="text-muted-foreground">{label}</dt>
                    <dd>{value}</dd>
                </div>
            ))}
        </dl>
    );
}
