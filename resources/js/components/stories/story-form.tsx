import { useRef, useState } from 'react';
import { useForm } from '@inertiajs/react';
import { ImagePlus, Upload } from 'lucide-react';
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
import { postJson } from '@/lib/http';
import { shortTimezoneLabel } from '@/lib/date';
import { store as storeRoute } from '@/routes/stories';
import { update as updateRoute } from '@/routes/stories';
import type { MediaFileItem, StoryDetail, StoryType } from '@/types/wahabuddy';

type Props = {
    story?: StoryDetail | null;
    recentMedia: MediaFileItem[];
    displayTimezone: string;
};

type FormShape = {
    type: StoryType;
    text: string;
    background_color: string;
    font: number;
    media_file_id: string;
    scheduled_at: string;
    timezone: string;
};

export function StoryForm({ story, recentMedia, displayTimezone }: Props) {
    const [media, setMedia] = useState<MediaFileItem | null>(
        story?.media
            ? {
                  id: story.media.id,
                  original_name: story.media.original_name,
                  mime_type: story.media.mime_type,
                  video_codec: story.media.video_codec,
                  url: story.media.url,
              }
            : null,
    );
    const [uploading, setUploading] = useState(false);
    const fileInput = useRef<HTMLInputElement>(null);

    const form = useForm<FormShape>({
        type: story?.type ?? 'text',
        text: story?.text ?? '',
        background_color: story?.background_color ?? '#38b42f',
        font: story?.font ?? 1,
        media_file_id: story?.media ? String(story.media.id) : '',
        scheduled_at: '',
        timezone: displayTimezone,
    });

    const type = form.data.type;
    const isMedia = type === 'image' || type === 'video';
    const textLabel = type === 'text' ? 'Text' : 'Caption';
    const textLimit = type === 'text' ? 700 : 500;

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
                const message = payload?.errors?.file?.[0] ?? 'Upload failed.';
                toast.error(message);

                return;
            }

            setMedia(payload);
            form.setData('media_file_id', String(payload.id));
            toast.success('Media uploaded.');
        } catch {
            toast.error('Upload failed.');
        } finally {
            setUploading(false);
            if (fileInput.current) {
                fileInput.current.value = '';
            }
        }
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();

        form.transform((data) => ({
            ...data,
            media_file_id: isMedia && media ? media.id : null,
            text: data.text || null,
        }));

        form.post(
            story ? updateRoute.url({ story: story.id }) : storeRoute.url(),
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        story ? 'Story updated.' : 'Story scheduled.',
                    ),
            },
        );
    };

    return (
        <form onSubmit={submit} className="space-y-6">
            <Card>
                <CardContent className="space-y-4">
                    <div className="grid gap-2 md:max-w-xs">
                        <Label htmlFor="story-type">Type</Label>
                        <Select
                            value={type}
                            onValueChange={(value) =>
                                form.setData('type', value as StoryType)
                            }
                            disabled={Boolean(story)}
                        >
                            <SelectTrigger id="story-type">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="text">
                                    Text status
                                </SelectItem>
                                <SelectItem value="image">
                                    Image status
                                </SelectItem>
                                <SelectItem value="video">
                                    Video status
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="story-text">
                            {textLabel}{' '}
                            <span className="text-muted-foreground">
                                (max {textLimit})
                            </span>
                        </Label>
                        <Textarea
                            id="story-text"
                            value={form.data.text}
                            onChange={(e) =>
                                form.setData('text', e.target.value)
                            }
                            rows={4}
                            maxLength={textLimit}
                            placeholder={
                                type === 'text'
                                    ? 'Status text'
                                    : 'Optional caption'
                            }
                        />
                        <p className="text-xs text-muted-foreground">
                            {form.data.text.length}/{textLimit}
                        </p>
                        <InputError message={form.errors.text} />
                    </div>

                    {type === 'text' ? (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="story-color">
                                    Background color
                                </Label>
                                <div className="flex items-center gap-2">
                                    <input
                                        id="story-color"
                                        type="color"
                                        value={form.data.background_color}
                                        onChange={(e) =>
                                            form.setData(
                                                'background_color',
                                                e.target.value,
                                            )
                                        }
                                        className="size-9 cursor-pointer rounded-md border border-input"
                                        aria-label="Background color"
                                    />
                                    <Input
                                        value={form.data.background_color}
                                        onChange={(e) =>
                                            form.setData(
                                                'background_color',
                                                e.target.value,
                                            )
                                        }
                                        className="w-32 font-mono text-sm"
                                    />
                                </div>
                                <InputError
                                    message={form.errors.background_color}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="story-font">Font</Label>
                                <Select
                                    value={String(form.data.font)}
                                    onValueChange={(value) =>
                                        form.setData('font', Number(value))
                                    }
                                >
                                    <SelectTrigger id="story-font">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {[0, 1, 2, 3, 4, 5, 6].map((font) => (
                                            <SelectItem
                                                key={font}
                                                value={String(font)}
                                            >
                                                Font {font}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={form.errors.font} />
                            </div>
                        </div>
                    ) : (
                        <div className="grid gap-2">
                            <Label>Media</Label>
                            <div className="flex flex-wrap items-center gap-3">
                                <input
                                    ref={fileInput}
                                    type="file"
                                    accept={
                                        type === 'image'
                                            ? 'image/jpeg,image/png,image/webp'
                                            : 'video/mp4'
                                    }
                                    className="hidden"
                                    onChange={(e) => {
                                        const file = e.target.files?.[0];
                                        if (file) void upload(file);
                                    }}
                                />
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={uploading}
                                    onClick={() => fileInput.current?.click()}
                                >
                                    {uploading ? (
                                        <Spinner />
                                    ) : (
                                        <Upload className="size-4" />
                                    )}
                                    Upload {type}
                                </Button>
                                {media ? (
                                    <span className="flex items-center gap-2 text-sm">
                                        {media.mime_type.startsWith(
                                            'image/',
                                        ) ? (
                                            <img
                                                src={media.url ?? undefined}
                                                alt={media.original_name}
                                                className="size-12 rounded-md border object-cover"
                                            />
                                        ) : (
                                            <ImagePlus
                                                className="size-6 text-muted-foreground"
                                                aria-hidden
                                            />
                                        )}
                                        <span>
                                            {media.original_name}
                                            {media.video_codec === null &&
                                            media.mime_type === 'video/mp4' ? (
                                                <span className="block text-xs text-amber-600 dark:text-amber-400">
                                                    codec unknown — ensure H.264
                                                    MP4
                                                </span>
                                            ) : null}
                                        </span>
                                    </span>
                                ) : (
                                    <span className="text-sm text-muted-foreground">
                                        JPEG/PNG/WebP up to 50 MiB
                                    </span>
                                )}
                            </div>
                            <InputError message={form.errors.media_file_id} />
                            <p className="text-xs text-muted-foreground">
                                WAHA must be able to fetch this URL from its own
                                network. Video must already match the WhatsApp
                                format (H.264 MP4); conversion is not performed
                                locally.
                            </p>
                        </div>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="story-scheduled">
                                Scheduled at ({' '}
                                {shortTimezoneLabel(form.data.timezone)})
                            </Label>
                            <Input
                                id="story-scheduled"
                                type="datetime-local"
                                value={form.data.scheduled_at}
                                onChange={(e) =>
                                    form.setData('scheduled_at', e.target.value)
                                }
                                required
                            />
                            <InputError message={form.errors.scheduled_at} />
                            <p className="text-xs text-muted-foreground">
                                Interpreted in{' '}
                                <strong>{form.data.timezone}</strong>; stored as
                                UTC.
                            </p>
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="story-timezone">Timezone</Label>
                            <select
                                id="story-timezone"
                                value={form.data.timezone}
                                onChange={(e) =>
                                    form.setData('timezone', e.target.value)
                                }
                                className="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                            >
                                {Intl.supportedValuesOf('timeZone').map(
                                    (tz) => (
                                        <option key={tz} value={tz}>
                                            {tz}
                                        </option>
                                    ),
                                )}
                            </select>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div className="flex items-center gap-2 pb-6">
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Spinner />}
                    {story ? 'Save changes' : 'Schedule story'}
                </Button>
            </div>
        </form>
    );
}
