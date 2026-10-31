import { usePage } from '@inertiajs/react';
import { cn } from '@/lib/utils';

type Tone = 'green' | 'amber' | 'red' | 'gray';

const toneClasses: Record<Tone, string> = {
    green: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    amber: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    red: 'bg-red-500/15 text-red-700 dark:text-red-400',
    gray: 'bg-muted text-muted-foreground',
};

const dotClasses: Record<Tone, string> = {
    green: 'bg-emerald-500',
    amber: 'bg-amber-500',
    red: 'bg-red-500',
    gray: 'bg-muted-foreground/50',
};

/**
 * Top-bar connection summary driven by shared props: never a live probe, only
 * the last known configuration/test state.
 */
export function ConnectionStatusBadge() {
    const waha = usePage().props.waha;

    if (!waha) {
        return null;
    }

    let tone: Tone = 'gray';
    let label = 'Not configured';

    if (waha.configured) {
        if (waha.lastTestStatus === 'working') {
            tone = 'green';
            label = waha.sessionName;
        } else if (waha.lastTestStatus === 'failing') {
            tone = 'red';
            label = `${waha.sessionName} · unreachable`;
        } else {
            tone = 'amber';
            label = `${waha.sessionName} · untested`;
        }
    }

    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-medium',
                toneClasses[tone],
            )}
            title={
                waha.lastTestAt
                    ? `Last connection test: ${waha.lastTestAt}`
                    : 'No connection test has been run yet'
            }
        >
            <span
                className={cn('size-1.5 rounded-full', dotClasses[tone])}
                aria-hidden
            />
            <span className="max-w-[16ch] truncate">{label}</span>
        </span>
    );
}
