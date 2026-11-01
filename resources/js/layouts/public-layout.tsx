import { Link, usePage } from '@inertiajs/react';
import AppLogo from '@/components/app-logo';
import { AppearanceMenu } from '@/components/appearance-menu';
import { SiteFooter } from '@/components/site-footer';
import { Button } from '@/components/ui/button';
import { feed, home, login, register } from '@/routes';

export default function PublicLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const { auth } = usePage().props;

    return (
        <div className="flex min-h-screen flex-col bg-background">
            <header className="border-b">
                <div className="mx-auto flex h-14 max-w-5xl items-center justify-between px-4">
                    <Link href={home()} className="flex items-center gap-2">
                        <AppLogo />
                    </Link>
                    <nav className="flex items-center gap-2">
                        <AppearanceMenu />
                        {auth.user ? (
                            <Button asChild variant="outline" size="sm">
                                <Link href={feed()}>マイページ</Link>
                            </Button>
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
