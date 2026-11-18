import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    balanceNanos: number | null;
    isAdmin: boolean;
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    flash: { success: string | null };
    auth: Auth;
    errors: Record<string, string>;
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export type BreadcrumbItemType = BreadcrumbItem;

export type GenerationStatus = 'queued' | 'processing' | 'succeeded' | 'failed';

export interface TokenUsage {
    input_text_tokens: number;
    input_image_tokens: number;
    output_tokens: number;
    reasoning_tokens: number;
    total_tokens?: number;
}

export interface Generation {
    id: string;
    status: GenerationStatus;
    status_detail: string | null;
    prompt: string;
    size: string;
    quality: string;
    reference_images: string[];
    /** All money is integer nanodollars (1 USD = 1e9). */
    estimated_min_nanos: number;
    estimated_nanos: number;
    estimated_max_nanos: number;
    hold_nanos: number;
    charged_nanos: number | null;
    usage: TokenUsage | null;
    image_url: string | null;
    image_expired: boolean;
    error_code: string | null;
    error_message: string | null;
    created_at: string;
    completed_at: string | null;
}

/** GET /v1/pricing from Pixelforge, passed through unchanged. */
export interface Pricing {
    rates: Record<'input_text_tokens' | 'input_image_tokens' | 'output_tokens' | 'reasoning_tokens', string>;
    tokens: {
        characters_per_text_token: number;
        text_token_variance: number;
        per_reference_image: number;
        output: Record<string, Record<string, number>>;
        reasoning: Record<string, { min: number; max: number }>;
    };
}

/** A Laravel API-resource paginator. */
export interface ResourcePage<T> {
    data: T[];
    links: { prev: string | null; next: string | null };
    meta: { current_page: number; last_page: number; total: number };
}

/** A plain Laravel length-aware paginator. */
export interface Page<T> {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
}
