import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { HardDrive } from 'lucide-react';
import { toast } from 'sonner';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postJson } from '@/lib/http';
import { testStorage as testStorageRoute } from '@/routes/media';
import { update as updatePreferencesRoute } from '@/routes/settings/preferences';
import { cleanupRun as cleanupRunRoute } from '@/routes/settings/storage';
import { checkPublicUrl as checkPublicUrlRoute } from '@/routes/settings/storage';
import type { StorageSettings } from '@/types/wahabuddy';

const WEEKDAYS = [
    'Sunday',
    'Monday',
    'Tuesday',
    'Wednesday',
    'Thursday',
    'Friday',
    'Saturday',
];

type StorageForm = {
    media_disk: string;
    media_public_base_url: string | null;
    media_cleanup_enabled: boolean;
    media_cleanup_frequency: StorageSettings['cleanup']['frequency'];
    media_cleanup_day: number | null;
    media_cleanup_interval_days: number | null;
    media_cleanup_retention_days: number;
    media_cleanup_include_terminal: boolean;
};

function formatBytes(bytes: number): string {
    if (bytes >= 1024 * 1024 * 1024) {
        return `${(bytes / (1024 * 1024 * 1024)).toFixed(1)} GB`;
    }

    if (bytes >= 1024 * 1024) {
        return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
    }

    if (bytes >= 1024) {
        return `${Math.round(bytes / 1024)} KB`;
    }

    return `${bytes} B`;
}

function formatWhen(value: string | null): string {
    if (value === null) {
        return '—';
    }

    return new Date(value).toLocaleString();
}

