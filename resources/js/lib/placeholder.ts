const PLACEHOLDER_COUNT = 5;

/**
 * Cover image for a post without one: one of five templates, picked from the post id so the same post always
 * shows the same image (a hash, so neighbouring posts don't simply cycle 1→2→3).
 */
export function placeholderCover(postId: number): string {
    const hash = Math.imul(postId ^ 0x9e3779b9, 0x85ebca6b) >>> 0;

    return `/images/post-placeholders/${(hash % PLACEHOLDER_COUNT) + 1}.webp`;
}
