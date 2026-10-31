import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

const STATUS_TONES: Record<string, string> = {
    pending: 'bg-slate-500/15 text-slate-700 dark:text-slate-300',
    sending: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    sent: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    running: 'bg-blue-500/15 text-blue-700 dark:text-blue-400',
    failed: 'bg-red-500/15 text-red-700 dark:text-red-400',
    failed_max: 'bg-red-600/20 text-red-800 dark:text-red-400',
    unknown: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    expired: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400',
    canceled: 'bg-zinc-500/15 text-zinc-600 dark:text-zinc-400',
    paused: 'bg-amber-500/15 text-amber-700 dark:text-amber-400',
    completed: 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400',
    queued: 'bg-violet-500/15 text-violet-700 dark:text-violet-400',
    draft: 'bg-slate-500/15 text-slate-600 dark:text-slate-400',
};

const LABELS: Record<string, string> = {
    failed_max: 'failed (max)',
    pending: 'pending',
    sending: 'sending',
    sent: 'sent',
    failed: 'failed',
    unknown: 'unknown',
    expired: 'expired',
    canceled: 'canceled',
    running: 'running',
    paused: 'paused',
    completed: 'completed',
    queued: 'queued',
    draft: 'draft',
};

export function StatusBadge({ status }: { status: string }) {
    return (
        <Badge className={cn(STATUS_TONES[status] ?? STATUS_TONES.pending)}>
            {LABELS[status] ?? status}
        </Badge>
    );
}

export function statusLabel(status: string): string {
    return LABELS[status] ?? status;
}
