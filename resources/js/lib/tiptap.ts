import type { TiptapNode } from '@/types';

/**
 * Remove image nodes that do not point at one of this post's uploaded files,
 * e.g. images pasted from other web pages (no fileId) or deleted uploads.
 */
export function withoutUnknownImages(
    doc: TiptapNode,
    knownIds: number[],
): { doc: TiptapNode; removed: number } {
    let removed = 0;

    const walk = (node: TiptapNode): TiptapNode => ({
        ...node,
        content: node.content
            ?.filter((child) => {
                const keep =
                    child.type !== 'image' ||
                    knownIds.includes(Number(child.attrs?.fileId));

                if (!keep) {
                    removed++;
                }

                return keep;
            })
            .map(walk),
    });

    return { doc: walk(doc), removed };
}
