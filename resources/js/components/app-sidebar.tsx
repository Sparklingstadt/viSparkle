import { Link, usePage } from '@inertiajs/react';
import { CreditCard, FileText, Heart, Ticket, Users } from 'lucide-react';
import BillingPortalController from '@/actions/App/Http/Controllers/Fan/BillingPortalController';
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
import { home } from '@/routes';
import { index as creatorPlans } from '@/routes/creator/plans';
import { index as creatorPosts } from '@/routes/creator/posts';
import { index as creatorSupporters } from '@/routes/creator/supporters';
import { index as supporting } from '@/routes/supporting';
import type { NavItem } from '@/types';

const fanNavItems: NavItem[] = [
    {
        title: '支援中',
        href: supporting(),
        icon: Heart,
    },
];

const creatorNavItems: NavItem[] = [
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

// Opens Stripe's customer portal, so only fans who have paid before get it.
const billingNavItem: NavItem = {
    title: 'お支払い方法・請求履歴',
    href: BillingPortalController(),
    icon: CreditCard,
};

export function AppSidebar() {
    const { auth } = usePage().props;
    const items = [
        ...fanNavItems,
        ...(auth.user?.has_billing_account ? [billingNavItem] : []),
        ...(auth.isCreator ? creatorNavItems : []),
    ];

    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={home()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
