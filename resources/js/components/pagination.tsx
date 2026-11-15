import { Link } from '@inertiajs/react';
import type { Paginated } from '@/types';

/**
 * Previous / next links under a paginated list.
 */
export function Pagination({
    page,
}: {
    page: Pick<Paginated<unknown>, 'prev_page_url' | 'next_page_url'>;
}) {
    return (
        <div className="flex justify-between">
            {page.prev_page_url ? (
                <Link href={page.prev_page_url}>← 前へ</Link>
            ) : (
                <span />
            )}
            {page.next_page_url && (
                <Link href={page.next_page_url}>次へ →</Link>
            )}
        </div>
    );
}
