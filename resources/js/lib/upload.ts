import type { PostFileData } from '@/types';

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
}

export class UploadError extends Error {}

async function request(url: string, init: RequestInit): Promise<Response> {
    const response = await fetch(url, {
        ...init,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': xsrfToken(),
        },
    });

    if (!response.ok) {
        const body = (await response.json().catch(() => null)) as {
            message?: string;
            errors?: Record<string, string[]>;
        } | null;
        const message =
            Object.values(body?.errors ?? {})[0]?.[0] ??
            body?.message ??
            'アップロードに失敗しました。';

        throw new UploadError(message);
    }

    return response;
}

export async function uploadPostFile(
    url: string,
    kind: PostFileData['kind'],
    file: File,
): Promise<PostFileData> {
    const form = new FormData();
    form.append('kind', kind);
    form.append('file', file);

    const response = await request(url, { method: 'POST', body: form });

    return (await response.json()) as PostFileData;
}

export async function deletePostFile(url: string): Promise<void> {
    await request(url, { method: 'DELETE' });
}
