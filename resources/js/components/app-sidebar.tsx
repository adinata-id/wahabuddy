import { Link } from '@inertiajs/react';
import {
    BookOpen,
    Images,
    LayoutGrid,
    Send,
    Settings as SettingsIcon,
    Users,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
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
import broadcasts from '@/routes/broadcasts';
import contacts from '@/routes/contacts';
import { dashboard } from '@/routes';
import settings from '@/routes/settings';
import stories from '@/routes/stories';
import type { NavItem } from '@/types';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Stories',
        href: stories.index(),
        icon: Images,
    },
    {
        title: 'Broadcasts',
        href: broadcasts.index(),
        icon: Send,
    },
    {
        title: 'Contacts',
        href: contacts.index(),
        icon: Users,
    },
    {
        title: 'Settings',
        href: settings.index(),
        icon: SettingsIcon,
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'WAHA Documentation',
        href: 'https://waha.devlike.pro/docs/',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
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
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
