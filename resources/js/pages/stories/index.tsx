import { useState } from 'react';
import { Head, Link, router } from '@inertiajs/react';
import {
    createColumnHelper,
    flexRender,
    getCoreRowModel,
    useReactTable,
    type ColumnDef,
} from '@tanstack/react-table';
import { Images, Plus } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
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
import type {
    Pagination,
    StoryFilters,
    StoryListItem,
} from '@/types/wahabuddy';

const columnHelper = createColumnHelper<StoryListItem>();

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
                    <span className="flex size-10 items-center justify-center rounded-md border text-xs uppercase text-muted-foreground">
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
                                {stories.total} story
                                {stories.total === 1 ? '' : 'ies'} · page{' '}
                                {stories.current_page} of {stories.last_page}
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
