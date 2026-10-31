import { Head } from '@inertiajs/react';
import { Users } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import contactsRoutes from '@/routes/contacts';
import type { Pagination } from '@/types/wahabuddy';

export default function Contacts({
    contacts,
}: {
    contacts: Pagination<{
        id: number;
        chat_id: string;
        name: string | null;
        type: string;
        opted_in: boolean;
        opted_in_at: string | null;
        opted_out_at: string | null;
    }>;
}) {
    return (
        <>
            <Head title="Contacts" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <EmptyState
                    icon={Users}
                    title={contacts.total === 0 ? 'No contacts synced yet' : 'Contacts'}
                    description={
                        contacts.total === 0
                            ? 'Sync contacts and groups from your connected WAHA session, then manage recipient consent before broadcasting.'
                            : `${contacts.total} entries synced.`
                    }
                />
            </div>
        </>
    );
}

Contacts.layout = {
    breadcrumbs: [
        {
            title: 'Contacts',
            href: contactsRoutes.index(),
        },
    ],
};
