import { Head } from '@inertiajs/react';
import { Send } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import broadcasts from '@/routes/broadcasts';

export default function Broadcasts() {
    return (
        <>
            <Head title="Broadcasts" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <EmptyState
                    icon={Send}
                    title="No broadcasts yet"
                    description="Create a broadcast to send a personalized text or image message to your opted-in contacts and groups."
                />
            </div>
        </>
    );
}

Broadcasts.layout = {
    breadcrumbs: [
        {
            title: 'Broadcasts',
            href: broadcasts.index(),
        },
    ],
};
