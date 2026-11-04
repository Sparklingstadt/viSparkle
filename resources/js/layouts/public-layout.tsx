import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { AppearanceMenu } from '@/components/appearance-menu';
import { PublicUserMenu } from '@/components/public-user-menu';
import { SiteFooter } from '@/components/site-footer';
import { SiteSearch } from '@/components/site-search';
import { Button } from '@/components/ui/button';
import { home, login, register } from '@/routes';

export default function PublicLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { auth } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-background">
            <header className="border-b">
                <div className="mx-auto flex max-w-5xl flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-2 sm:h-14 sm:flex-nowrap sm:py-0">
                    <Link href={home()} className="flex items-center gap-2">
                        <AppLogo />
                    </Link>
                    <SiteSearch className="order-last w-full sm:order-none sm:w-auto sm:max-w-md sm:flex-1" />
                    <nav className="flex items-center gap-2">
                        <AppearanceMenu />
                        {auth.user ? (
                            <PublicUserMenu />
                        ) : (
                            <>
                                <Button asChild variant="ghost" size="sm">
                                    <Link href={login()}>ログイン</Link>
                                </Button>
                                <Button asChild size="sm">
                                    <Link href={register()}>新規登録</Link>
                                </Button>
                            </>
                        )}
                    </nav>
                </div>
            </header>
            <main className="mx-auto w-full max-w-5xl flex-1 px-4 py-6">
                {children}
            </main>
            <SiteFooter className="mx-auto w-full max-w-5xl" />
        </div>
    );
}
