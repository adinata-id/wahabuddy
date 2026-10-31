/**
 * WahaBuddy domain types shared between pages and the backend (spec M).
 */

export type CapabilityState = 'supported' | 'unsupported' | 'unknown';

export type CapabilityInfo = {
    state: CapabilityState;
    source: string;
};

export type ConnectionSettings = {
    base_url: string | null;
    session: string;
    basic_username: string | null;
    has_api_key: boolean;
    api_key_hint: string | null;
    has_basic_password: boolean;
    revision: number;
    capabilities: Record<string, CapabilityInfo>;
    last_test: {
        at: string;
        status: string | null;
        error: string | null;
    } | null;
};

export type AppPreferences = {
    display_timezone: string;
    broadcast_delay_min: number;
    broadcast_delay_max: number;
    broadcast_batch_size: number;
    broadcast_batch_pause: number;
};

export type ConnectionCheckResult = {
    working: boolean;
    sessionStatus: string | null;
    sessionName: string | null;
    me: { chatId?: string; name?: string } | null;
    engine: string | null;
    wahaVersion: string | null;
    errorCode: string | null;
    error: string | null;
    testedAt: string;
    persisted: boolean;
};

/** Shared on every authenticated page via HandleInertiaRequests. */
export type WahaStatus = {
    configured: boolean;
    sessionName: string;
    lastTestStatus: string | null;
    lastTestAt: string | null;
    displayTimezone: string;
};

export type StoryType = 'text' | 'image' | 'video';

export type StoryStatus =
    | 'pending'
    | 'sending'
    | 'sent'
    | 'failed'
    | 'failed_max'
    | 'unknown'
    | 'expired'
    | 'canceled';

export type MediaFileItem = {
    id: number;
    original_name: string;
    mime_type: string;
    video_codec: string | null;
    url: string;
};

export type StoryMedia = {
    id: number;
    original_name: string;
    mime_type: string;
    video_codec: string | null;
    url: string;
};

export type StoryListItem = {
    id: number;
    type: StoryType;
    text: string | null;
    status: StoryStatus;
    attempts: number;
    scheduled_at: string;
    scheduled_at_display: string;
    display_timezone: string;
    last_error: string | null;
    thumbnail_url: string | null;
};

export type StoryDetail = {
    id: number;
    type: StoryType;
    text: string | null;
    background_color: string;
    font: number;
    status: StoryStatus;
    attempts: number;
    scheduled_at: string;
    scheduled_at_display: string;
    display_timezone: string;
    connection_revision: number;
    waha_message_id: string | null;
    waha_key_id: string | null;
    last_error_code: string | null;
    last_error: string | null;
    sent_at: string | null;
    sent_at_display: string | null;
    editable: boolean;
    media: StoryMedia | null;
};

export type StoryLogItem = {
    id: number;
    event: string;
    message: string | null;
    attempt_token: string | null;
    context: Record<string, unknown> | null;
    created_at: string | null;
    created_at_display: string | null;
};

export type Pagination<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    total: number;
    per_page?: number;
};

export type StoryFilters = {
    status: string | null;
    type: string | null;
    from: string | null;
    to: string | null;
};
