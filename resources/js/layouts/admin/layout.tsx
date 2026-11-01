import { Link } from '@inertiajs/react';
import type { PropsWithChildren } from 'react';
import { Button } from '@/components/ui/button';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { cn, toUrl } from '@/lib/utils';
import { dashboard } from '@/routes/admin';
import { index as reports } from '@/routes/admin/reports';
import type { NavItem } from '@/types';

// Add new admin sections here; every page under pages/admin/ gets this nav.
const adminNavItems: (NavItem & { exact?: boolean })[] = [
    { title: 'ダッシュボード', href: dashboard(), exact: true },
    { title: '通報', href: reports() },
];

export default function AdminLayout({ children }: PropsWithChildren) {
    const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();

    return (
        <div className="space-y-4 p-4">
            <nav className="flex flex-wrap gap-1" aria-label="管理メニュー">
                {adminNavItems.map((item) => {
                    const active = item.exact
                        ? isCurrentUrl(item.href)
                        : isCurrentOrParentUrl(item.href);

                    return (
                        <Button
                            key={toUrl(item.href)}
                            size="sm"
                            variant="ghost"
                            asChild
                            className={cn({ 'bg-muted': active })}
                        >
                            <Link href={item.href}>{item.title}</Link>
                        </Button>
                    );
                })}
            </nav>

            {children}
        </div>
    );
}
