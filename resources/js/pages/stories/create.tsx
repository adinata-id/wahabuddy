import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { StoryForm } from '@/components/stories/story-form';
import stories from '@/routes/stories';
import type { MediaFileItem } from '@/types/wahabuddy';

export default function StoriesCreate({
    recent_media: recentMedia,
    display_timezone: displayTimezone,
}: {
    recent_media: MediaFileItem[];
    display_timezone: string;
}) {
    return (
        <>
            <Head title="Schedule story" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <Heading
                    title="Schedule a story"
                    description="Your status will be posted through the configured WAHA session at the scheduled time (display timezone)."
                />
                <StoryForm
                    recentMedia={recentMedia}
                    displayTimezone={displayTimezone}
                />
            </div>
        </>
    );
}

StoriesCreate.layout = {
    breadcrumbs: [
        { title: 'Stories', href: stories.index() },
        { title: 'Schedule' },
    ],
};
