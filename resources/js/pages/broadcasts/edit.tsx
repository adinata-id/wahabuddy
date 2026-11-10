import { useEffect, useMemo, useRef, useState } from 'react';
import { Head, useForm } from '@inertiajs/react';
import { Users, Upload } from 'lucide-react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import broadcastsRoutes from '@/routes/broadcasts';
import type { MediaFileItem } from '@/types/wahabuddy';

/**
 * Step 1 of the wizard: campaign identity + template. Recipients are picked
 * on the review step after this draft exists.
 */
export default function BroadcastsEdit({
    broadcast,
    recent_media: recentMedia,
}: {
    broadcast: {
        id: number;
        name: string;
        type: 'text' | 'image';
        template_text: string;
        media: { url: string | null; original_name: string } | null;
        media_file_id: number | null;
    };
    recent_media: MediaFileItem[];
}) {
    const form = useForm<{
        name: string;
        type: 'text' | 'image';
        template_text: string;
        media_file_id: string;
    }>({
        name: broadcast.name,
        type: broadcast.type,
        template_text: broadcast.template_text,
        media_file_id: broadcast.media_file_id
            ? String(broadcast.media_file_id)
            : '',
    });

    const [media, setMedia] = useState<MediaFileItem | null>(
        broadcast.media
            ? {
                  id: broadcast.media_file_id ?? 0,
                  original_name: broadcast.media.original_name,
                  mime_type: 'image/jpeg',
                  video_codec: null,
                  url: broadcast.media.url,
              }
            : null,
    );
    const [uploading, setUploading] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    const images = useMemo(
        () => recentMedia.filter((item) => item.mime_type.startsWith('image/')),
        [recentMedia],
    );

    const preview = useMemo(
        () => form.data.template_text.replaceAll('{name}', 'Andi'),
        [form.data.template_text],
    );

    const upload = async (file: File) => {
        setUploading(true);
        try {
            const body = new FormData();
            body.append('file', file);

            const xsrf = document.cookie
                .split('; ')
                .find((row) => row.startsWith('XSRF-TOKEN='))
                ?.split('=')
                .slice(1)
                .join('=');

            const response = await fetch('/media', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    ...(xsrf
                        ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) }
                        : {}),
                },
                body,
            });

            const payload = await response.json();

            if (!response.ok) {
                toast.error(payload?.errors?.file?.[0] ?? 'Upload failed.');

                return;
            }

            setMedia(payload);
            form.setData('media_file_id', String(payload.id));
            toast.success('Media uploaded.');
        } catch {
            toast.error('Upload failed.');
        } finally {
            setUploading(false);
        }
    };

    useEffect(() => {
        if (form.data.type !== 'image') {
            form.setData('media_file_id', '');
            setMedia(null);
        }
    }, [form.data.type]);

    return (
        <>
            <Head title="Edit broadcast" />
            <div className="flex h-full flex-1 flex-col gap-4">
                <Heading
                    title="Edit broadcast"
                    description="Only {name} is supported as a placeholder; recipients are reviewed on the next step."
                />

                <form
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.transform((data) => ({
                            ...data,
                            media_file_id:
                                data.type === 'image' && media
                                    ? media.id
                                    : null,
                        }));
                        form.put(
                            broadcastsRoutes.update.url({
                                broadcast: broadcast.id,
                            }),
                            { onSuccess: () => toast.success('Draft saved.') },
                        );
                    }}
                    className="space-y-4"
                >
                    <Card>
                        <CardContent className="space-y-4">
                            <div className="grid gap-2 md:max-w-sm">
                                <Label htmlFor="broadcast-name">Name</Label>
                                <Input
                                    id="broadcast-name"
                                    value={form.data.name}
                                    onChange={(e) =>
                                        form.setData('name', e.target.value)
                                    }
                                    placeholder="October promo"
                                    required
                                />
                                <InputError message={form.errors.name} />
                            </div>

                            <div className="grid gap-2 md:max-w-xs">
                                <Label htmlFor="broadcast-type">Type</Label>
                                <Select
                                    value={form.data.type}
                                    onValueChange={(value) =>
                                        form.setData(
                                            'type',
                                            value as 'text' | 'image',
                                        )
                                    }
                                >
                                    <SelectTrigger id="broadcast-type">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="text">
                                            Text message
                                        </SelectItem>
                                        <SelectItem value="image">
                                            Image + caption
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="broadcast-template">
                                    Template{' '}
                                    <span className="text-muted-foreground">
                                        ({'{name}'} supported, max 4096)
                                    </span>
                                </Label>
                                <Textarea
                                    id="broadcast-template"
                                    value={form.data.template_text}
                                    onChange={(e) =>
                                        form.setData(
                                            'template_text',
                                            e.target.value,
                                        )
                                    }
                                    rows={3}
                                />
                                <p className="text-xs text-muted-foreground">
                                    Preview for Andi: <strong>{preview}</strong>
                                </p>
                                <InputError
                                    message={form.errors.template_text}
                                />
                            </div>

                            {form.data.type === 'image' ? (
                                <div className="grid gap-2">
                                    <Label>Image</Label>
                                    <div className="flex flex-wrap items-center gap-3">
                                        <input
                                            ref={fileInput}
                                            type="file"
                                            accept="image/jpeg,image/png,image/webp"
                                            className="hidden"
                                            onChange={(e) => {
                                                const file =
                                                    e.target.files?.[0];
                                                if (file) void upload(file);
                                            }}
                                        />
                                        <Button
                                            type="button"
                                            variant="secondary"
                                            disabled={uploading}
                                            onClick={() =>
                                                fileInput.current?.click()
                                            }
                                        >
                                            {uploading ? (
                                                <Spinner />
                                            ) : (
                                                <Upload className="size-4" />
                                            )}
                                            Upload image
                                        </Button>
                                        {media ? (
                                            media.url !== null ? (
                                                <img
                                                    src={media.url}
                                                    alt={media.original_name}
                                                    className="size-14 rounded-md border object-cover"
                                                />
                                            ) : (
                                                <div className="flex size-14 items-center justify-center rounded-md border border-dashed text-center text-[10px] leading-tight text-muted-foreground">
                                                    File cleaned up
                                                </div>
                                            )
                                        ) : images.length > 0 ? (
                                            <Select
                                                value={
                                                    form.data.media_file_id ||
                                                    undefined
                                                }
                                                onValueChange={(value) => {
                                                    form.setData(
                                                        'media_file_id',
                                                        value,
                                                    );
                                                    setMedia(
                                                        images.find(
                                                            (item) =>
                                                                String(
                                                                    item.id,
                                                                ) === value,
                                                        ) ?? null,
                                                    );
                                                }}
                                            >
                                                <SelectTrigger className="w-64">
                                                    <SelectValue placeholder="Pick a recent upload" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {images.map((item) => (
                                                        <SelectItem
                                                            key={item.id}
                                                            value={String(
                                                                item.id,
                                                            )}
                                                        >
                                                            {item.original_name}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        ) : null}
                                    </div>
                                    <InputError
                                        message={form.errors.media_file_id}
                                    />
                                </div>
                            ) : null}
                        </CardContent>
                    </Card>

                    <Button type="submit" disabled={form.processing}>
                        {form.processing && <Spinner />}
                        Save draft
                    </Button>
                </form>
            </div>
        </>
    );
}

BroadcastsEdit.layout = {
    breadcrumbs: [
        {
            title: 'Broadcasts',
            href: broadcastsRoutes.index(),
        },
        { title: 'Edit' },
    ],
};
