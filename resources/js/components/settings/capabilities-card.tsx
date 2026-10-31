import { Badge } from '@/components/ui/badge';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { CapabilityInfo } from '@/types/wahabuddy';

const LABELS: Record<string, string> = {
    text_status: 'Text status',
    image_status: 'Image status',
    video_status: 'Video status',
    delete_status: 'Delete status',
    video_convert: 'Server-side video conversion',
    contacts: 'Contact sync',
    chats: 'Chat / group sync',
};

function capabilityClass(state: CapabilityInfo['state']): string {
    switch (state) {
        case 'supported':
            return 'bg-emerald-500/15 text-emerald-700 dark:text-emerald-400';
        case 'unsupported':
            return 'bg-red-500/15 text-red-700 dark:text-red-400';
        default:
            return 'bg-amber-500/15 text-amber-700 dark:text-amber-400';
    }
}

export function CapabilitiesCard({
    capabilities,
}: {
    capabilities: Record<string, CapabilityInfo>;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>WAHA capabilities</CardTitle>
                <CardDescription>
                    Derived from the documented engine matrix and stored
                    overrides. Unknown features are never simulated as working.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <ul className="grid gap-2 sm:grid-cols-2">
                    {Object.entries(capabilities).map(([key, info]) => (
                        <li
                            key={key}
                            className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                        >
                            <span>{LABELS[key] ?? key}</span>
                            <span className="flex items-center gap-1.5">
                                <Badge className={capabilityClass(info.state)}>
                                    {info.state}
                                </Badge>
                                <span className="text-xs text-muted-foreground">
                                    {info.source}
                                </span>
                            </span>
                        </li>
                    ))}
                </ul>
            </CardContent>
        </Card>
    );
}
