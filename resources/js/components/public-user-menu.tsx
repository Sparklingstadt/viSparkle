import { Link, router, usePage } from '@inertiajs/react';
import { Heart, LogOut, Newspaper, ReceiptText } from 'lucide-react';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useInitials } from '@/hooks/use-initials';
import { feed, logout } from '@/routes';
import { index as orders } from '@/routes/orders';
import { index as supporting } from '@/routes/supporting';

const links = [
    { title: 'フィード', href: feed(), icon: Newspaper },
    { title: '支援中', href: supporting(), icon: Heart },
    { title: '注文履歴', href: orders(), icon: ReceiptText },
];

export function PublicUserMenu() {
    const { auth } = usePage().props;
    const getInitials = useInitials();

    return (
        <DropdownMenu>
            <DropdownMenuTrigger
                aria-label={`マイページのメニュー（${auth.user.name}）`}
                className="flex min-w-0 items-center gap-2 rounded-md px-1.5 py-1 transition-colors hover:bg-muted"
            >
                <Avatar className="size-7">
                    <AvatarImage
                        src={
                            auth.user.avatar ??
                            auth.creator?.avatar_url ??
                            undefined
                        }
                        alt=""
                    />
                    <AvatarFallback className="text-xs">
                        {getInitials(auth.user.name)}
                    </AvatarFallback>
                </Avatar>
                <span className="max-w-32 truncate text-sm font-medium">
                    {auth.user.name}
                </span>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end" className="w-44">
                {links.map(({ title, href, icon: Icon }) => (
                    <DropdownMenuItem key={title} asChild>
                        <Link
                            href={href}
                            prefetch
                            className="w-full cursor-pointer"
                        >
                            <Icon />
                            {title}
                        </Link>
                    </DropdownMenuItem>
                ))}
                <DropdownMenuSeparator />
                <DropdownMenuItem asChild>
                    <Link
                        href={logout()}
                        as="button"
                        onClick={() => router.flushAll()}
                        className="w-full cursor-pointer"
                        data-test="logout-button"
                    >
                        <LogOut />
                        ログアウト
                    </Link>
                </DropdownMenuItem>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}
