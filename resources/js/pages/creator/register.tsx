import { Form, Head } from '@inertiajs/react';
import CreatorRegistrationController from '@/actions/App/Http/Controllers/Creator/CreatorRegistrationController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { register } from '@/routes/creator';

export default function CreatorRegister() {
    return (
        <>
            <Head title="クリエイター登録" />

            <div className="mx-auto w-full max-w-xl space-y-6 p-4">
                <Heading
                    title="クリエイター登録"
                    description="あなたのページを作成して、ファンからの支援を受け付けましょう。"
                />

                <Form
                    {...CreatorRegistrationController.store.form()}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="slug">ページのURL</Label>
                                <div className="flex items-center gap-1 text-sm text-muted-foreground">
                                    <span>{window.location.origin}/@</span>
                                    <Input
                                        id="slug"
                                        name="slug"
                                        required
                                        autoComplete="off"
                                        placeholder="your-name"
                                        className="flex-1"
                                    />
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    半角英小文字・数字・ハイフンで3〜32文字。登録後は変更できません。
                                </p>
                                <InputError message={errors.slug} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="display_name">表示名</Label>
                                <Input
                                    id="display_name"
                                    name="display_name"
                                    required
                                    maxLength={50}
                                />
                                <InputError message={errors.display_name} />
                            </div>

                            <Button disabled={processing}>登録する</Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

CreatorRegister.layout = {
    breadcrumbs: [{ title: 'クリエイター登録', href: register() }],
};
