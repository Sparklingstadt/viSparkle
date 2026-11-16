import type { ComponentProps } from 'react';
import { cn } from '@/lib/utils';
import type { CategoryOption } from '@/types';

/**
 * The category of a post or a product: 「未設定」 plus the options from IsCategory::options(), where an archived
 * category the item still has is marked as no longer taking new items.
 */
export function CategorySelect({
    categories,
    className,
    ...props
}: ComponentProps<'select'> & { categories: CategoryOption[] }) {
    return (
        <select
            className={cn(
                'h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs',
                className,
            )}
            {...props}
        >
            <option value="">未設定</option>
            {categories.map((category) => (
                <option key={category.id} value={category.id}>
                    {category.name}
                    {category.archived && '（受付終了）'}
                </option>
            ))}
        </select>
    );
}
