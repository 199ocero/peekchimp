export type Appearance = 'light' | 'dark' | 'system';
export type ResolvedAppearance = 'light' | 'dark';

export type AppVariant = 'header' | 'sidebar';

export type FlashToast = {
    type: 'success' | 'info' | 'warning' | 'error';
    message: string;
};

export type WebsiteSummary = {
    id: number;
    name: string;
    domain: string | null;
};

export type WebsiteSwitcherData = {
    current: WebsiteSummary | null;
    items: Array<WebsiteSummary & { status: 'ready' | 'setup_required' }>;
};
