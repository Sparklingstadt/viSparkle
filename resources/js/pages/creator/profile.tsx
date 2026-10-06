import { Form, Head, Link } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Creator/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/creator/profile';
import type { CreatorProfile } from '@/types';

export default function CreatorProfileEdit({
    creator,
}: {
    creator: CreatorProfile;
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
            </div>
        </>
    );
}

CreatorProfileEdit.layout = {
    breadcrumbs: [{ title: 'プロフィール編集', href: edit() }],
};
