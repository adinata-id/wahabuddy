import { Head } from '@inertiajs/react';
import { PlugZap } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import settings from '@/routes/settings';

export default function Settings() {
    return (
        <>
            <Head title="Settings" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <EmptyState
                    icon={PlugZap}
                    title="WAHA connection not configured yet"
                    description="Connect your self-hosted WAHA instance, pick a session, and configure timezone, pacing, and storage on this page."
                />
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
