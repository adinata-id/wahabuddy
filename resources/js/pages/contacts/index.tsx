import { useState } from 'react';
import { Head, router, useForm } from '@inertiajs/react';
import { RefreshCcw, Search, Users } from 'lucide-react';
import { toast } from 'sonner';
import ConfirmDialog from '@/components/confirm-dialog';
import { EmptyState } from '@/components/empty-state';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import contactsRoutes from '@/routes/contacts';
import type { Pagination } from '@/types/wahabuddy';

type ContactRow = {
    id: number;
    chat_id: string;
    name: string | null;
    type: string;
    opted_in: boolean;
    opted_in_at: string | null;
    opted_out_at: string | null;
};

export default function Contacts({
    contacts,
    filters,
}: {
    contacts: Pagination<ContactRow>;
    filters: { search: string | null; type: string | null };
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [type, setType] = useState(filters.type ?? 'all');
    const consentForm = useForm<{ consent: 'in' | 'out' }>({ consent: 'in' });
    const syncForm = useForm({});
    const [syncing, setSyncing] = useState(false);
    const [optOutFor, setOptOutFor] = useState<ContactRow | null>(null);

    const applyFilters = () => {
        const query: Record<string, string> = {};
        if (search.trim()) query.search = search.trim();
        if (type !== 'all') query.type = type;

        router.get(contactsRoutes.index(), query, { preserveState: true });
    };

    const sync = async () => {
        setSyncing(true);

        try {
            const xsrf = document.cookie
                .split('; ')
                .find((row) => row.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=');

            const response = await fetch('/contacts/sync', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrf ? decodeURIComponent(xsrf) : '',
                },
            });

            const payload = await response.json();

            if (response.ok) {
                toast.success(payload.message ?? 'Sync finished.');
                router.reload({ only: ['contacts'] });
            } else {
                toast.error('Sync failed.');
            }
        } catch {
            toast.error('Sync failed.');
        } finally {
            setSyncing(false);
        }
    };

    const setConsent = (contact: ContactRow, consent: 'in' | 'out') => {
        consentForm.setData('consent', consent);
        consentForm.post(contactsRoutes.consent.url({ contact: contact.id }), {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    consent === 'in'
                        ? 'Opt-in recorded.'
                        : 'Opt-out recorded; the contact will be excluded.',
                ),
        });
    };

    return (
        <>
            <Head title="Contacts" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <div className="flex flex-wrap items-end justify-between gap-3">
                    <Heading
                        title="Contacts"
                        description="Synced from WAHA. Syncing never implies consent — opt-in is explicit per recipient."
                    />
                    <Button
                        className="mb-8"
                        disabled={syncing}
                        onClick={() => void sync()}
                    >
                        <RefreshCcw
                            className={
                                syncing ? 'size-4 animate-spin' : 'size-4'
                            }
                        />
                        Sync from WAHA
                    </Button>
                </div>

                <Card>
                    <CardContent className="flex flex-wrap items-end gap-3 py-4">
                        <div className="grid gap-1.5">
                            <span className="text-xs text-muted-foreground">
                                Search
                            </span>
                            <div className="relative">
                                <Search className="absolute top-2.5 left-2 size-4 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(event) =>
                                        setSearch(event.target.value)
                                    }
                                    onKeyDown={(event) => {
                                        if (event.key === 'Enter') {
                                            applyFilters();
                                        }
                                    }}
                                    placeholder="Name or chat id…"
                                    className="w-64 pl-8"
                                />
                            </div>
                        </div>
                        <div className="grid gap-1.5">
                            <span className="text-xs text-muted-foreground">
                                Type
                            </span>
                            <Select
                                value={type}
                                onValueChange={(value) => setType(value)}
                            >
                                <SelectTrigger className="w-32">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {['all', 'contact', 'group'].map(
                                        (option) => (
                                            <SelectItem
                                                key={option}
                                                value={option}
                                            >
                                                {option}
                                            </SelectItem>
                                        ),
                                    )}
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            variant="secondary"
                            type="button"
                            onClick={applyFilters}
                        >
                            Apply
                        </Button>
                    </CardContent>
                </Card>

                {contacts.data.length === 0 ? (
                    <EmptyState
                        icon={Users}
                        title="No contacts"
                        description="Run a sync to pull contacts and groups from your connected WAHA session."
                    />
                ) : (
                    <Card className="pb-2">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Name</TableHead>
                                    <TableHead>Chat id</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Consent</TableHead>
                                    <TableHead />
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {contacts.data.map((contact) => (
                                    <TableRow key={contact.id}>
                                        <TableCell className="text-sm">
                                            {contact.name ?? (
                                                <span className="text-muted-foreground">
                                                    —
                                                </span>
                                            )}
                                        </TableCell>
                                        <TableCell className="font-mono text-xs">
                                            {contact.chat_id}
                                        </TableCell>
                                        <TableCell>
                                            <span className="text-xs capitalize">
                                                {contact.type}
                                            </span>
                                        </TableCell>
                                        <TableCell>
                                            {contact.opted_in ? (
                                                <Badge className="bg-emerald-500/15 text-emerald-700 dark:text-emerald-400">
                                                    opted in
                                                </Badge>
                                            ) : contact.opted_out_at ? (
                                                <Badge className="bg-red-500/15 text-red-700 dark:text-red-400">
                                                    opted out
                                                </Badge>
                                            ) : (
                                                <Badge className="bg-amber-500/15 text-amber-700 dark:text-amber-400">
                                                    no consent
                                                </Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {contact.opted_in ? (
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    className="text-destructive"
                                                    disabled={
                                                        consentForm.processing
                                                    }
                                                    onClick={() =>
                                                        setOptOutFor(contact)
                                                    }
                                                >
                                                    Opt out
                                                </Button>
                                            ) : (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    disabled={
                                                        consentForm.processing
                                                    }
                                                    onClick={() =>
                                                        setConsent(
                                                            contact,
                                                            'in',
                                                        )
                                                    }
                                                >
                                                    Opt in
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <div className="flex items-center justify-between px-4 py-3 text-sm text-muted-foreground">
                            <span>
                                {contacts.total} ent
                                {contacts.total === 1 ? 'ry' : 'ries'} · page{' '}
                                {contacts.current_page} of {contacts.last_page}
                            </span>
                            <span className="flex gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={contacts.current_page <= 1}
                                    onClick={() =>
                                        router.get(
                                            contactsRoutes.index(),
                                            {
                                                page: contacts.current_page - 1,
                                            },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    Previous
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    disabled={
                                        contacts.current_page >=
                                        contacts.last_page
                                    }
                                    onClick={() =>
                                        router.get(
                                            contactsRoutes.index(),
                                            {
                                                page: contacts.current_page + 1,
                                            },
                                            { preserveState: true },
                                        )
                                    }
                                >
                                    Next
                                </Button>
                            </span>
                        </div>
                    </Card>
                )}

                <ConfirmDialog
                    open={optOutFor !== null}
                    onOpenChange={(open) => {
                        if (!open) setOptOutFor(null);
                    }}
                    title="Record an opt-out?"
                    description={`${
                        optOutFor?.name ?? optOutFor?.chat_id ?? 'This contact'
                    } will be excluded from all broadcasts.`}
                    confirmLabel="Record opt-out"
                    destructive
                    processing={consentForm.processing}
                    error={consentForm.errors.consent ?? null}
                    onConfirm={() => {
                        if (!optOutFor) {
                            return;
                        }

                        consentForm.setData('consent', 'out');
                        consentForm.post(
                            contactsRoutes.consent.url({
                                contact: optOutFor.id,
                            }),
                            {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setOptOutFor(null);
                                    toast.success(
                                        'Opt-out recorded; the contact will be excluded.',
                                    );
                                },
                            },
                        );
                    }}
                />
            </div>
        </>
    );
}

Contacts.layout = {
    breadcrumbs: [
        {
            title: 'Contacts',
            href: contactsRoutes.index(),
        },
    ],
};
