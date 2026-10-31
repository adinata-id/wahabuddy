import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    createColumnHelper,
    flexRender,
    getCoreRowModel,
    useReactTable,
    type ColumnDef,
} from '@tanstack/react-table';
import {
    BadgeCheck,
    Images,
    Plus,
    RotateCcw,
    Trash2,
    XCircle,
} from 'lucide-react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatusBadge, statusLabel } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Spinner } from '@/components/ui/spinner';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import storiesRoutes from '@/routes/stories';
import { bulk as bulkRoute } from '@/routes/stories';
import { JsonError, postJson } from '@/lib/http';
import type {
    Pagination,
    StoryFilters,
    StoryListItem,
} from '@/types/wahabuddy';

const columnHelper = createColumnHelper<StoryListItem>();

/** Statuses an admin may set through the bulk "Update status" action. */
const SETTABLE_STATUSES = [
    'pending',
    'sent',
    'failed',
    'failed_max',
    'expired',
    'canceled',
] as const;

export default function StoriesIndex({
    stories,
    filters,
}: {
    stories: Pagination<StoryListItem>;
    filters: StoryFilters;
}) {
    const [draft, setDraft] = useState({
        status: filters.status ?? 'all',
        type: filters.type ?? 'all',
        from: filters.from ?? '',
        to: filters.to ?? '',
    });
    const [selected, setSelected] = useState<number[]>([]);
    const [statusTarget, setStatusTarget] = useState<string>('');
    const [running, setRunning] = useState<
        'cancel' | 'retry' | 'delete' | 'set_status' | null
    >(null);
    const [pendingBulk, setPendingBulk] = useState<{
        action: 'cancel' | 'retry' | 'delete' | 'set_status';
        ids: number[];
        status: string;
    } | null>(null);
    const [bulkError, setBulkError] = useState<string | null>(null);

    const pageIds = stories.data.map((story) => story.id);
    const allOnPageSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.includes(id));
    const someOnPageSelected = selected.length > 0 && !allOnPageSelected;

    const toggleRow = (id: number, checked: boolean) => {
        setSelected((current) =>
            checked
                ? [...current, id]
                : current.filter((selectedId) => selectedId !== id),
        );
    };

    const togglePage = () => {
        setSelected((current) =>
            allOnPageSelected
                ? current.filter((id) => !pageIds.includes(id))
                : [...new Set([...current, ...pageIds])],
        );
    };

    // Snapshot the selection when the dialog opens so changes made while it
    // is open cannot alter what gets submitted.
    const requestBulk = (
        action: 'cancel' | 'retry' | 'delete' | 'set_status',
    ) => {
        setBulkError(null);
        setPendingBulk({ action, ids: selected, status: statusTarget });
    };

    const executeBulk = async () => {
        if (!pendingBulk) {
            return;
        }

        const { action, ids, status } = pendingBulk;

        setRunning(action);

        try {
            const result = await postJson<{
                updated: number;
                skipped: number;
                summary: string;
            }>(bulkRoute.url(), {
                action,
                ids,
                ...(action === 'set_status' ? { status } : {}),
            });

            toast.success(result.summary);
            setPendingBulk(null);
            setSelected([]);
            setStatusTarget('');
            router.reload({ only: ['stories'] });
        } catch (error) {
            if (error instanceof JsonError) {
                const payload = error.data as {
                    message?: string;
                    errors?: Record<string, string[]>;
                } | null;

                setBulkError(
                    payload?.errors
                        ? Object.values(payload.errors).flat().join(' ')
                        : (payload?.message ??
                              'The bulk action could not be completed.'),
                );
            } else {
                setBulkError('The bulk action could not be completed.');
            }
        } finally {
            setRunning(null);
        }
    };

    const applyFilters = () => {
        const query: Record<string, string> = {};
        if (draft.status !== 'all') query.status = draft.status;
        if (draft.type !== 'all') query.type = draft.type;
        if (draft.from) query.from = draft.from;
        if (draft.to) query.to = draft.to;

        router.get(storiesRoutes.index(), query, { preserveState: true });
    };

    const columns = [
        columnHelper.display({
            id: 'select',
            header: () => (
                <Checkbox
                    checked={
                        allOnPageSelected
                            ? true
                            : someOnPageSelected
                              ? 'indeterminate'
                              : false
                    }
                    onCheckedChange={() => togglePage()}
                    aria-label="Select all stories on this page"
                />
            ),
            cell: ({ row }) => (
                <Checkbox
                    checked={selected.includes(row.original.id)}
                    onCheckedChange={(checked) =>
                        toggleRow(row.original.id, checked === true)
                    }
                    aria-label={`Select story ${row.original.id}`}
                    onClick={(event) => event.stopPropagation()}
                />
            ),
        }),
        columnHelper.display({
            id: 'preview',
            header: 'Preview',
            cell: ({ row }) =>
                row.original.thumbnail_url ? (
                    <img
                        src={row.original.thumbnail_url}
                        alt=""
                        className="size-10 rounded-md border object-cover"
                    />
                ) : (
                    <span className="flex size-10 items-center justify-center rounded-md border text-xs text-muted-foreground uppercase">
                        {row.original.type === 'text' ? 'T' : row.original.type}
                    </span>
                ),
        }),
        columnHelper.accessor('text', {
            header: 'Content',
            cell: ({ row }) => (
                <Link
                    href={storiesRoutes.show({ story: row.original.id })}
                    className="line-clamp-2 max-w-72 text-sm hover:underline"
                >
                    {row.original.text ?? (
                        <span className="text-muted-foreground">—</span>
                    )}
                </Link>
            ),
        }),
        columnHelper.accessor('type', {
            header: 'Type',
            cell: ({ row }) => (
                <span className="text-sm capitalize">{row.original.type}</span>
            ),
        }),
        columnHelper.accessor('scheduled_at_display', {
            header: 'Scheduled',
            cell: ({ row }) => (
                <div className="text-sm">
                    {row.original.scheduled_at_display}
                    <span className="block text-xs text-muted-foreground">
                        {row.original.display_timezone}
                    </span>
                </div>
            ),
        }),
        columnHelper.accessor('status', {
            header: 'Status',
            cell: ({ row }) => <StatusBadge status={row.original.status} />,
        }),
        columnHelper.accessor('attempts', {
            header: 'Attempts',
            cell: ({ row }) => row.original.attempts,
        }),
        columnHelper.accessor('last_error', {
            header: 'Last error',
            cell: ({ row }) =>
                row.original.last_error ? (
                    <span
                        className="line-clamp-2 max-w-64 text-xs text-red-600 dark:text-red-400"
                        title={row.original.last_error}
                    >
                        {row.original.last_error}
                    </span>
                ) : (
                    <span className="text-muted-foreground">—</span>
                ),
        }),
    ];

    const table = useReactTable({
        data: stories.data,
        columns: columns as ColumnDef<StoryListItem, unknown>[],
        getCoreRowModel: getCoreRowModel(),
        manualPagination: true,
    });

    return (
        <>
            <Head title="Stories" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Stories"
                        description="Scheduled WhatsApp status updates via your WAHA session."
                    />
                    <Button asChild className="mb-8">
                        <Link href={storiesRoutes.create()}>
                            <Plus className="size-4" /> Schedule story
                        </Link>
                    </Button>
                </div>

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-3 py-4">
                        <div className="grid gap-1.5">
                            <span className="text-xs text-muted-foreground">
                                Status
                            </span>
                            <Select
                                value={draft.status}
                                onValueChange={(value) =>
                                    setDraft((d) => ({ ...d, status: value }))
                                }
                            >
                                <SelectTrigger className="w-36">
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
                                        'expired',
                                        'canceled',
                                    ].map((status) => (
                                        <SelectItem key={status} value={status}>
                                            {status}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <span className="text-xs text-muted-foreground">
                                Type
                            </span>
                            <Select
                                value={draft.type}
                                onValueChange={(value) =>
                                    setDraft((d) => ({ ...d, type: value }))
                                }
                            >
                                <SelectTrigger className="w-28">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {['all', 'text', 'image', 'video'].map(
                                        (type) => (
                                            <SelectItem key={type} value={type}>
                                                {type}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="grid gap-1.5">
                            <span className="text-xs text-muted-foreground">
                                From
                            </span>
                            <Input
                                type="date"
                                value={draft.from}
                                onChange={(e) =>
                                    setDraft((d) => ({
                                        ...d,
                                        from: e.target.value,
                                    }))
                                }
                                className="w-40"
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <span className="text-xs text-muted-foreground">
                                To
                            </span>
                            <Input
                                type="date"
                                value={draft.to}
                                onChange={(e) =>
                                    setDraft((d) => ({
                                        ...d,
                                        to: e.target.value,
                                    }))
                                }
                                className="w-40"
                            />
                        </div>
                        <Button
                            variant="secondary"
                            onClick={applyFilters}
                            type="button"
                        >
                            Apply
                        </Button>
                    </CardContent>
                </Card>

                {selected.length > 0 ? (
                    <Card className="border-primary/30 bg-primary/5">
                        <CardContent className="flex flex-wrap items-center gap-2 py-3">
                            <span className="text-sm font-medium">
                                {selected.length} selected
                            </span>
                            <span className="flex flex-wrap gap-2">
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    disabled={running !== null}
                                    onClick={() => requestBulk('cancel')}
                                >
                                    {running === 'cancel' ? (
                                        <Spinner />
                                    ) : (
                                        <XCircle className="size-4" />
                                    )}
                                    Cancel selected
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    disabled={running !== null}
                                    onClick={() => requestBulk('retry')}
                                >
                                    {running === 'retry' ? (
                                        <Spinner />
                                    ) : (
                                        <RotateCcw className="size-4" />
                                    )}
                                    Retry selected
                                </Button>
                                <Select
                                    value={statusTarget}
                                    onValueChange={setStatusTarget}
                                >
                                    <SelectTrigger
                                        className="h-8 w-36"
                                        aria-label="Target status"
                                    >
                                        <SelectValue placeholder="Set status…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {SETTABLE_STATUSES.map((status) => (
                                            <SelectItem
                                                key={status}
                                                value={status}
                                            >
                                                {statusLabel(status)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    disabled={
                                        running !== null || statusTarget === ''
                                    }
                                    onClick={() => requestBulk('set_status')}
                                >
                                    {running === 'set_status' ? (
                                        <Spinner />
                                    ) : (
                                        <BadgeCheck className="size-4" />
                                    )}
                                    Update status
                                </Button>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="ghost"
                                    className="text-destructive"
                                    disabled={running !== null}
                                    onClick={() => requestBulk('delete')}
                                >
                                    {running === 'delete' ? (
                                        <Spinner />
                                    ) : (
                                        <Trash2 className="size-4" />
                                    )}
                                    Delete selected
                                </Button>
                            </span>
                            <Button
                                type="button"
                                size="sm"
                                variant="ghost"
                                className="ml-auto"
                                onClick={() => setSelected([])}
                            >
                                Clear selection
                            </Button>
                        </CardContent>
                    </Card>
                ) : null}

                <ConfirmDialog
                    open={pendingBulk !== null}
                    onOpenChange={(open) => {
                        if (!open) {
                            setPendingBulk(null);
                            setBulkError(null);
                        }
                    }}
                    title={`${
                        pendingBulk?.action === 'set_status'
                            ? 'Set status of'
                            : pendingBulk?.action === 'delete'
                              ? 'Delete'
                              : pendingBulk?.action === 'cancel'
                                ? 'Cancel'
                                : 'Retry'
                    } ${pendingBulk?.ids.length ?? 0} selected stor${
                        (pendingBulk?.ids.length ?? 0) === 1 ? 'y' : 'ies'
                    }?`}
                    description={
                        pendingBulk?.action === 'set_status' ? (
                            <>
                                Rows already{' '}
                                <StatusBadge status={pendingBulk.status} />,
                                currently <StatusBadge status="sending" />, or{' '}
                                <StatusBadge status="unknown" /> are skipped.
                            </>
                        ) : pendingBulk?.action === 'delete' ? (
                            <>
                                Records still <StatusBadge status="sending" />{' '}
                                or in <StatusBadge status="unknown" /> state are
                                kept.
                            </>
                        ) : (
                            'Stories not eligible for this action are skipped.'
                        )
                    }
                    confirmLabel={
                        pendingBulk?.action === 'set_status'
                            ? `Set to ${statusLabel(pendingBulk.status)}`
                            : pendingBulk?.action === 'delete'
                              ? 'Delete stories'
                              : pendingBulk?.action === 'cancel'
                                ? 'Cancel stories'
                                : 'Retry stories'
                    }
                    destructive={pendingBulk?.action === 'delete'}
                    processing={running !== null}
                    error={bulkError}
                    onConfirm={() => void executeBulk()}
                />

                {stories.data.length === 0 ? (
                    <EmptyState
                        icon={Images}
                        title="No stories match"
                        description="Schedule your first status story or adjust the filters above."
                    />
                ) : (
                    <Card className="pb-2">
                        <Table>
                            <TableHeader>
                                {table.getHeaderGroups().map((group) => (
                                    <TableRow key={group.id}>
                                        {group.headers.map((header) => (
                                            <TableHead key={header.id}>
                                                {flexRender(
                                                    header.column.columnDef
                                                        .header,
                                                    header.getContext(),
                                                )}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableHeader>
                            <TableBody>
                                {table.getRowModel().rows.map((row) => (
                                    <TableRow key={row.id}>
                                        {row.getVisibleCells().map((cell) => (
                                            <TableCell key={cell.id}>
                                                {flexRender(
                                                    cell.column.columnDef.cell,
                                                    cell.getContext(),
                                                )}
                                            </TableCell>
                                        ))}
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <div className="flex items-center justify-between px-4 py-3 text-sm text-muted-foreground">
                            <span>
                                {stories.total}{' '}
                                {stories.total === 1 ? 'story' : 'stories'} ·
                                page {stories.current_page} of{' '}
                                {stories.last_page}
                            </span>
                            <span className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={stories.current_page <= 1}
                                    onClick={() =>
                                        router.get(
                                            storiesRoutes.index(),
                                            { page: stories.current_page - 1 },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    Previous
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        stories.current_page >=
                                        stories.last_page
                                    }
                                    onClick={() =>
                                        router.get(
                                            storiesRoutes.index(),
                                            { page: stories.current_page + 1 },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    Next
                                </Button>
                            </span>
                        </div>
                    </Card>
                )}
            </div>
        </>
    );
}

StoriesIndex.layout = {
    breadcrumbs: [
        {
            title: 'Stories',
            href: storiesRoutes.index(),
        },
    ],
};
