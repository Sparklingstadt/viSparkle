export type CreatorSummary = {
    slug: string;
    display_name: string;
    avatar_url: string | null;
};

export type CreatorProfile = CreatorSummary & {
    bio: string | null;
    header_url: string | null;
};

export type PlanData = {
    id: number;
    name: string;
    description: string | null;
    price: number;
    cover_url: string | null;
    is_accepting: boolean;
    active_supporters_count: number;
};
