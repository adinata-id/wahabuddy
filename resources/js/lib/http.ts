/**
 * Small JSON fetch helper for endpoints that return plain JSON (connection
 * tests, uploads, polling) instead of Inertia visits. Uses the encrypted
 * XSRF-TOKEN cookie for CSRF, same as Inertia itself.
 */
export async function postJson<T = unknown>(
    url: string,
    body?: unknown,
): Promise<T> {
    const xsrf = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.split('=')
        .slice(1)
        .join('=');

    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            ...(xsrf ? { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) } : {}),
        },
        body: JSON.stringify(body ?? {}),
    });

    const data = (await response.json().catch(() => null)) as T | null;

    if (!response.ok) {
        throw new JsonError(
            `Request failed with status ${response.status}`,
            response.status,
            data,
        );
    }

    return data as T;
}

export class JsonError extends Error {
    status: number;
    data: unknown;

    constructor(message: string, status: number, data: unknown) {
        super(message);
        this.status = status;
        this.data = data;
    }
}
