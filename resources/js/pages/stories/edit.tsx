import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { StoryForm } from '@/components/stories/story-form';
import stories from '@/routes/stories';
import type { MediaFileItem, StoryDetail } from '@/types/wahabuddy';

export default function StoriesEdit({
    story,
    recent_media: recentMedia,
    display_timezone: displayTimezone,
}: {
    story: StoryDetail;
    recent_media: MediaFileItem[];
    display_timezone: string;
}) {
    return (
        <>
            <Head title={`Edit story #${story.id}`} />
            <div className="flex h-full flex-1 flex-col gap-4">
                <Heading
                    title="Edit story"
                    description="Editing is only possible while the story is pending or failed and has never been sent."
                />
                <StoryForm
                    story={story}
                    recentMedia={recentMedia}
                    displayTimezone={displayTimezone}
                />
            </div>
        </>
    );
}

StoriesEdit.layout = {
    breadcrumbs: [
        { title: 'Stories', href: stories.index() },
        { title: 'Edit' },
    ],
};
