import { Head, Link } from '@inertiajs/react';
import { CircleUserRound, KeyRound, Palette } from 'lucide-react';
import { CapabilitiesCard } from '@/components/settings/capabilities-card';
import { ConnectionCard } from '@/components/settings/connection-card';
import { PreferencesCard } from '@/components/settings/preferences-card';
import Heading from '@/components/heading';
import settings from '@/routes/settings';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type {
    AppPreferences,
    ConnectionSettings,
} from '@/types/wahabuddy';

export default function Settings({
    connection,
    preferences,
    timezones,
}: {
    connection: ConnectionSettings;
    preferences: AppPreferences;
    timezones: string[];
}) {
    return (
        <>
            <Head title="Settings" />
            <div className="flex h-full flex-1 flex-col gap-6">
                <Heading
                    title="Settings"
                    description="WAHA connection, capabilities, and operational preferences for this installation."
                />

                <ConnectionCard connection={connection} />
                <CapabilitiesCard capabilities={connection.capabilities} />
                <PreferencesCard
                    preferences={preferences}
                    timezones={timezones}
                />

                <nav className="flex flex-wrap gap-3 pb-6 text-sm text-muted-foreground">
                    <Link
                        href={editProfile()}
                        className="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 hover:bg-accent"
                    >
                        <CircleUserRound className="size-4" aria-hidden />
                        Account profile
                    </Link>
                    <Link
                        href={editSecurity()}
                        className="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 hover:bg-accent"
                    >
                        <KeyRound className="size-4" aria-hidden />
                        Security
                    </Link>
                    <Link
                        href={editAppearance()}
                        className="inline-flex items-center gap-1.5 rounded-lg border px-3 py-2 hover:bg-accent"
                    >
                        <Palette className="size-4" aria-hidden />
                        Appearance
                    </Link>
                </nav>
            </div>
        </>
    );
}

Settings.layout = {
    breadcrumbs: [
        {
            title: 'Settings',
            href: settings.index(),
        },
    ],
};
