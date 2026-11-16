import { Head, Link, router, useForm } from '@inertiajs/react';
import { Lock } from 'lucide-react';
import { toast } from 'sonner';
import PostController from '@/actions/App/Http/Controllers/Creator/PostController';
import InputError from '@/components/input-error';
import { PostEditor } from '@/components/tiptap/post-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { withoutUnknownImages } from '@/lib/tiptap';
import { index } from '@/routes/creator/posts';
import { show } from '@/routes/posts';
import type { CategoryOption, EditorPost, TiptapNode } from '@/types';
import { useConfirm } from '@/components/confirm-dialog';
import { PostStatusBadge } from '@/components/post-status-badge';
import { Textarea } from '@/components/ui/textarea';
import { CategorySelect } from '@/components/category-select';
import { AttachmentList } from '@/components/creator/post-form/attachment-list';
import {
    AudiencePicker,
    type Audience,
} from '@/components/creator/post-form/audience-picker';
import { CoverPicker } from '@/components/creator/post-form/cover-picker';
import { usePostFiles } from '@/hooks/use-post-files';
import { HiddenByAdminAlert } from '@/components/hidden-by-admin';

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
    audiences: Audience[];
    categories: CategoryOption[];
    creatorSlug: string;
}) {
    const confirm = useConfirm();
    const form = useForm<FormData>({
        title: post.title,
        excerpt: post.excerpt ?? '',
        body: post.body,
        required_price: post.required_price,
        cover_file_id: post.cover_file_id,
        post_category_id: post.category_id ?? '',
    });
    const files = usePostFiles(post);

    const submit = (action: Action) => {
        const cleaned = form.data.body
            ? withoutUnknownImages(
                  form.data.body,
                  files.images.map((image) => image.id),
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
            onSuccess: files.reset,
        });
    };

    const busy = form.processing || files.uploading;

    return (
        <>
            <Head title={`編集: ${post.title}`} />

            <div className="mx-auto w-full max-w-6xl space-y-6 p-4 pt-0">
                {post.hidden && (
                    <HiddenByAdminAlert reason={post.hidden_reason} />
                )}
                <div className="sticky top-0 z-10 -mx-4 flex flex-wrap items-center justify-between gap-2 border-b bg-background/95 px-4 py-3 backdrop-blur">
                    <div className="flex items-center gap-2">
                        <PostStatusBadge status={post.status} />
                        {post.status === 'published' && (
                            <Link
                                href={show({
                                    creator: creatorSlug,
                                    post: post.id,
                                })}
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
                            <Textarea
                                id="excerpt"
                                value={form.data.excerpt}
                                maxLength={300}
                                rows={3}
                                onChange={(e) =>
                                    form.setData('excerpt', e.target.value)
                                }
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
                                imageUrls={files.imageUrls}
                                onChange={(doc) => form.setData('body', doc)}
                                onUploadImage={files.uploadImage}
                            />
                            {files.uploading && (
                                <p className="text-sm text-muted-foreground">
                                    アップロード中…
                                </p>
                            )}
                            <InputError message={form.errors.body} />
                        </div>

                        <AttachmentList
                            attachments={files.attachments}
                            onAdd={(added) =>
                                void files.uploadAttachments(added)
                            }
                            onRemove={(file) =>
                                void files.removeAttachment(file)
                            }
                        />
                    </div>
                    <aside className="space-y-6">
                        <AudiencePicker
                            audiences={audiences}
                            value={form.data.required_price}
                            onChange={(price) =>
                                form.setData('required_price', price)
                            }
                            error={form.errors.required_price}
                        />

                        {categories.length > 0 && (
                            <div className="grid gap-2">
                                <Label htmlFor="post_category_id">
                                    カテゴリ
                                </Label>
                                <CategorySelect
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
                                    className="max-w-xs"
                                    categories={categories}
                                />
                                <InputError
                                    message={form.errors.post_category_id}
                                />
                            </div>
                        )}

                        <CoverPicker
                            images={files.images}
                            value={form.data.cover_file_id}
                            onChange={(id) => form.setData('cover_file_id', id)}
                            error={form.errors.cover_file_id}
                        />

                        <div className="border-t pt-4">
                            <Button
                                variant="ghost"
                                className="text-destructive hover:text-destructive"
                                disabled={busy}
                                onClick={async () => {
                                    if (
                                        await confirm({
                                            title: 'この投稿を削除しますか？',
                                            description:
                                                '添付ファイルも削除されます。',
                                            confirmLabel: '削除',
                                            destructive: true,
                                        })
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
