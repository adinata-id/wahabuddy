import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { HardDrive, ShieldAlert } from 'lucide-react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
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
import {
    cleanupRun as cleanupRunRoute,
    clearSecret as clearSecretRoute,
    test as testStorageRoute,
    update as updateStorageRoute,
} from '@/routes/settings/storage';
import { update as updatePreferencesRoute } from '@/routes/settings/preferences';
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

type StorageConnectionForm = {
    enabled: boolean;
    key: string;
    secret: string;
    bucket: string;
    region: string;
    endpoint: string;
    use_path_style_endpoint: boolean;
};

type StorageForm = {
    media_cleanup_enabled: boolean;
    media_cleanup_frequency: StorageSettings['cleanup']['frequency'];
    media_cleanup_day: number | null;
    media_cleanup_interval_days: number | null;
    media_cleanup_retention_days: number;
    media_cleanup_include_terminal: boolean;
};

type StorageProbeResult = {
    reachable: boolean;
    status: number | null;
    persisted: boolean;
    note: string;
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
    const { connection } = storage;

    const [testing, setTesting] = useState(false);
    const [running, setRunning] = useState(false);
    const [clearingSecret, setClearingSecret] = useState(false);
    const [clearingBusy, setClearingBusy] = useState(false);

    const connectionForm = useForm<StorageConnectionForm>({
        enabled: connection.enabled,
        key: '',
        secret: '',
        bucket: connection.bucket ?? '',
        region: connection.region || 'auto',
        endpoint: connection.endpoint ?? '',
        use_path_style_endpoint: connection.use_path_style_endpoint,
    });

    const cleanupForm = useForm<StorageForm>({
        media_cleanup_enabled: storage.cleanup.enabled,
        media_cleanup_frequency: storage.cleanup.frequency,
        media_cleanup_day: storage.cleanup.day,
        media_cleanup_interval_days: storage.cleanup.interval_days,
        media_cleanup_retention_days: storage.cleanup.retention_days,
        media_cleanup_include_terminal: storage.cleanup.include_terminal,
    });

    /**
     * Blank key/secret mean "keep the stored value" — the browser never
     * receives them. Every other field is sent as-is.
     */
    const connectionPayload = (): Record<string, unknown> => {
        const payload: Record<string, unknown> = {
            enabled: connectionForm.data.enabled,
            bucket: connectionForm.data.bucket,
            region: connectionForm.data.region,
            endpoint: connectionForm.data.endpoint,
            use_path_style_endpoint:
                connectionForm.data.use_path_style_endpoint,
        };

        if (connectionForm.data.key !== '') {
            payload.key = connectionForm.data.key;
        }

        if (connectionForm.data.secret !== '') {
            payload.secret = connectionForm.data.secret;
        }

        return payload;
    };

    const runStorageTest = async () => {
        setTesting(true);

        try {
            const result = await postJson<StorageProbeResult>(
                testStorageRoute.url(),
                connectionPayload(),
            );

            if (result.reachable) {
                toast.success(
                    result.persisted
                        ? 'Storage test passed — result saved with these credentials.'
                        : 'Storage test passed (unsaved form values — save to keep them).',
                );
            } else {
                toast.error(
                    `Storage test failed${result.status ? ` (HTTP ${result.status})` : ''}.`,
                );
            }
        } catch {
            toast.error('Storage test could not be completed.');
        } finally {
            setTesting(false);
        }
    };

    // One Save, two requests: the connection slice and the cleanup slice
    // live on different endpoints; each gets its own toast.
    const submitAll = () => {
        connectionForm.put(updateStorageRoute.url(), {
            preserveScroll: true,
            onSuccess: () => toast.success('Storage connection saved.'),
            onError: () =>
                toast.error('Storage connection could not be saved.'),
        });

        cleanupForm.transform((data) => ({
            ...data,
            // Laravel's boolean rule wants 1/0, not "true"/"false" form
            // strings; the day is clamped for the chosen cadence so a
            // visible-but-null select can never submit.
            media_cleanup_enabled: data.media_cleanup_enabled ? 1 : 0,
            media_cleanup_include_terminal: data.media_cleanup_include_terminal
                ? 1
                : 0,
            media_cleanup_day:
                data.media_cleanup_frequency === 'monthly'
                    ? Math.min(28, Math.max(1, data.media_cleanup_day ?? 1))
                    : data.media_cleanup_frequency === 'weekly'
                      ? Math.min(6, data.media_cleanup_day ?? 1)
                      : data.media_cleanup_day,
        }));

        cleanupForm.put(updatePreferencesRoute.url(), {
            preserveScroll: true,
            onSuccess: () => toast.success('Storage settings updated.'),
            onError: () => toast.error('Storage settings could not be saved.'),
        });
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
                        submitAll();
                    }}
                    className="space-y-4"
                >
                    {connection.credentials_unreadable && (
                        <div className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 p-3 text-xs text-destructive dark:text-red-400">
                            <ShieldAlert
                                className="mt-0.5 size-3.5 shrink-0"
                                aria-hidden
                            />
                            Stored credentials are unreadable (did APP_KEY
                            change?). Re-enter the access key and secret, then
                            save.
                        </div>
                    )}

                    <div className="grid gap-2 md:max-w-sm">
                        <Label
                            htmlFor="s3_enabled"
                            className="flex items-center gap-2"
                        >
                            <Checkbox
                                id="s3_enabled"
                                checked={connectionForm.data.enabled}
                                onCheckedChange={(checked) =>
                                    connectionForm.setData(
                                        'enabled',
                                        checked === true,
                                    )
                                }
                            />
                            S3 storage enabled
                        </Label>
                        <InputError message={connectionForm.errors.enabled} />
                        <p className="text-xs text-muted-foreground">
                            {connection.configured
                                ? 'Uploads go to the configured S3-compatible bucket; WAHA receives a fresh presigned URL for every send.'
                                : 'Media upload stays disabled until an access key, secret, and bucket are saved here.'}
                        </p>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="s3_key">Access key ID</Label>
                            <Input
                                id="s3_key"
                                type="password"
                                autoComplete="new-password"
                                value={connectionForm.data.key}
                                onChange={(e) =>
                                    connectionForm.setData(
                                        'key',
                                        e.target.value,
                                    )
                                }
                                placeholder={
                                    connection.has_key
                                        ? `Stored (${connection.key_hint}). Leave blank to keep.`
                                        : ''
                                }
                            />
                            <InputError message={connectionForm.errors.key} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="s3_secret">Secret access key</Label>
                            <Input
                                id="s3_secret"
                                type="password"
                                autoComplete="new-password"
                                value={connectionForm.data.secret}
                                onChange={(e) =>
                                    connectionForm.setData(
                                        'secret',
                                        e.target.value,
                                    )
                                }
                                placeholder={
                                    connection.has_secret
                                        ? 'Configured — leave empty to keep'
                                        : ''
                                }
                            />
                            <InputError
                                message={connectionForm.errors.secret}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="s3_bucket">Bucket</Label>
                            <Input
                                id="s3_bucket"
                                value={connectionForm.data.bucket}
                                onChange={(e) =>
                                    connectionForm.setData(
                                        'bucket',
                                        e.target.value,
                                    )
                                }
                                placeholder="wahabuddy-media"
                            />
                            <InputError
                                message={connectionForm.errors.bucket}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="s3_region">Region</Label>
                            <Input
                                id="s3_region"
                                value={connectionForm.data.region}
                                onChange={(e) =>
                                    connectionForm.setData(
                                        'region',
                                        e.target.value,
                                    )
                                }
                                placeholder="auto"
                            />
                            <InputError
                                message={connectionForm.errors.region}
                            />
                            <p className="text-xs text-muted-foreground">
                                Cloudflare R2 uses “auto”.
                            </p>
                        </div>
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="s3_endpoint">
                                Endpoint (optional)
                            </Label>
                            <Input
                                id="s3_endpoint"
                                type="url"
                                value={connectionForm.data.endpoint}
                                onChange={(e) =>
                                    connectionForm.setData(
                                        'endpoint',
                                        e.target.value,
                                    )
                                }
                                placeholder="https://<accountid>.r2.cloudflarestorage.com"
                            />
                            <InputError
                                message={connectionForm.errors.endpoint}
                            />
                            <p className="text-xs text-muted-foreground">
                                Leave empty for AWS S3. Cloudflare R2 needs the
                                account endpoint (
                                <code>
                                    https://&lt;accountid&gt;.r2.cloudflarestorage.com
                                </code>
                                ).
                            </p>
                        </div>
                        <div className="grid gap-2 md:col-span-2">
                            <Label
                                htmlFor="s3_path_style"
                                className="flex items-center gap-2 font-normal"
                            >
                                <Checkbox
                                    id="s3_path_style"
                                    checked={
                                        connectionForm.data
                                            .use_path_style_endpoint
                                    }
                                    onCheckedChange={(checked) =>
                                        connectionForm.setData(
                                            'use_path_style_endpoint',
                                            checked === true,
                                        )
                                    }
                                />
                                Path-style endpoint (required for Cloudflare R2)
                            </Label>
                            <InputError
                                message={
                                    connectionForm.errors
                                        .use_path_style_endpoint
                                }
                            />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={testing}
                            onClick={runStorageTest}
                        >
                            {testing && <Spinner />}
                            Test storage
                        </Button>
                        {connection.has_secret && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive"
                                onClick={() => setClearingSecret(true)}
                            >
                                Clear secret
                            </Button>
                        )}
                    </div>
                    <p className="text-xs text-muted-foreground">
                        Tests the values above (unsaved edits included) by
                        writing and fetching a tiny object from the bucket; run
                        an end-to-end story test to confirm the WAHA network can
                        reach it too.
                    </p>

                    {connection.last_test && (
                        <div className="rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
                            Last storage test:{' '}
                            <Badge
                                className={
                                    connection.last_test.status === 'passed'
                                        ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                                        : 'bg-red-500/15 text-red-700 dark:text-red-400'
                                }
                            >
                                {connection.last_test.status ?? 'unknown'}
                            </Badge>{' '}
                            <span className="ml-1">
                                {new Date(
                                    connection.last_test.at,
                                ).toLocaleString()}
                            </span>
                            {connection.last_test.error && (
                                <p className="mt-1">
                                    {connection.last_test.error}
                                </p>
                            )}
                        </div>
                    )}

                    <div className="grid gap-2 md:max-w-sm">
                        <Label
                            htmlFor="media_cleanup_enabled"
                            className="flex items-center gap-2"
                        >
                            <Checkbox
                                id="media_cleanup_enabled"
                                checked={cleanupForm.data.media_cleanup_enabled}
                                onCheckedChange={(checked) =>
                                    cleanupForm.setData(
                                        'media_cleanup_enabled',
                                        checked === true,
                                    )
                                }
                            />
                            Auto-cleanup enabled
                        </Label>
                        <InputError
                            message={cleanupForm.errors.media_cleanup_enabled}
                        />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="media_cleanup_frequency">
                                Frequency
                            </Label>
                            <select
                                id="media_cleanup_frequency"
                                value={cleanupForm.data.media_cleanup_frequency}
                                onChange={(e) => {
                                    const frequency = e.target
                                        .value as StorageForm['media_cleanup_frequency'];

                                    cleanupForm.setData(
                                        'media_cleanup_frequency',
                                        frequency,
                                    );

                                    // Re-clamp a stale day for the new
                                    // cadence (weekday 0-6 vs month 1-28).
                                    const day =
                                        cleanupForm.data.media_cleanup_day;

                                    if (frequency === 'monthly') {
                                        cleanupForm.setData(
                                            'media_cleanup_day',
                                            day !== null &&
                                                day >= 1 &&
                                                day <= 28
                                                ? day
                                                : 1,
                                        );
                                    } else if (day === null || day > 6) {
                                        cleanupForm.setData(
                                            'media_cleanup_day',
                                            1,
                                        );
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
                                message={
                                    cleanupForm.errors.media_cleanup_frequency
                                }
                            />
                        </div>

                        {cleanupForm.data.media_cleanup_frequency ===
                            'weekly' && (
                            <div className="grid gap-2">
                                <Label htmlFor="media_cleanup_day">
                                    Day of week
                                </Label>
                                <select
                                    id="media_cleanup_day"
                                    value={
                                        cleanupForm.data.media_cleanup_day ?? 1
                                    }
                                    onChange={(e) =>
                                        cleanupForm.setData(
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
                                    message={
                                        cleanupForm.errors.media_cleanup_day
                                    }
                                />
                            </div>
                        )}

                        {cleanupForm.data.media_cleanup_frequency ===
                            'monthly' && (
                            <div className="grid gap-2">
                                <Label htmlFor="media_cleanup_day">
                                    Day of month
                                </Label>
                                <select
                                    id="media_cleanup_day"
                                    value={
                                        cleanupForm.data.media_cleanup_day ?? 1
                                    }
                                    onChange={(e) =>
                                        cleanupForm.setData(
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
                                    message={
                                        cleanupForm.errors.media_cleanup_day
                                    }
                                />
                            </div>
                        )}

                        {cleanupForm.data.media_cleanup_frequency ===
                            'custom' && (
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
                                        cleanupForm.data
                                            .media_cleanup_interval_days ?? ''
                                    }
                                    onChange={(e) =>
                                        cleanupForm.setData(
                                            'media_cleanup_interval_days',
                                            e.target.value === ''
                                                ? null
                                                : Number(e.target.value),
                                        )
                                    }
                                />
                                <InputError
                                    message={
                                        cleanupForm.errors
                                            .media_cleanup_interval_days
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
                                value={
                                    cleanupForm.data
                                        .media_cleanup_retention_days
                                }
                                onChange={(e) =>
                                    cleanupForm.setData(
                                        'media_cleanup_retention_days',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError
                                message={
                                    cleanupForm.errors
                                        .media_cleanup_retention_days
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
                                    cleanupForm.data
                                        .media_cleanup_include_terminal
                                }
                                onCheckedChange={(checked) =>
                                    cleanupForm.setData(
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
                            message={
                                cleanupForm.errors
                                    .media_cleanup_include_terminal
                            }
                        />
                    </div>

                    <Button
                        type="submit"
                        disabled={
                            connectionForm.processing || cleanupForm.processing
                        }
                    >
                        {connectionForm.processing && <Spinner />}
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

                <ConfirmDialog
                    open={clearingSecret}
                    onOpenChange={(open) => {
                        if (!open) setClearingSecret(false);
                    }}
                    title="Remove the stored secret access key?"
                    description="Uploads and sends are rejected until a new secret is saved. Removal never deletes stored files."
                    confirmLabel="Clear secret"
                    destructive
                    processing={clearingBusy}
                    onConfirm={() => {
                        setClearingBusy(true);
                        router.delete(clearSecretRoute.url(), {
                            preserveScroll: true,
                            onSuccess: () => {
                                setClearingSecret(false);
                                toast.success('Storage secret removed.');
                            },
                            onError: () =>
                                toast.error('The secret could not be removed.'),
                            onFinish: () => setClearingBusy(false),
                        });
                    }}
                />
            </CardContent>
        </Card>
    );
}
