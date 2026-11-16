/** The site's one creator, as config/visparkle.php describes them. */
export type CreatorProfile = {
    name: string;
    bio: string | null;
    avatar_url: string;
    header_url: string;
};

/** The plans and the viewer's own support, shared by the home and post pages. */
export type SupportPanel = {
    plans: PlanData[];
    isCreator: boolean;
    viewerSupportPrice: number | null;
    viewerPlanId: number | null;
    viewerSupportId: number | null;
    viewerSupportCancelling: boolean;
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

export type TiptapAttrs = Record<string, string | number | boolean | null>;

export type TiptapMark = {
    type: string;
    attrs?: TiptapAttrs;
};

export type TiptapNode = {
    type: string;
    attrs?: TiptapAttrs;
    content?: TiptapNode[];
    text?: string;
    marks?: TiptapMark[];
};

export type PostStatus = 'draft' | 'published';

export type PostFileData = {
    id: number;
    kind: 'image' | 'attachment';
    url: string;
    thumb_url: string;
    original_name: string;
    size: number;
};

export type EditorPost = {
    id: number;
    title: string;
    excerpt: string | null;
    body: TiptapNode | null;
    required_price: number;
    status: PostStatus;
    published_at: string | null;
    cover_file_id: number | null;
    images: PostFileData[];
    attachments: PostFileData[];
};

export type Paginated<T> = {
    data: T[];
    current_page: number;
    last_page: number;
    prev_page_url: string | null;
    next_page_url: string | null;
    total: number;
};

export type PostSummary = {
    id: number;
    title: string;
    excerpt: string | null;
    required_price: number;
    published_at: string | null;
    cover_thumb_url: string | null;
    locked: boolean;
};

export type PostAttachment = {
    id: number;
    original_name: string;
    size: number;
    url: string;
};

export type PostDetail = PostSummary & {
    updated_at: string;
    body?: TiptapNode | null;
    image_urls?: Record<string, string>;
    attachments?: PostAttachment[];
};
