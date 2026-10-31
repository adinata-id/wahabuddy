import { useEffect, useMemo, useState } from 'react';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { Pause, Play, Users, XCircle } from 'lucide-react';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
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
import broadcastsRoutes from '@/routes/broadcasts';
import type { MediaFileItem, Pagination } from '@/types/wahabuddy';

type BroadcastDetail = {
    id: number;
    name: string;
    type: string;
    template_text: string;
    status: string;
    editable: boolean;
    pausable: boolean;
    resumable: boolean;
    cancellable: boolean;
    counts: Record<string, number>;
    media: { url: string; original_name: string } | null;
    started_at_display: string | null;
    finished_at_display: string | null;
};

type RecipientRow = {
    id: number;
    chat_id: string;
    name: string | null;
    rendered_text: string;
    status: string;
    attempts: number;
    last_error: string | null;
    sent_at_display: string | null;
};

type LogRow = {
    id: number;
    event: string;
    message: string | null;
    created_at_display: string | null;
};

export default function BroadcastsShow({
    broadcast,
    recipients,
    logs,
    filter_status: filterStatus,
}: {
    broadcast: BroadcastDetail;
    recipients: Pagination<RecipientRow>;
    logs: LogRow[];
    filter_status: string | null;
}) {
    const actionForm = useForm({});
    const [resolveFor, setResolveFor] = useState<RecipientRow | null>(null);
    const resolveForm = useForm<{ decision: 'mark_sent' | 'retry'; note: string }>({
        decision: 'mark_sent',
        note: '',
    });

    const running =
        broadcast.status === 'running' || broadcast.status === 'queued';

    // Light polling while work is in flight (spec J).
    useEffect(() => {
        if (!running) {
            return;
        }

        const interval = setInterval(() => {
            router.reload({ only: ['broadcast', 'recipients', 'logs'] });
        }, 10_000);

        return () => clearInterval(interval);
    }, [running]);

    const reviewed = useMemo(
        () =>
            broadcast.counts.sent +
                broadcast.counts.pending +
                broadcast.counts.sending +
                broadcast.counts.failed +
                broadcast.counts.unknown +
                broadcast.counts.canceled ===
            broadcast.counts.total,
        [broadcast.counts],
    );

    return (
        <>
            <Head title={`Broadcast · ${broadcast.name}`} />
            <div className="flex h-full flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <div className="mb-8 space-y-0.5">
                        <div className="flex items-center gap-3">
                            <h2 className="text-xl font-semibold tracking-tight">
                                {broadcast.name}
                            </h2>
                            <StatusBadge status={broadcast.status} />
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {broadcast.type} · {broadcast.counts.total}{' '}
                            recipient(s)
                            {broadcast.started_at_display
                                ? ` · started ${broadcast.started_at_display}`
                                : ''}
                            {broadcast.finished_at_display
                                ? ` · finished ${broadcast.finished_at_display}`
                                : ''}
                        </p>
                    </div>

                    <div className="mb-8 flex flex-wrap gap-2">
                        {broadcast.editable ? (
                            <Button asChild variant="secondary">
                                <Link
                                    href={broadcastsRoutes.edit({
                                        broadcast: broadcast.id,
                                    })}
                                >
                                    Edit draft
                                </Link>
                            </Button>
                        ) : null}

                        {broadcast.pausable ? (
                            <Button
                                variant="secondary"
                                disabled={actionForm.processing}
                                onClick={() =>
                                    actionForm.post(broadcastsRoutes.pause.url({
                                            broadcast: broadcast.id,
                                        }),
                                        {
                                            onSuccess: () =>
                                                toast.success('Paused.'),
                                        },
                                    )
                                }
                            >
                                <Pause className="size-4" /> Pause
                            </Button>
                        ) : null}

                        {broadcast.resumable ? (
                            <Button
                                variant="secondary"
                                disabled={actionForm.processing}
                                onClick={() =>
                                    actionForm.post(broadcastsRoutes.resume.url({
                                            broadcast: broadcast.id,
                                        }),
                                        {
                                            onSuccess: () =>
                                                toast.success('Resumed.'),
                                        },
                                    )
                                }
                            >
                                <Play className="size-4" /> Resume
                            </Button>
                        ) : null}

                        {broadcast.cancellable ? (
                            <Button
                                variant="ghost"
                                className="text-destructive"
                                disabled={actionForm.processing}
                                onClick={() => {
                                    if (
                                        !window.confirm(
                                            'Cancel this campaign? Not-yet-started recipients will be canceled; in-flight sends may still complete.',
                                        )
                                    ) {
                                        return;
                                    }
                                    actionForm.post(broadcastsRoutes.cancel.url({
                                            broadcast: broadcast.id,
                                        }),
                                        {
                                            onSuccess: () =>
                                                toast.success('Canceled.'),
                                        },
                                    );
                                }}
                            >
                                <XCircle className="size-4" /> Cancel
                            </Button>
                        ) : null}
                    </div>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Progress</CardTitle>
                        <CardDescription>
                            Counts derive from recipient tracking, so they
                            cannot drift from reality.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-wrap gap-2 text-sm">
                        {(
                            [
                                ['sent', 'text-emerald-600 dark:text-emerald-400'],
                                [
                                    'pending',
                                    'text-slate-600 dark:text-slate-300',
                                ],
                                ['sending', 'text-blue-600 dark:text-blue-400'],
                                ['failed', 'text-red-600 dark:text-red-400'],
                                [
                                    'unknown',
                                    'text-amber-600 dark:text-amber-400',
                                ],
                                [
                                    'canceled',
                                    'text-zinc-500 dark:text-zinc-400',
                                ],
                            ] as const
                        ).map(([key, tone]) => (
                            <span
                                key={key}
                                className={`rounded-lg border px-3 py-1.5 ${tone}`}
                            >
                                {broadcast.counts[key] ?? 0} {key}
                            </span>
                        ))}
                        <span className="rounded-lg border px-3 py-1.5 text-muted-foreground">
                            total {broadcast.counts.total}
                        </span>
                        {!reviewed ? null : null}
                    </CardContent>
                </Card>

                {broadcast.editable ? (
                    <DraftRecipientsCard
                        broadcastId={broadcast.id}
                        templateText={broadcast.template_text}
                    />
                ) : null}

                {resolveFor ? (
                    <Card>
                        <CardHeader>
                            <CardTitle>
                                Resolve unknown · {resolveFor.chat_id}
                            </CardTitle>
                            <CardDescription>
                                Marking sent never invents a WAHA id; retrying
                                accepts the risk of a duplicate. The campaign
                                stays paused until you resume it explicitly.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            <div className="flex flex-wrap gap-2">
                                {(
                                    ['mark_sent', 'retry'] as const
                                ).map((option) => (
                                    <Button
                                        key={option}
                                        type="button"
                                        size="sm"
                                        variant={
                                            resolveForm.data.decision ===
                                            option
                                                ? 'default'
                                                : 'outline'
                                        }
                                        onClick={() =>
                                            resolveForm.setData(
                                                'decision',
                                                option,
                                            )
                                        }
                                    >
                                        {option === 'mark_sent'
                                            ? 'It was sent'
                                            : 'Resend (duplicate risk)'}
                                    </Button>
                                ))}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="recipient-note">
                                    Note (required, audited)
                                </Label>
                                <Textarea
                                    id="recipient-note"
                                    rows={2}
                                    value={resolveForm.data.note}
                                    onChange={(event) =>
                                        resolveForm.setData(
                                            'note',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                            <div className="flex gap-2">
                                <Button
                                    disabled={
                                        resolveForm.processing ||
                                        resolveForm.data.note.trim().length < 3
                                    }
                                    onClick={() =>
                                        resolveForm.post(
                                            broadcastsRoutes.recipients.resolve.url({
                                                broadcast: broadcast.id,
                                                recipient: resolveFor.id,
                                            }),
                                            {
                                                onSuccess: () => {
                                                    setResolveFor(null);
                                                    resolveForm.reset();
                                                    toast.success(
                                                        'Outcome resolved.',
                                                    );
                                                },
                                            },
                                        )
                                    }
                                >
                                    {resolveForm.processing && <Spinner />}
                                    Confirm
                                </Button>
                                <Button
                                    variant="ghost"
                                    onClick={() => setResolveFor(null)}
                                >
                                    Close
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                ) : null}

                <Card className="pb-2">
                    <CardHeader>
                        <CardTitle>Recipients</CardTitle>
                        <CardDescription>
                            {recipients.total} shown row(s)
                            {filterStatus ? ` (filtered: ${filterStatus})` : ''}
                        </CardDescription>
                    </CardHeader>
                    <div className="px-6 pb-2">
                        <Select
                            value={filterStatus ?? 'all'}
                            onValueChange={(value) =>
                                router.get(
                                    broadcastsRoutes.show({
                                        broadcast: broadcast.id,
                                    }),
                                    value === 'all' ? {} : { status: value },
                                    { preserveState: true },
                                )
                            }
                        >
                            <SelectTrigger className="w-44">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {[
                                    'all',
                                    'pending',
                                    'sending',
                                    'sent',
                                    'failed',
                                    'failed_max',
                                    'unknown',
                                    'canceled',
                                ].map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {status}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Recipient</TableHead>
                                <TableHead>Message</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Attempts</TableHead>
                                <TableHead>Detail</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {recipients.data.map((recipient) => (
                                <TableRow key={recipient.id}>
                                    <TableCell>
                                        <span className="text-sm">
                                            {recipient.name ?? '—'}
                                        </span>
                                        <span className="block font-mono text-xs text-muted-foreground">
                                            {recipient.chat_id}
                                        </span>
                                    </TableCell>
                                    <TableCell className="max-w-72 truncate text-sm">
                                        {recipient.rendered_text}
                                    </TableCell>
                                    <TableCell>
                                        <StatusBadge
                                            status={recipient.status}
                                        />
                                    </TableCell>
                                    <TableCell>{recipient.attempts}</TableCell>
                                    <TableCell className="max-w-64">
                                        {recipient.status === 'unknown' ? (
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() => {
                                                    setResolveFor(recipient);
                                                    resolveForm.reset();
                                                }}
                                            >
                                                Resolve…
                                            </Button>
                                        ) : recipient.last_error ? (
                                            <span
                                                className="line-clamp-2 text-xs text-red-600 dark:text-red-400"
                                                title={recipient.last_error}
                                            >
                                                {recipient.last_error}
                                            </span>
                                        ) : (
                                            <span className="text-xs text-muted-foreground">
                                                {recipient.sent_at_display ??
                                                    '—'}
                                            </span>
                                        )}
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Event log</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-1">
                        {logs.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No events yet.
                            </p>
                        ) : (
                            logs.map((log) => (
                                <div
                                    key={log.id}
                                    className="flex justify-between gap-3 text-xs"
                                >
                                    <span>
                                        <span className="font-mono text-muted-foreground">
                                            {log.event}
                                        </span>{' '}
                                        {log.message}
                                    </span>
                                    <span className="shrink-0 text-muted-foreground">
                                        {log.created_at_display}
                                    </span>
                                </div>
                            ))
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

type OptedInContact = {
    id: number;
    chat_id: string;
    name: string | null;
    type: string;
};

type DraftRecipient = { chat_id: string; name: string | null };

/**
 * Draft step: choose opted-in contacts/groups and/or paste international
 * numbers, review the rendered snapshot, then queue. Consent is explicit:
 * only opted-in contacts are offered, pasted numbers are your responsibility.
 */
function DraftRecipientsCard({
    broadcastId,
    templateText,
}: {
    broadcastId: number;
    templateText: string;
}) {
    const [contacts, setContacts] = useState<OptedInContact[]>([]);
    const [selected, setSelected] = useState<DraftRecipient[]>([]);
    const [search, setSearch] = useState('');
    const [pasted, setPasted] = useState('');
    const queueForm = useForm<{ recipients: DraftRecipient[] }>({
        recipients: [],
    });

    useEffect(() => {
        const controller = new AbortController();
        fetch(`/contacts/opted-in?search=${encodeURIComponent(search)}`, {
            signal: controller.signal,
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then((response) => (response.ok ? response.json() : { data: [] }))
            .then((payload) => setContacts(payload.data ?? []))
            .catch(() => undefined);

        return () => controller.abort();
    }, [search]);

    const pastedRecipients: DraftRecipient[] = pasted
        .split(/[\r\n;,]+/)
        .map((line) => line.trim())
        .filter((line) => line !== '')
        .map((line) => {
            const match = /^\+?[\d\s()\-]{8,}$/.test(line)
                ? line.replace(/\D/g, '') + '@c.us'
                : line;
            const known = contacts.find((c) => c.chat_id === match);

            return { chat_id: match, name: known?.name ?? null };
        });

    const allRecipients = [...selected, ...pastedRecipients].filter(
        (recipient, index, list) =>
            list.findIndex((r) => r.chat_id === recipient.chat_id) === index,
    );

    const preview = (name: string | null) =>
        templateText.replaceAll('{name}', name?.trim() || 'there');

    const queue = () => {
        if (
            !window.confirm(
                `Queue this broadcast to ${allRecipients.length} recipient(s)? Sends are paced and can be paused.`,
            )
        ) {
            return;
        }

        queueForm.setData('recipients', allRecipients);
        queueForm.post(broadcastsRoutes.queue.url({ broadcast: broadcastId }), {
            onSuccess: () => toast.success('Campaign queued.'),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Recipients &amp; review</CardTitle>
                <CardDescription>
                    Only opted-in contacts are listed. Pasted numbers must be
                    international format — local numbers are rejected by the
                    server.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="grid gap-2 md:max-w-sm">
                    <Label htmlFor="contact-search">
                        Search opted-in contacts
                    </Label>
                    <Input
                        id="contact-search"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        placeholder="Name or number…"
                    />
                    <div className="max-h-56 space-y-1 overflow-y-auto rounded-lg border p-2">
                        {contacts.length === 0 ? (
                            <p className="p-2 text-sm text-muted-foreground">
                                No opted-in contacts. Sync and grant consent on
                                the Contacts page first.
                            </p>
                        ) : (
                            contacts.map((contact) => {
                                const isSelected = selected.some(
                                    (r) => r.chat_id === contact.chat_id,
                                );

                                return (
                                    <label
                                        key={contact.id}
                                        className="flex cursor-pointer items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-accent"
                                    >
                                        <span className="flex items-center gap-2">
                                            <input
                                                type="checkbox"
                                                checked={isSelected}
                                                onChange={(event) =>
                                                    setSelected((current) =>
                                                        event.target.checked
                                                            ? [
                                                                  ...current,
                                                                  {
                                                                      chat_id: contact.chat_id,
                                                                      name: contact.name,
                                                                  },
                                                              ]
                                                            : current.filter(
                                                                  (r) =>
                                                                      r.chat_id !==
                                                                      contact.chat_id,
                                                              ),
                                                    )
                                                }
                                                className="size-4"
                                            />
                                            {contact.name ?? contact.chat_id}
                                            <span className="text-xs text-muted-foreground">
                                                {contact.type}
                                            </span>
                                        </span>
                                        <span className="font-mono text-xs text-muted-foreground">
                                            {contact.chat_id}
                                        </span>
                                    </label>
                                );
                            })
                        )}
                    </div>
                </div>

                <div className="grid gap-2">
                    <Label htmlFor="paste-numbers">
                        Or paste numbers (one per line, international format)
                    </Label>
                    <Textarea
                        id="paste-numbers"
                        rows={3}
                        value={pasted}
                        onChange={(event) => setPasted(event.target.value)}
                        placeholder={'+62 811-1111-111\n628222222222\n120363…@g.us'}
                    />
                </div>

                <div className="rounded-lg border">
                    <div className="border-b px-3 py-2 text-sm font-medium">
                        Review ({allRecipients.length} recipient(s))
                    </div>
                    <div className="max-h-56 overflow-y-auto p-2">
                        {allRecipients.length === 0 ? (
                            <p className="p-2 text-sm text-muted-foreground">
                                Nothing selected yet.
                            </p>
                        ) : (
                            allRecipients.map((recipient) => (
                                <div
                                    key={recipient.chat_id}
                                    className="flex justify-between gap-3 px-2 py-1 text-xs"
                                >
                                    <span className="font-mono text-muted-foreground">
                                        {recipient.chat_id}
                                    </span>
                                    <span className="max-w-72 truncate">
                                        {preview(recipient.name)}
                                    </span>
                                </div>
                            ))
                        )}
                    </div>
                </div>

                <Button
                    onClick={queue}
                    disabled={queueForm.processing || allRecipients.length === 0}
                >
                    {queueForm.processing && <Spinner />}
                    Queue broadcast ({allRecipients.length})
                </Button>
            </CardContent>
        </Card>
    );
}

BroadcastsShow.layout = {
    breadcrumbs: [
        {
            title: 'Broadcasts',
            href: broadcastsRoutes.index(),
        },
        { title: 'Detail' },
    ],
};
