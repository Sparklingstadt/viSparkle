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
    hidden: boolean;
    hidden_reason: string | null;
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
    // null when the viewer is neither the author nor a supporter of the creator.
    like_count: number | null;
    liked: boolean;
    can_like: boolean;
    creator: CreatorSummary;
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

export type ShopProductSummary = {
    id: number;
    name: string;
    price: number;
    sold_out: boolean;
    cover_url: string | null;
};

export type ProductKind = 'physical' | 'digital';

export type CartLine = {
    id: number;
    product_id: number;
    kind: ProductKind;
    name: string;
    price: number;
    quantity: number;
    stock: number;
    listed: boolean;
    cover_url: string | null;
};

export type CartGroup = {
    creator: CreatorSummary;
    items: CartLine[];
    subtotal: number;
    shipping_fee: number;
    total: number;
    purchasable: boolean;
};

export type OrderStatusValue =
    | 'pending'
    | 'paid'
    | 'shipped'
    | 'canceled'
    | 'refunded';

export type OrderItemDownloads = {
    refusal: string | null;
    files: {
        id: number;
        original_name: string;
        size: number;
        url: string;
    }[];
};

export type OrderItemData = {
    id: number;
    product_name: string;
    product_kind?: ProductKind;
    unit_price: number;
    quantity: number;
    /** The buyer's files of a digital work (the buyer's order page only). */
    downloads?: OrderItemDownloads | null;
};
