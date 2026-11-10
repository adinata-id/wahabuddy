import { Head, Link, router } from '@inertiajs/react';
import { Plus, Send } from 'lucide-react';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { StatusBadge } from '@/components/status-badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import broadcastsRoutes from '@/routes/broadcasts';
import type { Pagination } from '@/types/wahabuddy';

export type BroadcastListItem = {
    id: number;
    name: string;
    type: string;
    status: string;
    created_at_display: string;
    started_at_display: string | null;
    finished_at_display: string | null;
    counts: {
        sent: number;
        pending: number;
        sending: number;
        failed: number;
        unknown: number;
        canceled: number;
        total: number;
    };
};

export default function BroadcastsIndex({
    broadcasts,
}: {
    broadcasts: Pagination<BroadcastListItem>;
}) {
    return (
        <>
            <Head title="Broadcasts" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Broadcasts"
                        description="Paced campaigns to your opted-in contacts and groups."
                    />
                    <Button asChild className="mb-8">
                        <Link href={broadcastsRoutes.create()}>
                            <Plus className="size-4" /> New broadcast
                        </Link>
                    </Button>
                </div>

                {broadcasts.data.length === 0 ? (
                    <EmptyState
                        icon={Send}
                        title="No broadcasts yet"
                        description="Create a broadcast to send a personalized text or image message to your opted-in contacts and groups."
                    />
                ) : (
                    <Card className="pb-2">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Progress</TableHead>
                                    <TableHead>Started</TableHead>
                                    <TableHead>Finished</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {broadcasts.data.map((broadcast) => (
                                    <TableRow key={broadcast.id}>
                                        <TableCell>
                                            <Link
                                                href={broadcastsRoutes.show({
                                                    broadcast: broadcast.id,
                                                })}
                                                className="font-medium hover:underline"
                                            >
                                                {broadcast.name}
                                            </Link>
                                            <span className="block text-xs text-muted-foreground">
                                                {broadcast.type}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            <StatusBadge
                                                status={broadcast.status}
                                            />
                                        </TableCell>
                                        <TableCell>
                                            <span className="text-xs">
                                                {broadcast.counts.sent} sent ·{' '}
                                                {broadcast.counts.pending +
                                                    broadcast.counts
                                                        .sending}{' '}
                                                pending ·{' '}
                                                {broadcast.counts.failed} failed
                                                · {broadcast.counts.unknown}{' '}
                                                unknown
                                                {broadcast.counts.canceled > 0
                                                    ? ` · ${broadcast.counts.canceled} canceled`
                                                    : ''}
                                                {' / '}
                                                {broadcast.counts.total}
                                            </span>
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {broadcast.started_at_display ??
                                                '—'}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {broadcast.finished_at_display ??
                                                '—'}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <div className="flex items-center justify-between px-4 py-3 text-sm text-muted-foreground">
                            <span>
                                {broadcasts.total} campaign
                                {broadcasts.total === 1 ? '' : 's'} · page{' '}
                                {broadcasts.current_page} of{' '}
                                {broadcasts.last_page}
                            </span>
                            <span className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={broadcasts.current_page <= 1}
                                    onClick={() =>
                                        router.get(
                                            broadcastsRoutes.index(),
                                            {
                                                page:
                                                    broadcasts.current_page - 1,
                                            },
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
                                        broadcasts.current_page >=
                                        broadcasts.last_page
                                    }
                                    onClick={() =>
                                        router.get(
                                            broadcastsRoutes.index(),
                                            {
                                                page:
                                                    broadcasts.current_page + 1,
                                            },
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

BroadcastsIndex.layout = {
    breadcrumbs: [
        {
            title: 'Broadcasts',
            href: broadcastsRoutes.index(),
        },
    ],
};
