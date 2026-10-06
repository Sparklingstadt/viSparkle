export type CreatorSummary = {
    slug: string;
    display_name: string;
    avatar_url: string | null;
};

export type CreatorProfile = CreatorSummary & {
    bio: string | null;
    header_url: string | null;
};
