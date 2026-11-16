import { router } from '@inertiajs/react';

/**
 * Swaps the items at `from` and `to` and saves the new order of ids at `url` (a reorder route taking `ids`).
 */
export function saveSwapped(
    ids: number[],
    from: number,
    to: number,
    url: string,
) {
    const next = [...ids];
    [next[from], next[to]] = [next[to], next[from]];
    router.put(url, { ids: next }, { preserveScroll: true });
}
