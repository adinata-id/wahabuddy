import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, Trash2 } from 'lucide-react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { destroy as destroyRoute } from '@/routes/stories';
import { edit as editRoute } from '@/routes/stories';
import storiesRoutes from '@/routes/stories';
import type { Pagination, StoryDetail, StoryLogItem } from '@/types/wahabuddy';

export default function StoriesShow({
    story,
    logs,
}: {
    story: StoryDetail;
    logs: Pagination<StoryLogItem>;
}) {
    const deleteForm = useForm({});

    return (
        <>
            <Head title={`Story #${story.id}`} />
            <div className="flex h-full flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="mb-8 space-y-0.5">
                        <div className="flex items-center gap-3">
                            <h2 className="text-xl font-semibold tracking-tight">
                                Story #{story.id}
                            </h2>
                            <StatusBadge status={story.status} />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {story.type} status · {story.attempts} send
                            attempt(s)
                        </p>
                    </div>
                    <div className="mb-8 flex gap-2">
                        {story.editable ? (
                            <Button asChild variant="secondary">
                                <Link href={editRoute({ story: story.id })}>
                                    <Pencil className="size-4" /> Edit
                                </Link>
                            </Button>
                        ) : null}
                        {story.status !== 'sending' &&
                        story.status !== 'unknown' ? (
                            <Button
                                variant="ghost"
                                className="text-destructive"
                                disabled={deleteForm.processing}
                                onClick={() => {
                                    if (
                                        !window.confirm(
                                            'Delete this local story record? Scheduled sends will stop. Remote deletion on WhatsApp is a separate action.',
                                        )
                                    ) {
                                        return;
                                    }
                                    deleteForm.delete(
                                        destroyRoute.url({ story: story.id }),
                                        {
                                            onSuccess: () =>
                                                toast.success(
                                                    'Story deleted.',
                                                ),
                                        },
                                    );
                                }}
                            >
                                {deleteForm.processing ? (
                                    <Spinner />
                                ) : (
                                    <Trash2 className="size-4" />
                                )}
                                Delete
                            </Button>
                        ) : null}
                    </div>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Content</CardTitle>
                            <CardDescription>
                                Scheduled for {story.scheduled_at_display}{' '}
                                {story.display_timezone}
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {story.type === 'text' ? (
                                <div
                                    className="flex min-h-24 items-center justify-center rounded-lg p-4 text-center font-medium text-white"
                                    style={{
                                        backgroundColor:
                                            story.background_color,
                                    }}
                                >
                                    {story.text}
                                </div>
                            ) : story.media ? (
                                <div className="space-y-2">
                                    {story.media.mime_type.startsWith(
                                        'image/',
                                    ) ? (
                                        <img
                                            src={story.media.url}
                                            alt={story.media.original_name}
                                            className="max-h-64 rounded-lg border"
                                        />
                                    ) : (
                                        <video
                                            src={story.media.url}
                                            controls
                                            className="max-h-64 rounded-lg border"
                                        />
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {story.media.original_name} ·{' '}
                                        {story.media.mime_type}
                                        {story.media.video_codec
                                            ? ` · ${story.media.video_codec}`
                                            : ''}
                                    </p>
                                </div>
                            ) : null}

                            {story.type !== 'text' && story.text ? (
                                <p className="text-sm">{story.text}</p>
                            ) : null}

                            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 border-t pt-3 text-xs text-muted-foreground">
                                <div>
                                    <dt className="inline">Sent at: </dt>
                                    <dd className="inline">
                                        {story.sent_at_display ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="inline">Target revision: </dt>
                                    <dd className="inline">
                                        {story.connection_revision}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="inline">Message id: </dt>
                                    <dd className="inline font-mono">
                                        {story.waha_message_id ?? '—'}
                                    </dd>
                                </div>
                                <div>
                                    <dt className="inline">Key id: </dt>
                                    <dd className="inline font-mono">
                                        {story.waha_key_id ?? '—'}
                                    </dd>
                                </div>
                            </dl>

                            {story.last_error ? (
                                <p className="rounded-lg bg-red-500/10 p-3 text-xs text-red-700 dark:text-red-400">
                                    {story.last_error_code ? (
                                        <strong>{story.last_error_code}: </strong>
                                    ) : null}
                                    {story.last_error}
                                </p>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Card className="pb-2">
                        <CardHeader>
                            <CardTitle>History</CardTitle>
                            <CardDescription>
                                Append-only event log ({logs.total} entries)
                            </CardDescription>
                        </CardHeader>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>When</TableHead>
                                    <TableHead>Event</TableHead>
                                    <TableHead>Detail</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {logs.data.map((log) => (
                                    <TableRow key={log.id}>
                                        <TableCell className="whitespace-nowrap text-xs">
                                            {log.created_at_display}
                                        </TableCell>
                                        <TableCell>
                                            <span className="font-mono text-xs">
                                                {log.event}
                                            </span>
                                        </TableCell>
                                        <TableCell className="max-w-64 text-xs">
                                            {log.message ?? '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </Card>
                </div>
            </div>
        </>
    );
}

StoriesShow.layout = {
    breadcrumbs: [
        { title: 'Stories', href: storiesRoutes.index() },
        { title: 'Detail' },
    ],
};
