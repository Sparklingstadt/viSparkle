import { useState } from 'react';
import { toast } from 'sonner';
import PostFileController from '@/actions/App/Http/Controllers/Creator/PostFileController';
import { useConfirm } from '@/components/confirm-dialog';
import { deletePostFile, uploadPostFile } from '@/lib/upload';
import type { EditorPost, PostFileData } from '@/types';

/**
 * The images and attachments of a post being edited: the saved ones plus those uploaded or removed since the last
 * save. Call `reset()` once a save succeeds (the post's props then hold them).
 */
export function usePostFiles(post: EditorPost) {
    const confirm = useConfirm();
    const [uploadedImages, setUploadedImages] = useState<PostFileData[]>([]);
    const [uploadedAttachments, setUploadedAttachments] = useState<
        PostFileData[]
    >([]);
    const [removedIds, setRemovedIds] = useState<number[]>([]);
    const [uploading, setUploading] = useState(0);

    const images = [...post.images, ...uploadedImages];
    const attachments = [...post.attachments, ...uploadedAttachments].filter(
        (file) => !removedIds.includes(file.id),
    );
    const imageUrls = Object.fromEntries(
        images.map((image) => [image.id, image.url]),
    );

    const upload = async (
        kind: PostFileData['kind'],
        file: File,
    ): Promise<PostFileData | null> => {
        setUploading((n) => n + 1);

        try {
            return await uploadPostFile(
                PostFileController.store.url(post.id),
                kind,
                file,
            );
        } catch (error) {
            toast.error(
                `${file.name}: ${error instanceof Error ? error.message : 'アップロードに失敗しました。'}`,
            );

            return null;
        } finally {
            setUploading((n) => n - 1);
        }
    };

    /** An image added in the editor: its id and URL, or null when it failed (the toast says why). */
    const uploadImage = async (file: File) => {
        const uploaded = await upload('image', file);

        if (!uploaded) {
            return null;
        }

        setUploadedImages((list) => [...list, uploaded]);

        return { id: uploaded.id, url: uploaded.url };
    };

    /** Attachments are uploaded one after another; a failed one is skipped. */
    const uploadAttachments = async (files: File[]) => {
        for (const file of files) {
            const uploaded = await upload('attachment', file);

            if (uploaded) {
                setUploadedAttachments((list) => [...list, uploaded]);
            }
        }
    };

    const removeAttachment = async (file: PostFileData) => {
        if (
            !(await confirm({
                title: `${file.original_name} を削除しますか？`,
                confirmLabel: '削除',
                destructive: true,
            }))
        ) {
            return;
        }

        try {
            await deletePostFile(
                PostFileController.destroy.url({
                    post: post.id,
                    file: file.id,
                }),
            );
            setRemovedIds((ids) => [...ids, file.id]);
        } catch (error) {
            toast.error(
                error instanceof Error ? error.message : '削除に失敗しました。',
            );
        }
    };

    const reset = () => {
        setUploadedImages([]);
        setUploadedAttachments([]);
        setRemovedIds([]);
    };

    return {
        images,
        attachments,
        imageUrls,
        uploading: uploading > 0,
        uploadImage,
        uploadAttachments,
        removeAttachment,
        reset,
    };
}
