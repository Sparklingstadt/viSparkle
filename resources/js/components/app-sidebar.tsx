import { Link, usePage } from '@inertiajs/react';
import {
    CreditCard,
    FileText,
    Heart,
    Newspaper,
    Package,
    Palette,
    Shield,
    Ticket,
    Users,
    Wallet,
} from 'lucide-react';
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
import { dashboard as adminDashboard } from '@/routes/admin';
import { feed } from '@/routes';
import { index as creatorEarnings } from '@/routes/creator/earnings';
import { index as creatorPlans } from '@/routes/creator/plans';
import { index as creatorPosts } from '@/routes/creator/posts';
import { index as creatorProducts } from '@/routes/creator/products';
import { index as creatorSupporters } from '@/routes/creator/supporters';
import { index as supporting } from '@/routes/supporting';
import { edit as creatorProfile } from '@/routes/creator/profile';
import type { NavItem } from '@/types';

const fanNavItems: NavItem[] = [
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
];

const creatorNavItems: NavItem[] = [
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
        title: '商品管理',
        href: creatorProducts(),
        icon: Package,
    },
    {
        title: '支援者',
        href: creatorSupporters(),
        icon: Users,
    },
    {
        title: '収益',
        href: creatorEarnings(),
        icon: Wallet,
    },
];

// Opens Stripe's customer portal, so only fans who have paid before get it.
const billingNavItem: NavItem = {
    title: 'お支払い方法・請求履歴',
    href: BillingPortalController(),
    icon: CreditCard,
};

const adminNavItem: NavItem = {
    title: '管理',
    href: adminDashboard(),
    icon: Shield,
};

export function AppSidebar() {
    const { auth } = usePage().props;
    const items = [
        ...fanNavItems,
        ...(auth.user?.has_billing_account ? [billingNavItem] : []),
        ...creatorNavItems,
        ...(auth.user?.is_admin ? [adminNavItem] : []),
    ];

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
                <NavMain items={items} />
            </SidebarContent>

            <SidebarFooter>
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