export function StorageCard({ storage }: { storage: StorageSettings }) {
    const [testing, setTesting] = useState(false);
    const [checking, setChecking] = useState(false);
    const [running, setRunning] = useState(false);

    const form = useForm<StorageForm>({
        media_disk: storage.media_disk,
        media_public_base_url: storage.media_public_base_url,
        media_cleanup_enabled: storage.cleanup.enabled,
        media_cleanup_frequency: storage.cleanup.frequency,
        media_cleanup_day: storage.cleanup.day,
        media_cleanup_interval_days: storage.cleanup.interval_days,
        media_cleanup_retention_days: storage.cleanup.retention_days,
        media_cleanup_include_terminal: storage.cleanup.include_terminal,
    });

    const s3Available =
        storage.capabilities.s3_adapter_installed &&
        storage.capabilities.s3_credentials_present;

    const s3UnavailableReason = !storage.capabilities.s3_adapter_installed
        ? 'S3 driver package not installed'
        : 'AWS credentials not configured';

    const probeDisk = async () => {
        setTesting(true);

        try {
            const result = await postJson<{
                reachable: boolean;
                status: number | null;
                note: string;
            }>(testStorageRoute.url(), { disk: form.data.media_disk });

            if (result.reachable) {
                toast.success('Storage probe passed (from this backend only).');
            } else {
                toast.error(
                    `Storage probe failed${result.status ? ` (HTTP ${result.status})` : ''}.`,
                );
            }
        } catch {
            toast.error('Storage probe could not be completed.');
        } finally {
            setTesting(false);
        }
    };

    // Probes the URL typed in the field (saved or not) so a dead tunnel is
    // caught here instead of as failed sends.
    const probePublicUrl = async () => {
        setChecking(true);

        try {
            const result = await postJson<{
                reachable: boolean;
                status: number | null;
                note: string;
            }>(checkPublicUrlRoute.url(), {
                base_url: form.data.media_public_base_url,
            });

            if (result.reachable) {
                toast.success('Public URL check passed — the base answered.');
            } else {
                toast.error(
                    `Public URL check failed${result.status ? ` (HTTP ${result.status})` : ''}: ${result.note}`,
                );
            }
        } catch {
            toast.error('Public URL check could not be completed.');
        } finally {
            setChecking(false);
        }
    };

    const runCleanupNow = () => {
        setRunning(true);

        router.post(
            cleanupRunRoute.url(),
            {},
            {
                preserveScroll: true,
                onFinish: () => setRunning(false),
                onSuccess: (page) => {
                    const summary = page.props.cleanup_summary as
                        | string
                        | undefined as string | undefined;

                    toast.success(
                        summary && summary.length > 0
                            ? summary
                            : 'Cleanup run finished.',
                    );
                },
                onError: () => toast.error('Cleanup could not run.'),
            },
        );
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <HardDrive className="size-4" aria-hidden /> Storage &
                    auto-cleanup
                </CardTitle>
                <CardDescription>
                    Where new uploads are stored, and when the storage of
                    already-sent content is cleaned up. History rows always
                    survive — only files are removed.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        // Laravel's boolean rule wants 1/0, not
                        // "true"/"false" form strings; the day is clamped
                        // for the chosen cadence so a visible-but-null
                        // select can never submit.
                        form.transform((data) => ({
                            ...data,
                            media_cleanup_enabled: data.media_cleanup_enabled
                                ? 1
                                : 0,
                            media_cleanup_include_terminal:
                                data.media_cleanup_include_terminal ? 1 : 0,
                            media_cleanup_day:
                                data.media_cleanup_frequency === 'monthly'
                                    ? Math.min(
                                          28,
                                          Math.max(
                                              1,
                                              data.media_cleanup_day ?? 1,
                                          ),
                                      )
                                    : data.media_cleanup_frequency === 'weekly'
                                      ? Math.min(6, data.media_cleanup_day ?? 1)
                                      : data.media_cleanup_day,
                        }));
                        form.put(updatePreferencesRoute.url(), {
                            preserveScroll: true,
                            onSuccess: () =>
                                toast.success('Storage settings updated.'),
                        });
                    }}
                    className="space-y-4"
                >
                    <div className="grid gap-4 md:max-w-sm">
                        <Label htmlFor="media_disk">Media storage disk</Label>
                        <select
                            id="media_disk"
                            value={form.data.media_disk}
                            onChange={(e) =>
                                form.setData('media_disk', e.target.value)
                            }
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                        >
                            <option value="public">
                                Local disk (this server)
                            </option>
                            <option value="s3" disabled={!s3Available}>
                                {s3Available
                                    ? 'Amazon S3'
                                    : `Amazon S3 (${s3UnavailableReason})`}
                            </option>
                        </select>
                        <InputError message={form.errors.media_disk} />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={probeDisk}
                            disabled={testing}
                        >
                            {testing && <Spinner />}
                            Test storage
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            Existing files stay on the disk they were uploaded
                            to; cleanup handles each disk on its own.
                        </p>
                    </div>

                    <div className="grid gap-2 md:max-w-sm">
                        <Label htmlFor="media_public_base_url">
                            Public media base URL
                        </Label>
                        <Input
                            id="media_public_base_url"
                            type="url"
                            placeholder="https://your-tunnel.example.com"
                            value={form.data.media_public_base_url ?? ''}
                            onChange={(e) =>
                                form.setData(
                                    'media_public_base_url',
                                    e.target.value === ''
                                        ? null
                                        : e.target.value,
                                )
                            }
                        />
                        <InputError
                            message={form.errors.media_public_base_url}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            onClick={probePublicUrl}
                            disabled={
                                checking ||
                                !form.data.media_public_base_url?.trim()
                            }
                        >
                            {checking && <Spinner />}
                            Check public URL
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            WAHA downloads image/video media from this base
                            (e.g. a tunnel domain), so it must be reachable from
                            the internet. Leave empty to use the app URL.
                        </p>
                    </div>

                    <div className="grid gap-2 md:max-w-sm">
                        <Label
                            htmlFor="media_cleanup_enabled"
                            className="flex items-center gap-2"
                        >
                            <Checkbox
                                id="media_cleanup_enabled"
                                checked={form.data.media_cleanup_enabled}
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'media_cleanup_enabled',
                                        checked === true,
                                    )
                                }
                            />
                            Auto-cleanup enabled
                        </Label>
                        <InputError
                            message={form.errors.media_cleanup_enabled}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="media_cleanup_frequency">
                                Frequency
                            </Label>
                            <select
                                id="media_cleanup_frequency"
                                value={form.data.media_cleanup_frequency}
                                onChange={(e) => {
                                    const frequency = e.target
                                        .value as StorageForm['media_cleanup_frequency'];

                                    form.setData(
                                        'media_cleanup_frequency',
                                        frequency,
                                    );

                                    // Re-clamp a stale day for the new
                                    // cadence (weekday 0-6 vs month 1-28).
                                    const day = form.data.media_cleanup_day;

                                    if (frequency === 'monthly') {
                                        form.setData(
                                            'media_cleanup_day',
                                            day !== null &&
                                                day >= 1 &&
                                                day <= 28
                                                ? day
                                                : 1,
                                        );
                                    } else if (day === null || day > 6) {
                                        form.setData('media_cleanup_day', 1);
                                    }
                                }}
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                            >
                                <option value="daily">Daily</option>
                                <option value="weekly">Weekly</option>
                                <option value="monthly">Monthly</option>
                                <option value="custom">Every N days</option>
                            </select>
                            <InputError
                                message={form.errors.media_cleanup_frequency}
                            />
                        </div>

                        {form.data.media_cleanup_frequency === 'weekly' && (
                            <div className="grid gap-2">
                                <Label htmlFor="media_cleanup_day">
                                    Day of week
                                </Label>
                                <select
                                    id="media_cleanup_day"
                                    value={form.data.media_cleanup_day ?? 1}
                                    onChange={(e) =>
                                        form.setData(
                                            'media_cleanup_day',
                                            Number(e.target.value),
                                        )
                                    }
                                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    {WEEKDAYS.map((name, index) => (
                                        <option key={name} value={index}>
                                            {name}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={form.errors.media_cleanup_day}
                                />
                            </div>
                        )}

                        {form.data.media_cleanup_frequency === 'monthly' && (
                            <div className="grid gap-2">
                                <Label htmlFor="media_cleanup_day">
                                    Day of month
                                </Label>
                                <select
                                    id="media_cleanup_day"
                                    value={form.data.media_cleanup_day ?? 1}
                                    onChange={(e) =>
                                        form.setData(
                                            'media_cleanup_day',
                                            Number(e.target.value),
                                        )
                                    }
                                    className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    {Array.from(
                                        { length: 28 },
                                        (_, index) => index + 1,
                                    ).map((day) => (
                                        <option key={day} value={day}>
                                            {day}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={form.errors.media_cleanup_day}
                                />
                            </div>
                        )}

                        {form.data.media_cleanup_frequency === 'custom' && (
                            <div className="grid gap-2">
                                <Label htmlFor="media_cleanup_interval_days">
                                    Every (days)
                                </Label>
                                <Input
                                    id="media_cleanup_interval_days"
                                    type="number"
                                    min={1}
                                    max={365}
                                    value={
                                        form.data.media_cleanup_interval_days ??
                                        ''
                                    }
                                    onChange={(e) =>
                                        form.setData(
                                            'media_cleanup_interval_days',
                                            e.target.value === ''
                                                ? null
                                                : Number(e.target.value),
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        form.errors.media_cleanup_interval_days
                                    }
                                />
                            </div>
                        )}

                        <div className="grid gap-2">
                            <Label htmlFor="media_cleanup_retention_days">
                                Keep sent media (days)
                            </Label>
                            <Input
                                id="media_cleanup_retention_days"
                                type="number"
                                min={1}
                                max={90}
                                value={form.data.media_cleanup_retention_days}
                                onChange={(e) =>
                                    form.setData(
                                        'media_cleanup_retention_days',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError
                                message={
                                    form.errors.media_cleanup_retention_days
                                }
                            />
                        </div>
                    </div>

                    <div className="grid gap-2 md:max-w-md">
                        <Label
                            htmlFor="media_cleanup_include_terminal"
                            className="flex items-start gap-2"
                        >
                            <Checkbox
                                id="media_cleanup_include_terminal"
                                className="mt-0.5"
                                checked={
                                    form.data.media_cleanup_include_terminal
                                }
                                onCheckedChange={(checked) =>
                                    form.setData(
                                        'media_cleanup_include_terminal',
                                        checked === true,
                                    )
                                }
                            />
                            <span className="text-sm leading-snug font-normal">
                                Also clean canceled, expired, and
                                permanently-failed content. Stories live ~24h on
                                WhatsApp; without this, only successfully sent
                                content is cleaned and dead-but-unsent files
                                keep using disk space.
                            </span>
                        </Label>
                        <InputError
                            message={form.errors.media_cleanup_include_terminal}
                        />
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Save storage settings
                    </Button>
                </form>

                <div className="mt-6 space-y-3 border-t pt-4">
                    <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-2">
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">
                                Eligible now
                            </dt>
                            <dd>
                                {storage.estimate.files} file(s) (
                                {formatBytes(storage.estimate.bytes)})
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">
                                Next scheduled run
                            </dt>
                            <dd>{formatWhen(storage.cleanup.next_run_at)}</dd>
                        </div>
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">Last run</dt>
                            <dd>{formatWhen(storage.cleanup.last_run_at)}</dd>
                        </div>
                        {storage.disk_usage.map((row) => (
                            <div
                                key={row.disk}
                                className="flex justify-between gap-4"
                            >
                                <dt className="text-muted-foreground">
                                    Disk “{row.disk}” usage
                                </dt>
                                <dd>
                                    {row.files} file(s) (
                                    {formatBytes(row.bytes)})
                                </dd>
                            </div>
                        ))}
                    </dl>

                    <div className="flex flex-wrap items-center gap-3">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={runCleanupNow}
                            disabled={running || !storage.cleanup.enabled}
                        >
                            {running && <Spinner />}
                            Run cleanup now
                        </Button>
                        {!storage.cleanup.enabled && (
                            <span className="text-xs text-muted-foreground">
                                Enable auto-cleanup first.
                            </span>
                        )}
                    </div>

                    {storage.recent_runs.length > 0 && (
                        <div className="space-y-1">
                            <p className="text-xs font-medium text-muted-foreground">
                                Recent cleanup runs
                            </p>
                            <ul className="space-y-1 text-xs text-muted-foreground">
                                {storage.recent_runs.map((run) => (
                                    <li key={run.id}>
                                        {formatWhen(run.finished_at)} ·{' '}
                                        {run.trigger} · {run.files_purged}{' '}
                                        file(s), {formatBytes(run.bytes_freed)}{' '}
                                        freed
                                        {run.files_failed > 0
                                            ? `, ${run.files_failed} failed`
                                            : ''}
                                        {run.capped
                                            ? ' (capped, continues next run)'
                                            : ''}
                                    </li>
                                ))}
                            </ul>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
