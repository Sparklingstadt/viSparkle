import { Head, Link, router, useForm } from '@inertiajs/react';
import { Lock, Paperclip, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import PostController from '@/actions/App/Http/Controllers/Creator/PostController';
import PostFileController from '@/actions/App/Http/Controllers/Creator/PostFileController';
import InputError from '@/components/input-error';
import { PostEditor } from '@/components/tiptap/post-editor';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatBytes, formatYen } from '@/lib/format';
import { withoutUnknownImages } from '@/lib/tiptap';
import { deletePostFile, uploadPostFile } from '@/lib/upload';
import { cn } from '@/lib/utils';
import { index } from '@/routes/creator/posts';
import type { EditorPost, PostFileData, TiptapNode } from '@/types';

type Action = 'save' | 'publish' | 'unpublish';

type FormData = {
    title: string;
    excerpt: string;
    body: TiptapNode | null;
    required_price: number;
    cover_file_id: number | null;
    post_category_id: number | '';
};

export default function PostEdit({
    post,
    audiences,
    categories,
    creatorSlug,
}: {
    post: EditorPost;
    audiences: { price: number; plans: string[]; readers: number | null }[];
    categories: { id: number; name: string; archived: boolean }[];
    creatorSlug: string;
}) {
    const form = useForm<FormData>({
        title: post.title,
        excerpt: post.excerpt ?? '',
        body: post.body,
        required_price: post.required_price,
        cover_file_id: post.cover_file_id,
        post_category_id: post.category_id ?? '',
    });
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
    const filesUrl = PostFileController.store.url(post.id);

    const upload = async (
        kind: PostFileData['kind'],
        file: File,
    ): Promise<PostFileData | null> => {
        setUploading((n) => n + 1);

        try {
            return await uploadPostFile(filesUrl, kind, file);
        } catch (error) {
            toast.error(
                `${file.name}: ${error instanceof Error ? error.message : 'アップロードに失敗しました。'}`,
            );

            return null;
        } finally {
            setUploading((n) => n - 1);
        }
    };

    const submit = (action: Action) => {
        const cleaned = form.data.body
            ? withoutUnknownImages(
                  form.data.body,
                  images.map((image) => image.id),
              )
            : null;

        if (cleaned && cleaned.removed > 0) {
            toast.warning(
                `アップロードされていない画像を${cleaned.removed}件取り除きました。画像は「画像を挿入」から追加してください。`,
            );
        }

        form.transform((data) => ({
            ...data,
            body: cleaned ? cleaned.doc : data.body,
            action,
        }));
        form.put(PostController.update.url(post.id), {
            preserveScroll: true,
            onSuccess: () => {
                setUploadedImages([]);
                setUploadedAttachments([]);
                setRemovedIds([]);
            },
        });
    };

    const removeAttachment = async (file: PostFileData) => {
        if (!confirm(`${file.original_name} を削除しますか？`)) {
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

    const busy = form.processing || uploading > 0;

    return (
        <>
            <Head title={`編集: ${post.title}`} />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 pt-0">
                {post.hidden && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            運営により非公開になっています（理由:{' '}
                            {post.hidden_reason}）
                        </AlertDescription>
                    </Alert>
                )}
                <div className="sticky top-0 z-10 -mx-4 flex flex-wrap items-center justify-between gap-2 border-b bg-background/95 px-4 py-3 backdrop-blur">
                    <div className="flex items-center gap-2">
                        <Badge
                            variant={
                                post.status === 'published'
                                    ? 'default'
                                    : 'secondary'
                            }
                        >
                            {post.status === 'published' ? '公開中' : '下書き'}
                        </Badge>
                        {post.status === 'published' && (
                            <Link
                                href={`/@${creatorSlug}/posts/${post.id}`}
                                className="text-sm underline underline-offset-4"
                            >
                                公開ページを見る
                            </Link>
                        )}
                    </div>
                    <div className="flex flex-wrap gap-2">
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => submit('save')}
                        >
                            {post.status === 'published'
                                ? '更新する'
                                : '下書き保存'}
                        </Button>
                        {post.status === 'published' ? (
                            <Button
                                variant="secondary"
                                disabled={busy}
                                onClick={() => submit('unpublish')}
                            >
                                非公開にする
                            </Button>
                        ) : (
                            <Button
                                disabled={busy}
                                onClick={() => submit('publish')}
                            >
                                公開する
                            </Button>
                        )}
                    </div>
                </div>

                <div className="grid gap-8 lg:grid-cols-[minmax(0,1fr)_20rem]">
                    <div className="min-w-0 space-y-6">
                        <div className="grid gap-2">
                            <Label htmlFor="title" className="sr-only">
                                タイトル
                            </Label>
                            <Input
                                id="title"
                                className="h-auto border-0 px-0 text-3xl font-black shadow-none focus-visible:ring-0 md:text-3xl"
                                placeholder="タイトル"
                                value={form.data.title}
                                maxLength={100}
                                onChange={(e) =>
                                    form.setData('title', e.target.value)
                                }
                            />
                            <InputError message={form.errors.title} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="excerpt">
                                冒頭（支援していない人にも見えます）
                            </Label>
                            <textarea
                                id="excerpt"
                                value={form.data.excerpt}
                                maxLength={300}
                                rows={3}
                                onChange={(e) =>
                                    form.setData('excerpt', e.target.value)
                                }
                                className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                            />
                            <InputError message={form.errors.excerpt} />
                        </div>

                        <div className="grid gap-2">
                            <Label className="flex items-center gap-2 rounded-xl border border-dashed border-primary/40 bg-secondary px-3 py-2 text-primary">
                                <Lock className="size-4" />
                                ここから下は「読める人」で選んだ支援者だけが読めます
                            </Label>
                            <PostEditor
                                initialContent={post.body}
                                imageUrls={imageUrls}
                                onChange={(doc) => form.setData('body', doc)}
                                onUploadImage={async (file) => {
                                    const uploaded = await upload(
                                        'image',
                                        file,
                                    );

                                    if (!uploaded) {
                                        return null;
                                    }

                                    setUploadedImages((list) => [
                                        ...list,
                                        uploaded,
                                    ]);

                                    return {
                                        id: uploaded.id,
                                        url: uploaded.url,
                                    };
                                }}
                            />
                            {uploading > 0 && (
                                <p className="text-sm text-muted-foreground">
                                    アップロード中…
                                </p>
                            )}
                            <InputError message={form.errors.body} />
                        </div>

                        <section className="grid gap-2">
                            <Label htmlFor="attachment">
                                添付ファイル（支援者のみ）
                            </Label>
                            <ul className="space-y-1">
                                {attachments.map((file) => (
                                    <li
                                        key={file.id}
                                        className="flex items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <span className="flex min-w-0 items-center gap-2">
                                            <Paperclip className="size-4 shrink-0" />
                                            <span className="truncate">
                                                {file.original_name}
                                            </span>
                                            <span className="shrink-0 text-muted-foreground">
                                                {formatBytes(file.size)}
                                            </span>
                                        </span>
                                        <Button
                                            size="icon"
                                            variant="ghost"
                                            aria-label="削除"
                                            onClick={() =>
                                                void removeAttachment(file)
                                            }
                                        >
                                            <Trash2 />
                                        </Button>
                                    </li>
                                ))}
                            </ul>
                            <Input
                                id="attachment"
                                type="file"
                                multiple
                                onChange={async (e) => {
                                    const files = Array.from(
                                        e.target.files ?? [],
                                    );
                                    e.target.value = '';

                                    for (const file of files) {
                                        const uploaded = await upload(
                                            'attachment',
                                            file,
                                        );

                                        if (uploaded) {
                                            setUploadedAttachments((list) => [
                                                ...list,
                                                uploaded,
                                            ]);
                                        }
                                    }
                                }}
                            />
                        </section>
                    </div>
                    <aside className="space-y-6">
                        <fieldset className="grid gap-2">
                            <legend className="mb-2 text-sm font-bold">
                                読める人
                            </legend>
                            {audiences.map((audience) => (
                                <label
                                    key={audience.price}
                                    className={cn(
                                        'flex cursor-pointer items-center gap-3 rounded-2xl px-4 py-3 transition-colors',
                                        form.data.required_price ===
                                            audience.price
                                            ? 'border-2 border-primary bg-secondary'
                                            : 'border hover:border-primary/50',
                                    )}
                                >
                                    <input
                                        type="radio"
                                        name="required_price"
                                        value={audience.price}
                                        checked={
                                            form.data.required_price ===
                                            audience.price
                                        }
                                        onChange={() =>
                                            form.setData(
                                                'required_price',
                                                audience.price,
                                            )
                                        }
                                        className="size-4 accent-primary"
                                    />
                                    <span className="min-w-0 flex-1">
                                        <span className="block text-sm font-bold">
                                            {audience.price === 0
                                                ? '全体公開'
                                                : `${audience.plans.length > 0 ? `${audience.plans.join('・')} ` : ''}${formatYen(audience.price)} 以上`}
                                        </span>
                                        <span className="block text-xs text-muted-foreground">
                                            {audience.readers === null
                                                ? '誰でも読めます'
                                                : `支援中の ${audience.readers} 人が読めます`}
                                        </span>
                                    </span>
                                </label>
                            ))}
                            <InputError message={form.errors.required_price} />
                        </fieldset>

                        {categories.length > 0 && (
                            <div className="grid gap-2">
                                <Label htmlFor="post_category_id">
                                    カテゴリ
                                </Label>
                                <select
                                    id="post_category_id"
                                    value={form.data.post_category_id}
                                    onChange={(e) =>
                                        form.setData(
                                            'post_category_id',
                                            e.target.value === ''
                                                ? ''
                                                : Number(e.target.value),
                                        )
                                    }
                                    className="h-9 max-w-xs rounded-md border border-input bg-transparent px-3 text-sm shadow-xs"
                                >
                                    <option value="">未設定</option>
                                    {categories.map((category) => (
                                        <option
                                            key={category.id}
                                            value={category.id}
                                        >
                                            {category.name}
                                            {category.archived &&
                                                '（受付終了）'}
                                        </option>
                                    ))}
                                </select>
                                <InputError
                                    message={form.errors.post_category_id}
                                />
                            </div>
                        )}

                        <section className="grid gap-2">
                            <Label>カバー画像（本文中の画像から選択）</Label>
                            <p className="text-xs text-muted-foreground">
                                カバー画像の縮小版は、支援していない人にも表示されます（一覧ではぼかし表示）。限定公開したい画像は選ばないでください。
                            </p>
                            {images.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    本文に画像を追加すると選択できます。
                                </p>
                            ) : (
                                <div className="grid grid-cols-3 gap-2">
                                    <button
                                        type="button"
                                        onClick={() =>
                                            form.setData('cover_file_id', null)
                                        }
                                        className={cn(
                                            'flex aspect-video items-center justify-center rounded-lg border text-xs',
                                            form.data.cover_file_id === null &&
                                                'ring-2 ring-primary',
                                        )}
                                    >
                                        なし
                                    </button>
                                    {images.map((image) => (
                                        <button
                                            key={image.id}
                                            type="button"
                                            onClick={() =>
                                                form.setData(
                                                    'cover_file_id',
                                                    image.id,
                                                )
                                            }
                                            className={cn(
                                                'aspect-video overflow-hidden rounded-lg border',
                                                form.data.cover_file_id ===
                                                    image.id &&
                                                    'ring-2 ring-primary',
                                            )}
                                        >
                                            <img
                                                src={image.thumb_url}
                                                alt=""
                                                className="size-full object-cover"
                                            />
                                        </button>
                                    ))}
                                </div>
                            )}
                            <InputError message={form.errors.cover_file_id} />
                        </section>

                        <div className="border-t pt-4">
                            <Button
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                disabled={busy}
                                onClick={() => {
                                    if (
                                        confirm(
                                            'この投稿を削除しますか？添付ファイルも削除されます。',
                                        )
                                    ) {
                                        router.delete(
                                            PostController.destroy.url(post.id),
                                        );
                                    }
                                }}
                            >
                                投稿を削除
                            </Button>
                        </div>
                    </aside>
                </div>
            </div>
        </>
    );
}

PostEdit.layout = {
    breadcrumbs: [{ title: '投稿管理', href: index() }],
};
