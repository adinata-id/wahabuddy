import { Head, Link, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CalendarClock,
    CircleCheck,
    CircleHelp,
    CircleX,
    Clock,
    Play,
} from 'lucide-react';
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
import { dashboard as dashboardRoute } from '@/routes';
import storiesRoutes from '@/routes/stories';
import { run as runScheduler } from '@/routes/scheduler';
import settingsRoutes from '@/routes/settings';

type Counts = {
    today_total: number;
    sent_today: number;
    sent_month: number;
    pending: number;
    sending: number;
    failed: number;
    failed_max: number;
    unknown: number;
    expired: number;
    canceled: number;
};

type UpcomingItem = {
    id: number;
    type: string;
    text: string | null;
    status: string;
    scheduled_at_display: string;
    held: boolean;
};

type ActivityItem = {
    id: number;
    story_id: number;
    event: string;
    message: string | null;
    created_at_display: string;
};

export default function Dashboard({
    counts,
    upcoming,
    activity,
    scheduler,
    worker,
    connection_warning: warning,
}: {
    counts: Counts;
    upcoming: UpcomingItem[];
    activity: ActivityItem[];
    scheduler: {
        last_run_at_display: string | null;
        status: string | null;
        last_error: string | null;
    };
    worker: { heartbeat_at: string | null; stale: boolean };
    connection_warning: {
        untested: boolean;
        unconfigured: boolean;
        held_stories: number;
        revision: number;
    };
}) {
    const runForm = useForm({});

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Dashboard"
                        description="Story scheduling health at a glance."
                    />
                    <Button
                        className="mb-8"
                        disabled={runForm.processing}
                        onClick={() =>
                            runForm.post(runScheduler.url(), {
                                onSuccess: () =>
                                    toast.success(
                                        'Scheduler run queued; results appear on the next run.',
                                    ),
                            })
                        }
                    >
                        {runForm.processing ? <Spinner /> : <Play className="size-4" />}
                        Run scheduler now
                    </Button>
                </div>

                {warning.unconfigured ? (
                    <WarningBanner>
                        The WAHA connection is not configured. Open{' '}
                        <Link className="underline" href={settingsRoutes.index()}>
                            Settings
                        </Link>{' '}
                        to connect your instance — nothing will be sent until then.
                    </WarningBanner>
                ) : warning.untested ? (
                    <WarningBanner>
                        The last connection test did not pass. Sends are held until a{' '}
                        <Link className="underline" href={settingsRoutes.index()}>
                            successful connection test
                        </Link>
                        .
                    </WarningBanner>
                ) : null}

                {warning.held_stories > 0 ? (
                    <WarningBanner>
                        {warning.held_stories} scheduled item(s) target WAHA revision{' '}
                        {warning.revision - 1} and are held until you confirm the new
                        target (retry them from their detail page).
                    </WarningBanner>
                ) : null}

                {worker.stale ? (
                    <WarningBanner>
                        The queue worker heartbeat is stale — stories will not send
                        until a worker runs{' '}
                        <code className="rounded bg-muted px-1">
                            php artisan queue:work --tries=1 --timeout=180
                        </code>
                        .
                    </WarningBanner>
                ) : null}

                <div className="grid auto-rows-min gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    <StatTile
                        icon={CalendarClock}
                        label="Scheduled today"
                        value={counts.today_total}
                        hint={`${counts.sent_today} sent today`}
                    />
                    <StatTile
                        icon={CircleCheck}
                        label="Sent this month"
                        value={counts.sent_month}
                    />
                    <StatTile
                        icon={Clock}
                        label="Pending"
                        value={counts.pending}
                        hint={counts.sending > 0 ? `${counts.sending} sending now` : undefined}
                    />
                    <StatTile
                        icon={CircleX}
                        label="Failed"
                        value={counts.failed}
                        hint={
                            counts.failed_max > 0
                                ? `${counts.failed_max} reached max attempts`
                                : undefined
                        }
                    />
                    <StatTile
                        icon={CircleHelp}
                        label="Unknown"
                        value={counts.unknown}
                        hint="Needs manual resolution"
                    />
                    <StatTile icon={Clock} label="Expired" value={counts.expired} />
                    <StatTile icon={CircleX} label="Canceled" value={counts.canceled} />
                    <Card>
                        <CardContent className="flex flex-col gap-1 py-4">
                            <span className="text-xs text-muted-foreground">
                                Scheduler
                            </span>
                            <span className="text-2xl font-semibold tabular-nums">
                                {scheduler.status ?? '—'}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {scheduler.last_run_at_display
                                    ? `last run ${scheduler.last_run_at_display}`
                                    : 'no runs recorded'}
                            </span>
                            {scheduler.status === 'failed' && scheduler.last_error ? (
                                <span className="text-xs text-red-600 dark:text-red-400">
                                    {scheduler.last_error}
                                </span>
                            ) : null}
                        </CardContent>
                    </Card>
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Upcoming</CardTitle>
                            <CardDescription>
                                Next scheduled and retryable stories
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {upcoming.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    Nothing scheduled.{' '}
                                    <Link
                                        className="underline"
                                        href={storiesRoutes.create()}
                                    >
                                        Schedule a story
                                    </Link>
                                    .
                                </p>
                            ) : (
                                upcoming.map((item) => (
                                    <Link
                                        key={item.id}
                                        href={storiesRoutes.show({ story: item.id })}
                                        className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm hover:bg-accent"
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            <StatusBadge status={item.status} />
                                            <span className="truncate">
                                                {item.text ?? `#${item.id}`}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {item.scheduled_at_display}
                                            {item.held ? ' · held' : ''}
                                        </span>
                                    </Link>
                                ))
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2">
                                <Activity className="size-4" aria-hidden /> Recent
                                activity
                            </CardTitle>
                            <CardDescription>
                                Latest story events across the installation
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            {activity.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No activity yet.
                                </p>
                            ) : (
                                activity.map((item) => (
                                    <Link
                                        key={item.id}
                                        href={storiesRoutes.show({ story: item.story_id })}
                                        className="flex items-start justify-between gap-3 rounded-lg px-1 py-1 text-sm hover:bg-accent"
                                    >
                                        <span className="min-w-0">
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {item.event}
                                            </span>
                                            <span className="block truncate">
                                                {item.message ?? `Story #${item.story_id}`}
                                            </span>
                                        </span>
                                        <span className="shrink-0 text-xs text-muted-foreground">
                                            {item.created_at_display}
                                        </span>
                                    </Link>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboardRoute(),
        },
    ],
};

function StatTile({
    icon: Icon,
    label,
    value,
    hint,
}: {
    icon: typeof Clock;
    label: string;
    value: number;
    hint?: string;
}) {
    return (
        <Card>
            <CardContent className="flex flex-col gap-1 py-4">
                <span className="flex items-center gap-1.5 text-xs text-muted-foreground">
                    <Icon className="size-3.5" aria-hidden /> {label}
                </span>
                <span className="text-2xl font-semibold tabular-nums">{value}</span>
                {hint ? (
                    <span className="text-xs text-muted-foreground">{hint}</span>
                ) : null}
            </CardContent>
        </Card>
    );
}

function WarningBanner({ children }: { children: React.ReactNode }) {
    return (
        <div className="flex items-start gap-2 rounded-lg border border-amber-500/40 bg-amber-500/10 p-3 text-sm text-amber-800 dark:text-amber-300">
            <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
            <div>{children}</div>
        </div>
    );
}
