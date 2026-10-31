import type { Auth } from '@/types/auth';
import type { WahaStatus } from '@/types/wahabuddy';

declare module 'react' {
    interface InputHTMLAttributes<T> {
        passwordrules?: string;
    }
}

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            waha: WahaStatus;
            [key: string]: unknown;
        };
    }
}
