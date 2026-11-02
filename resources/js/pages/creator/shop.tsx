import { Form, Head } from '@inertiajs/react';
import ShopSettingsController from '@/actions/App/Http/Controllers/Creator/ShopSettingsController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/creator/shop';

export default function ShopSettings({
    shop,
}: {
    shop: {
        shipping_fee: number;
        shop_notice: string | null;
        shop_contact_email: string | null;
    };
}) {
    return (
        <>
            <Head title="ショップ設定" />

            <div className="max-w-xl space-y-6 p-4">
                <Heading
                    title="ショップ設定"
                    description="送料は1回のご注文ごとに加算されます。"
                />

                <Form
                    {...ShopSettingsController.update.form()}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="shipping_fee">送料（円）</Label>
                                <Input
                                    id="shipping_fee"
                                    name="shipping_fee"
                                    type="number"
                                    min={0}
                                    max={100000}
                                    step={1}
                                    defaultValue={shop.shipping_fee}
                                    required
                                />
                                <InputError message={errors.shipping_fee} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="shop_notice">
                                    ショップのお知らせ
                                </Label>
                                <textarea
                                    id="shop_notice"
                                    name="shop_notice"
                                    defaultValue={shop.shop_notice ?? ''}
                                    maxLength={2000}
                                    rows={5}
                                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                                />
                                <InputError message={errors.shop_notice} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="shop_contact_email">
                                    連絡先メールアドレス
                                </Label>
                                <Input
                                    id="shop_contact_email"
                                    name="shop_contact_email"
                                    type="email"
                                    defaultValue={shop.shop_contact_email ?? ''}
                                />
                                <InputError
                                    message={errors.shop_contact_email}
                                />
                            </div>
                            <Button disabled={processing}>保存</Button>
                        </>
                    )}
                </Form>
            </div>
        </>
    );
}

ShopSettings.layout = {
    breadcrumbs: [{ title: 'ショップ設定', href: edit() }],
};
