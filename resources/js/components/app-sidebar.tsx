import { Link } from '@inertiajs/react';
import {
    FileText,
    Heart,
    Newspaper,
    Palette,
    Ticket,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { feed } from '@/routes';
import { index as creatorPlans } from '@/routes/creator/plans';
import { index as creatorPosts } from '@/routes/creator/posts';
import { index as creatorSupporters } from '@/routes/creator/supporters';
import { index as supporting } from '@/routes/supporting';
import { edit as creatorProfile } from '@/routes/creator/profile';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'フィード',
        href: feed(),
        icon: Newspaper,
    },
    {
        title: '支援中',
        href: supporting(),
        icon: Heart,
    },
    {
        title: 'クリエイター管理',
        href: creatorProfile(),
        icon: Palette,
    },
    {
        title: 'プラン管理',
        href: creatorPlans(),
        icon: Ticket,
    },
    {
        title: '投稿管理',
        href: creatorPosts(),
        icon: FileText,
    },
    {
        title: '支援者',
        href: creatorSupporters(),
        icon: Users,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={feed()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={mainNavItems} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
