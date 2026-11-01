import { Link } from '@inertiajs/react';
import { cn } from '@/lib/utils';
import { privacy, terms, tokushoho } from '@/routes/legal';

export function SiteFooter({ className }: { className?: string }) {
    return (
        <footer
            className={cn(
                'flex flex-wrap items-center gap-x-4 gap-y-1 border-t px-4 py-4 text-xs text-muted-foreground',
                className,
            )}
        >
            <Link href={tokushoho()} className="hover:underline">
                特定商取引法に基づく表記
            </Link>
            <Link href={terms()} className="hover:underline">
                利用規約
            </Link>
            <Link href={privacy()} className="hover:underline">
                プライバシーポリシー
            </Link>
            <span className="ml-auto">
                © {new Date().getFullYear()} viSparkle
            </span>
        </footer>
    );
}
