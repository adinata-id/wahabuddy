import { Head } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import contacts from '@/routes/contacts';

export default function Contacts() {
    return (
        <>
            <Head title="Contacts" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <EmptyState
                    icon={Users}
                    title="No contacts synced yet"
                    description="Sync contacts and groups from your connected WAHA session, then manage recipient consent before broadcasting."
                />
            </div>
        </>
    );
}

Contacts.layout = {
    breadcrumbs: [
        {
            title: 'Contacts',
            href: contacts.index(),
        },
    ],
};
