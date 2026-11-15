import { Pill } from '@/components/pill';
import type { PostStatus } from '@/types';

export const POST_STATUS_LABEL: Record<PostStatus, string> = {
    published: '公開中',
    draft: '下書き',
};

export function PostStatusBadge({ status }: { status: PostStatus }) {
    return (
        <Pill tone={status === 'published' ? 'success' : 'muted'}>
            {POST_STATUS_LABEL[status]}
        </Pill>
    );
}
