/**
 * Timezone-aware formatting helpers. The backend persists UTC; the display
 * timezone (default Asia/Jakarta) comes from shared props or page props.
 */
export function formatInTimeZone(
    isoDate: string | null | undefined,
    timeZone: string,
    options?: Intl.DateTimeFormatOptions,
): string {
    if (!isoDate) {
        return '—';
    }

    const date = new Date(isoDate);

    if (Number.isNaN(date.getTime())) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-GB', {
        timeZone,
        year: 'numeric',
        month: 'short',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
        ...options,
    }).format(date);
}

export function formatDateTime(
    isoDate: string | null | undefined,
    timeZone: string,
): string {
    return formatInTimeZone(isoDate, timeZone, { second: undefined });
}

export function shortTimezoneLabel(timeZone: string): string {
    try {
        const formatted = new Intl.DateTimeFormat('en-GB', {
            timeZone,
            timeZoneName: 'short',
        }).formatToParts(new Date());
        return (
            formatted.find((part) => part.type === 'timeZoneName')?.value ??
            timeZone
        );
    } catch {
        return timeZone;
    }
}
