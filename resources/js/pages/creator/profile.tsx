import { Form, Head, Link } from '@inertiajs/react';
import ClosureController from '@/actions/App/Http/Controllers/Creator/ClosureController';
import ProfileController from '@/actions/App/Http/Controllers/Creator/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/creator/profile';
import type { CreatorProfile } from '@/types';

export default function CreatorProfileEdit({
    creator,
}: {
    creator: CreatorProfile & { closed: boolean };
}) {
    return (
        <>
            <Head title="プロフィール編集" />

            <div className="mx-auto w-full max-w-2xl space-y-6 p-4">
                <Heading
                    title="プロフィール編集"
                    description={`ページURL: /@${creator.slug}`}
                />

                <Link
                    href={`/@${creator.slug}`}
                    className="text-sm underline underline-offset-4"
                >
                    自分のページを見る
                </Link>

                <Form
                    {...ProfileController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="display_name">表示名</Label>
                                <Input
                                    id="display_name"
                                    name="display_name"
                                    defaultValue={creator.display_name}
                                    required
                                    maxLength={50}
                                />
                                <InputError message={errors.display_name} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="bio">自己紹介</Label>
                                <textarea
                                    id="bio"
                                    name="bio"
                                    defaultValue={creator.bio ?? ''}
                                    maxLength={2000}
                                    rows={6}
                                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                                />
                                <InputError message={errors.bio} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="avatar">アイコン</Label>
                                {creator.avatar_url && (
                                    <img
                                        src={creator.avatar_url}
                                        alt=""
                                        className="size-20 rounded-full object-cover"
                                    />
                                )}
                                <Input
                                    id="avatar"
                                    name="avatar"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                />
                                <InputError message={errors.avatar} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="header">ヘッダー画像</Label>
                                {creator.header_url && (
                                    <img
                                        src={creator.header_url}
                                        alt=""
                                        className="aspect-[3/1] w-full rounded-md object-cover"
                                    />
                                )}
                                <Input
                                    id="header"
                                    name="header"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                />
                                <InputError message={errors.header} />
                            </div>

                            <Button disabled={processing}>保存する</Button>
                        </>
                    )}
                </Form>

                <ClosureSection closed={creator.closed} />
            </div>
        </>
    );
}

function ClosureSection({ closed }: { closed: boolean }) {
    return (
        <div className="space-y-4 border-t pt-6">
            <Heading
                variant="small"
                title="活動の終了"
                description="新しい支援の受付を止め、支援者の支援を期間の終わりで停止します"
            />

            {closed ? (
                <div className="space-y-4">
                    <p className="text-sm">活動を終了しています。</p>
                    <Form
                        {...ClosureController.destroy.form()}
                        options={{ preserveScroll: true }}
                    >
                        {({ processing }) => (
                            <Button variant="secondary" disabled={processing}>
                                活動を再開する
                            </Button>
                        )}
                    </Form>
                </div>
            ) : (
                <Dialog>
                    <DialogTrigger asChild>
                        <Button variant="destructive">活動を終了する</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>活動を終了しますか？</DialogTitle>
                        <DialogDescription>
                            活動を終了すると、新しい支援の受付を止め、すべての支援を次回のお支払い日で停止します。
                        </DialogDescription>

                        <Form
                            {...ClosureController.store.form()}
                            options={{ preserveScroll: true }}
                        >
                            {({ processing }) => (
                                <DialogFooter className="gap-2">
                                    <DialogClose asChild>
                                        <Button
                                            type="button"
                                            variant="secondary"
                                        >
                                            キャンセル
                                        </Button>
                                    </DialogClose>
                                    <Button
                                        variant="destructive"
                                        disabled={processing}
                                    >
                                        活動を終了する
                                    </Button>
                                </DialogFooter>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            )}
        </div>
    );
}

CreatorProfileEdit.layout = {
    breadcrumbs: [{ title: 'プロフィール編集', href: edit() }],
};
