import { Head, Link } from '@inertiajs/react';
import { MessageCircleMore } from 'lucide-react';
import AppLogoIcon from '@/components/app-logo-icon';
import { dashboard, login } from '@/routes';
import type { Auth } from '@/types';

export default function Welcome({ auth }: { auth: { user: Auth['user'] } }) {
    return (
        <>
            <Head title="Welcome" />
            <div className="flex min-h-svh flex-col items-center justify-center gap-6 bg-background p-6 text-foreground">
                <header className="flex w-full max-w-md items-center justify-end">
                    {auth.user ? (
                        <Link
                            href={dashboard()}
                            className="inline-block rounded-sm border border-border px-5 py-1.5 text-sm leading-normal hover:bg-accent"
                        >
                            Dashboard
                        </Link>
                    ) : (
                        <Link
                            href={login()}
                            className="inline-block rounded-sm border border-transparent px-5 py-1.5 text-sm leading-normal hover:border-border"
                        >
                            Log in
                        </Link>
                    )}
                </header>

                <main className="flex w-full max-w-md flex-col items-center gap-4 rounded-xl border bg-card p-8 text-center shadow-sm">
                    <AppLogoIcon className="size-14 fill-current text-sidebar-primary" />
                    <h1 className="text-2xl font-semibold tracking-tight">
                        WahaBuddy
                    </h1>
                    <p className="text-sm text-muted-foreground">
                        Self-hosted panel for scheduling WhatsApp Status updates
                        and running paced broadcasts through your own WAHA
                        instance.
                    </p>
                    <div className="mt-2 flex items-center gap-2 rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground">
                        <MessageCircleMore className="size-4" aria-hidden />
                        <span>
                            Sign in with the administrator account to continue.
                        </span>
                    </div>
                </main>
            </div>
        </>
    );
}
