import { Head } from '@inertiajs/react';
import { Images } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import stories from '@/routes/stories';

export default function Stories() {
    return (
        <>
            <Head title="Stories" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <EmptyState
                    icon={Images}
                    title="No stories scheduled yet"
                    description="Scheduled WhatsApp status updates you create will appear here with their send status and history."
                />
            </div>
        </>
    );
}

Stories.layout = {
    breadcrumbs: [
        {
            title: 'Stories',
            href: stories.index(),
        },
    ],
};
