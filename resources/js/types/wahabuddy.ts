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
