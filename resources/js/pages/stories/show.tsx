import { useState } from 'react';
import { Head, Link, useForm } from '@inertiajs/react';
import { Pencil, RotateCcw, Trash, Trash2, XCircle } from 'lucide-react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/confirm-dialog';
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
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { cancel as cancelRoute } from '@/routes/stories';
import { destroy as destroyRoute } from '@/routes/stories';
import { edit as editRoute } from '@/routes/stories';
import storiesRoutes from '@/routes/stories';
import {
    deleteRemote as deleteRemoteRoute,
    resolve as resolveRoute,
    retry as retryRoute,
} from '@/routes/stories';
import type { Pagination, StoryDetail, StoryLogItem } from '@/types/wahabuddy';

export default function StoriesShow({
    story,
    logs,
}: {
    story: StoryDetail;
    logs: Pagination<StoryLogItem>;
}) {
    // Error bags from the server use the "story" key; type the forms so
    // errors.story is visible to the confirmation dialog.
    const deleteForm = useForm<Record<string, string>>({});
    const actionForm = useForm<Record<string, string>>({});
    const [confirming, setConfirming] = useState<
        'cancel' | 'delete_remote' | 'delete_local' | null
    >(null);
    const [resolveOpen, setResolveOpen] = useState(false);
    const resolveForm = useForm<{
        decision: 'mark_sent' | 'retry';
        note: string;
    }>({
        decision: 'mark_sent',
        note: '',
    });

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
                    <div className="mb-8 flex flex-wrap gap-2">
                        {story.editable ? (
                            <Button asChild variant="secondary">
                                <Link href={editRoute({ story: story.id })}>
                                    <Pencil className="size-4" /> Edit
                                </Link>
                            </Button>
                        ) : null}

                        {[
                            'failed',
                            'failed_max',
                            'expired',
                            'canceled',
                        ].includes(story.status) ? (
                            <Button
                                variant="secondary"
                                disabled={actionForm.processing}
                                onClick={() =>
                                    actionForm.post(
                                        retryRoute.url({ story: story.id }),
                                        {
                                            onSuccess: () =>
                                                toast.success(
                                                    'New retry cycle opened.',
                                                ),
                                        },
                                    )
                                }
                            >
                                <RotateCcw className="size-4" /> Retry now
                            </Button>
                        ) : null}

                        {[
                            'pending',
                            'failed',
                            'failed_max',
                            'expired',
                        ].includes(story.status) ? (
                            <Button
                                variant="ghost"
                                disabled={actionForm.processing}
                                onClick={() => setConfirming('cancel')}
                            >
                                <XCircle className="size-4" /> Cancel
                            </Button>
                        ) : null}

                        {story.status === 'unknown' ? (
                            <Button
                                variant="secondary"
                                onClick={() => setResolveOpen((open) => !open)}
                            >
                                Resolve unknown…
                            </Button>
                        ) : null}

                        {story.status === 'sent' && story.waha_key_id ? (
                            <Button
                                variant="ghost"
                                disabled={actionForm.processing}
                                onClick={() => setConfirming('delete_remote')}
                            >
                                <Trash className="size-4" /> Delete on WhatsApp
                            </Button>
                        ) : null}

                        {story.status !== 'sending' &&
                        story.status !== 'unknown' ? (
                            <Button
                                variant="ghost"
                                className="text-destructive"
                                disabled={deleteForm.processing}
                                onClick={() => setConfirming('delete_local')}
                            >
                                {deleteForm.processing ? (
                                    <Spinner />
                                ) : (
                                    <Trash2 className="size-4" />
                                )}
                                Delete record
                            </Button>
                        ) : null}
                    </div>
                </div>

                {resolveOpen && story.status === 'unknown' ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>Resolve unknown outcome</CardTitle>
                            <CardDescription>
                                A timeout or lost worker means WAHA may already
                                have posted this status. Marking it sent never
                                invents a WAHA id; retrying acknowledges a
                                possible duplicate. A note is mandatory and
                                stored in the audit log.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex flex-wrap gap-2">
                                {(['mark_sent', 'retry'] as const).map(
                                    (option) => (
                                        <Button
                                            key={option}
                                            type="button"
                                            variant={
                                                resolveForm.data.decision ===
                                                option
                                                    ? 'default'
                                                    : 'outline'
                                            }
                                            size="sm"
                                            onClick={() =>
                                                resolveForm.setData(
                                                    'decision',
                                                    option,
                                                )
                                            }
                                        >
                                            {option === 'mark_sent'
                                                ? 'It was sent (mark sent)'
                                                : 'Resend (accept duplicate risk)'}
                                        </Button>
                                    ),
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="resolve-note">
                                    Note (required, kept in the audit log)
                                </Label>
                                <Textarea
                                    id="resolve-note"
                                    value={resolveForm.data.note}
                                    onChange={(event) =>
                                        resolveForm.setData(
                                            'note',
                                            event.target.value,
                                        )
                                    }
                                    rows={2}
                                    placeholder="e.g. Verified on the phone that the status was posted 09:02."
                                />
                            </div>
                            <Button
                                disabled={
                                    resolveForm.processing ||
                                    resolveForm.data.note.trim().length < 3
                                }
                                onClick={() =>
                                    resolveForm.post(
                                        resolveRoute.url({ story: story.id }),
                                        {
                                            onSuccess: () => {
                                                setResolveOpen(false);
                                                resolveForm.reset();
                                                toast.success(
                                                    'Outcome resolved and audited.',
                                                );
                                            },
                                        },
                                    )
                                }
                            >
                                {resolveForm.processing && <Spinner />}
                                Confirm resolution
                            </Button>
                        </CardContent>
                    </Card>
                ) : null}

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
                                        backgroundColor: story.background_color,
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
                                    <dt className="inline">
                                        Target revision:{' '}
                                    </dt>
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
                                        <strong>
                                            {story.last_error_code}:{' '}
                                        </strong>
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
                                        <TableCell className="text-xs whitespace-nowrap">
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

                <ConfirmDialog
                    open={confirming !== null}
                    onOpenChange={(open) => {
                        if (!open) setConfirming(null);
                    }}
                    title={
                        confirming === 'cancel'
                            ? 'Cancel this story?'
                            : confirming === 'delete_remote'
                              ? 'Delete this status on WhatsApp?'
                              : 'Delete this story record?'
                    }
                    description={
                        confirming === 'cancel'
                            ? 'No further send attempts will be made.'
                            : confirming === 'delete_remote'
                              ? 'WAHA is asked to delete the status from the WhatsApp session. This cannot be undone.'
                              : 'Scheduled sends stop. The status on WhatsApp is not touched — remote deletion is a separate action.'
                    }
                    confirmLabel={
                        confirming === 'cancel'
                            ? 'Cancel story'
                            : confirming === 'delete_remote'
                              ? 'Delete on WhatsApp'
                              : 'Delete record'
                    }
                    destructive={
                        confirming === 'delete_remote' ||
                        confirming === 'delete_local'
                    }
                    processing={actionForm.processing || deleteForm.processing}
                    error={
                        (confirming === 'delete_local'
                            ? deleteForm.errors.story
                            : actionForm.errors.story) ?? null
                    }
                    onConfirm={() => {
                        if (confirming === 'cancel') {
                            actionForm.post(
                                cancelRoute.url({ story: story.id }),
                                {
                                    onSuccess: () => {
                                        setConfirming(null);
                                        toast.success('Story canceled.');
                                    },
                                },
                            );
                        } else if (confirming === 'delete_remote') {
                            actionForm.post(
                                deleteRemoteRoute.url({ story: story.id }),
                                {
                                    onSuccess: () => {
                                        setConfirming(null);
                                        toast.success(
                                            'Remote deletion accepted.',
                                        );
                                    },
                                },
                            );
                        } else if (confirming === 'delete_local') {
                            deleteForm.delete(
                                destroyRoute.url({ story: story.id }),
                                {
                                    onSuccess: () => {
                                        setConfirming(null);
                                        toast.success('Story deleted.');
                                    },
                                },
                            );
                        }
                    }}
                />
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
