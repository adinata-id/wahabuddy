import { useForm } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
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
import { Spinner } from '@/components/ui/spinner';
import { update as updateRoute } from '@/routes/settings/preferences';
import type { AppPreferences } from '@/types/wahabuddy';

type PreferencesForm = AppPreferences & {
    broadcast_delay_min: number;
    broadcast_delay_max: number;
    broadcast_batch_size: number;
    broadcast_batch_pause: number;
};

export function PreferencesCard({
    preferences,
    timezones,
}: {
    preferences: AppPreferences;
    timezones: string[];
}) {
    const form = useForm<PreferencesForm>({ ...preferences });

    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2">
                    <SlidersHorizontal className="size-4" aria-hidden />{' '}
                    Preferences
                </CardTitle>
                <CardDescription>
                    Display timezone affects presentation and new schedule
                    input only; stored schedules stay in UTC. Pacing values are
                    read fresh each time work is scheduled.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(updateRoute.url(), {
                            preserveScroll: true,
                            onSuccess: () =>
                                toast.success('Preferences updated.'),
                        });
                    }}
                    className="space-y-4"
                >
                    <div className="grid gap-2 md:max-w-sm">
                        <Label htmlFor="display_timezone">Display timezone</Label>
                        <select
                            id="display_timezone"
                            value={form.data.display_timezone}
                            onChange={(e) =>
                                form.setData('display_timezone', e.target.value)
                            }
                            className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                        >
                            {timezones.map((tz) => (
                                <option key={tz} value={tz}>
                                    {tz}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.display_timezone} />
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="broadcast_delay_min">
                                Min delay (s)
                            </Label>
                            <Input
                                id="broadcast_delay_min"
                                type="number"
                                min={1}
                                value={form.data.broadcast_delay_min}
                                onChange={(e) =>
                                    form.setData(
                                        'broadcast_delay_min',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.broadcast_delay_min}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="broadcast_delay_max">
                                Max delay (s)
                            </Label>
                            <Input
                                id="broadcast_delay_max"
                                type="number"
                                min={1}
                                value={form.data.broadcast_delay_max}
                                onChange={(e) =>
                                    form.setData(
                                        'broadcast_delay_max',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.broadcast_delay_max}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="broadcast_batch_size">
                                Batch size
                            </Label>
                            <Input
                                id="broadcast_batch_size"
                                type="number"
                                min={1}
                                value={form.data.broadcast_batch_size}
                                onChange={(e) =>
                                    form.setData(
                                        'broadcast_batch_size',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.broadcast_batch_size}
                            />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="broadcast_batch_pause">
                                Batch pause (s)
                            </Label>
                            <Input
                                id="broadcast_batch_pause"
                                type="number"
                                min={1}
                                value={form.data.broadcast_batch_pause}
                                onChange={(e) =>
                                    form.setData(
                                        'broadcast_batch_pause',
                                        Number(e.target.value),
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.broadcast_batch_pause}
                            />
                        </div>
                    </div>

                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Save preferences
                    </Button>
                </form>
            </CardContent>
        </Card>
    );
}
