import { useState } from 'react';
import { useForm, router } from '@inertiajs/react';
import { HardDrive, PlugZap, ShieldAlert } from 'lucide-react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { postJson } from '@/lib/http';
import { test as testRoute } from '@/routes/settings/connection';
import { testStorage as testStorageRoute } from '@/routes/media';
import { update as updateRoute } from '@/routes/settings/connection';
import { clearApiKey as clearApiKeyRoute } from '@/routes/settings/connection';
import { clearBasicAuth as clearBasicAuthRoute } from '@/routes/settings/connection';
import type { ConnectionCheckResult, ConnectionSettings } from '@/types/wahabuddy';

type ConnectionForm = {
    base_url: string;
    session: string;
    api_key: string;
    basic_username: string;
    basic_password: string;
};

export function ConnectionCard({ connection }: { connection: ConnectionSettings }) {
    const [check, setCheck] = useState<ConnectionCheckResult | null>(null);
    const [testing, setTesting] = useState(false);
    const [testingStorage, setTestingStorage] = useState(false);

    const form = useForm<ConnectionForm>({
        base_url: connection.base_url ?? '',
        session: connection.session,
        api_key: '',
        basic_username: connection.basic_username ?? '',
        basic_password: '',
    });

    const runTest = async () => {
        setTesting(true);
        setCheck(null);

        try {
            const result = await postJson<ConnectionCheckResult>(
                testRoute.url(),
                form.data,
            );
            setCheck(result);

            if (result.working) {
                toast.success('Connection test passed — session is WORKING.');
            } else {
                toast.error(result.error ?? 'Connection test failed.');
            }
        } catch {
            toast.error('Connection test could not be completed.');
        } finally {
            setTesting(false);
        }
    };

    const runStorageTest = async () => {
        setTestingStorage(true);

        try {
            const result = await postJson<{
                reachable: boolean;
                status: number | null;
                note: string;
            }>(testStorageRoute.url());

            if (result.reachable) {
                toast.success(
                    'Storage probe passed (from this backend only).',
                );
            } else {
                toast.error(
                    `Storage probe failed${result.status ? ` (HTTP ${result.status})` : ''}.`,
                );
            }
        } catch {
            toast.error('Storage probe could not be completed.');
        } finally {
            setTestingStorage(false);
        }
    };

    const clearSecret = (
        label: string,
        url: string,
        status: string,
    ) => {
        if (
            !window.confirm(
                `Remove the stored ${label}? This changes the send target and will hold pending work until confirmed.`,
            )
        ) {
            return;
        }

        router.delete(url, {
            preserveScroll: true,
            onSuccess: () => toast.success(status),
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <PlugZap className="size-4" aria-hidden /> WAHA connection
                </CardTitle>
            </CardHeader>
            <CardContent className="space-y-4">
                {connection.revision > 1 && (
                    <p className="text-xs text-muted-foreground">
                        Connection target revision {connection.revision}. Work
                        created before a target change stays held until you
                        confirm the new target.
                    </p>
                )}

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(updateRoute.url(), {
                            preserveScroll: true,
                            onSuccess: () =>
                                toast.success('Connection settings saved.'),
                        });
                    }}
                    className="space-y-4"
                >
                    <div className="grid gap-2">
                        <Label htmlFor="base_url">Base URL</Label>
                        <Input
                            id="base_url"
                            type="url"
                            value={form.data.base_url}
                            onChange={(e) => form.setData('base_url', e.target.value)}
                            placeholder="http://waha.example.test"
                        />
                        <InputError message={form.errors.base_url} />
                        <p className="text-xs text-muted-foreground">
                            Origin plus optional path prefix, no trailing slash.
                            WAHA must be able to reach this app&apos;s media
                            URLs from its own network.
                        </p>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="session">Session</Label>
                        <Input
                            id="session"
                            value={form.data.session}
                            onChange={(e) => form.setData('session', e.target.value)}
                            placeholder="default"
                        />
                        <InputError message={form.errors.session} />
                        <p className="text-xs text-muted-foreground">
                            An existing WAHA session name. WahaBuddy never
                            creates or restarts sessions.
                        </p>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="api_key">API key</Label>
                        <Input
                            id="api_key"
                            type="password"
                            autoComplete="new-password"
                            value={form.data.api_key}
                            onChange={(e) => form.setData('api_key', e.target.value)}
                            placeholder={
                                connection.has_api_key
                                    ? `Stored (${connection.api_key_hint}). Leave blank to keep.`
                                    : 'Optional, if WAHA requires X-Api-Key'
                            }
                        />
                        <InputError message={form.errors.api_key} />
                        {connection.has_api_key && (
                            <Button
                                type="button"
                                variant="ghost"
                                size="sm"
                                className="w-fit text-destructive"
                                onClick={() =>
                                    clearSecret(
                                        'API key',
                                        clearApiKeyRoute.url(),
                                        'API key removed.',
                                    )
                                }
                            >
                                Remove stored API key
                            </Button>
                        )}
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="basic_username">
                                Basic username (optional)
                            </Label>
                            <Input
                                id="basic_username"
                                value={form.data.basic_username}
                                onChange={(e) =>
                                    form.setData('basic_username', e.target.value)
                                }
                                placeholder="Only for proxies guarding the API"
                            />
                            <InputError message={form.errors.basic_username} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="basic_password">
                                Basic password (optional)
                            </Label>
                            <Input
                                id="basic_password"
                                type="password"
                                autoComplete="new-password"
                                value={form.data.basic_password}
                                onChange={(e) =>
                                    form.setData('basic_password', e.target.value)
                                }
                                placeholder={
                                    connection.has_basic_password
                                        ? 'Stored. Leave blank to keep.'
                                        : 'Only for proxies guarding the API'
                                }
                            />
                            <InputError message={form.errors.basic_password} />
                        </div>
                    </div>

                    <div className="flex flex-wrap items-center gap-2">
                        <Button type="submit" disabled={form.processing}>
                            {form.processing && <Spinner />}
                            Save connection
                        </Button>
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={testing}
                            onClick={runTest}
                        >
                            {testing && <Spinner />}
                            Test connection
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={testingStorage}
                            onClick={runStorageTest}
                        >
                            {testingStorage ? (
                                <Spinner />
                            ) : (
                                <HardDrive className="size-4" />
                            )}
                            Test storage
                        </Button>
                        {connection.has_basic_password && (
                            <Button
                                type="button"
                                variant="ghost"
                                className="text-destructive"
                                onClick={() =>
                                    clearSecret(
                                        'Basic auth credentials',
                                        clearBasicAuthRoute.url(),
                                        'Basic auth removed.',
                                    )
                                }
                            >
                                Remove Basic auth
                            </Button>
                        )}
                    </div>
                </form>

                {check && <TestResult check={check} />}

                {!check && connection.last_test && (
                    <div className="rounded-lg border bg-muted/40 p-3 text-xs text-muted-foreground">
                        Last saved-config test:{' '}
                        <Badge
                            className={
                                connection.last_test.status === 'working'
                                    ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                                    : 'bg-red-500/15 text-red-700 dark:text-red-400'
                            }
                        >
                            {connection.last_test.status ?? 'unknown'}
                        </Badge>{' '}
                        <span className="ml-1">
                            {new Date(connection.last_test.at).toLocaleString()}
                        </span>
                        {connection.last_test.error && (
                            <p className="mt-1">{connection.last_test.error}</p>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function TestResult({ check }: { check: ConnectionCheckResult }) {
    return (
        <div className="space-y-2 rounded-lg border p-3 text-sm">
            <div className="flex flex-wrap items-center gap-2">
                <Badge
                    className={
                        check.working
                            ? 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400'
                            : 'bg-red-500/15 text-red-700 dark:text-red-400'
                    }
                >
                    {check.working ? 'WORKING' : (check.sessionStatus ?? 'failed')}
                </Badge>
                {check.sessionName && <span>{check.sessionName}</span>}
                {check.me?.chatId && (
                    <span className="text-xs text-muted-foreground">
                        {check.me.chatId}
                        {check.me.name ? ` · ${check.me.name}` : ''}
                    </span>
                )}
                <span className="ml-auto text-xs text-muted-foreground">
                    {new Date(check.testedAt).toLocaleTimeString()}
                </span>
            </div>
            <dl className="grid grid-cols-2 gap-x-4 gap-y-1 text-xs text-muted-foreground sm:grid-cols-4">
                <div>
                    <dt className="inline">Engine: </dt>
                    <dd className="inline">{check.engine ?? 'unknown'}</dd>
                </div>
                <div>
                    <dt className="inline">Version: </dt>
                    <dd className="inline">{check.wahaVersion ?? 'unknown'}</dd>
                </div>
                <div className="col-span-2">
                    <dt className="inline">Result storage: </dt>
                    <dd className="inline">
                        {check.persisted
                            ? 'saved with current configuration'
                            : 'not saved (unsaved form values)'}
                    </dd>
                </div>
            </dl>
            {check.error && (
                <p className="flex items-start gap-2 text-xs text-red-600 dark:text-red-400">
                    <ShieldAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden />
                    {check.error}
                </p>
            )}
        </div>
    );
}
