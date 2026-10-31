import type { ReactNode } from 'react';
import { TriangleAlertIcon } from 'lucide-react';
import { Button, buttonVariants } from '@/components/ui/button';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Spinner } from '@/components/ui/spinner';

/**
 * Confirmation dialog for consequence-bearing actions, replacing the native
 * window.confirm(). The dialog stays open until the caller closes it:
 * onConfirm fires the request, the caller closes on success, and failures
 * surface in place through the error prop.
 */
export default function ConfirmDialog({
    open,
    onOpenChange,
    title,
    description,
    confirmLabel,
    cancelLabel = 'Cancel',
    destructive = false,
    processing = false,
    confirmDisabled = false,
    warning = null,
    error = null,
    onConfirm,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    title: string;
    description?: ReactNode;
    confirmLabel: string;
    cancelLabel?: string;
    destructive?: boolean;
    processing?: boolean;
    confirmDisabled?: boolean;
    warning?: ReactNode;
    error?: string | null;
    onConfirm: () => void;
}) {
    return (
        <AlertDialog open={open} onOpenChange={onOpenChange}>
            <AlertDialogContent className="sm:max-w-md">
                <AlertDialogHeader>
                    {destructive ? (
                        <div className="flex size-10 items-center justify-center rounded-full bg-destructive/10">
                            <TriangleAlertIcon
                                className="size-5 text-destructive"
                                aria-hidden
                            />
                        </div>
                    ) : null}
                    <AlertDialogTitle>{title}</AlertDialogTitle>
                    {description ? (
                        <AlertDialogDescription>
                            {description}
                        </AlertDialogDescription>
                    ) : null}
                </AlertDialogHeader>
                {warning ? (
                    <p className="text-sm text-amber-700 dark:text-amber-400">
                        {warning}
                    </p>
                ) : null}
                {error ? (
                    <p className="text-sm font-medium text-destructive">
                        {error}
                    </p>
                ) : null}
                <AlertDialogFooter>
                    <AlertDialogCancel asChild>
                        <Button variant="secondary" disabled={processing}>
                            {cancelLabel}
                        </Button>
                    </AlertDialogCancel>
                    <AlertDialogAction
                        className={
                            destructive
                                ? buttonVariants({ variant: 'destructive' })
                                : undefined
                        }
                        disabled={processing || confirmDisabled}
                        onClick={(event) => {
                            // Prevent the dialog from closing so the caller
                            // controls when to dismiss (on success only).
                            event.preventDefault();
                            onConfirm();
                        }}
                    >
                        {processing ? <Spinner /> : null} {confirmLabel}
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
