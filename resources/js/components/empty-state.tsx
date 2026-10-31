import type { LucideIcon } from 'lucide-react';
import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

export function EmptyState({
    icon: Icon,
    title,
    description,
    action,
    className,
}: {
    icon?: LucideIcon;
    title: string;
    description?: string;
    action?: React.ReactNode;
    className?: string;
}) {
    return (
        <Card className={cn('border-dashed', className)}>
            <CardContent className="flex flex-col items-center justify-center gap-3 py-12 text-center">
                {Icon ? <Icon className="size-10 text-muted-foreground/60" aria-hidden /> : null}
                <div className="space-y-1">
                    <p className="text-sm font-medium">{title}</p>
                    {description ? (
                        <p className="mx-auto max-w-md text-sm text-muted-foreground">
                            {description}
                        </p>
                    ) : null}
                </div>
                {action}
            </CardContent>
        </Card>
    );
}
