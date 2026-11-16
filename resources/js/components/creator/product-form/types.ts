import type { ProductKind } from '@/types';

/** The product page's editable data, as Creator\ProductController::edit() sends it. */
export type ProductImage = { id: number; url: string };

export type WorkFile = {
    id: number;
    original_name: string;
    size: number;
    mime: string;
};

export type EditableVariant = {
    id: number;
    name: string | null;
    stock: number;
    reserved: number;
};

export type EditableProduct = {
    id: number;
    kind: ProductKind;
    name: string;
    description: string | null;
    price: number;
    category_id: number | null;
    variants: EditableVariant[];
    is_published: boolean;
    hidden: boolean;
    hidden_reason: string | null;
    images: ProductImage[];
    files: WorkFile[];
};
