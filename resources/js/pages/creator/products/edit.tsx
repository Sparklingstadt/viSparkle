import { Form, Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import ProductController from '@/actions/App/Http/Controllers/Creator/ProductController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as earnings } from '@/routes/creator/earnings';
import { index } from '@/routes/creator/products';
import type { CategoryOption, ProductKind } from '@/types';
import { useConfirm } from '@/components/confirm-dialog';
import { Textarea } from '@/components/ui/textarea';
import { CategorySelect } from '@/components/category-select';
import { FilesSection } from '@/components/creator/product-form/files-section';
import { ImagesSection } from '@/components/creator/product-form/images-section';
import type { EditableProduct } from '@/components/creator/product-form/types';
import { VariantsField } from '@/components/creator/product-form/variants-field';

export default function ProductEdit({
    product,
    categories,
    can_publish: canPublish,
    files_max: filesMax,
    file_max_kb: fileMaxKb,
}: {
    product: EditableProduct | null;
    categories: CategoryOption[];
    can_publish: boolean;
    files_max: number;
    file_max_kb: number;
}) {
    const confirm = useConfirm();
    // The kind is chosen once, when creating; an existing product only shows it.
    const [newKind, setNewKind] = useState<ProductKind>('physical');
    const kind = product?.kind ?? newKind;
    const digital = kind === 'digital';
    const title = product ? '商品を編集' : '商品を追加';
    const form = product
        ? ProductController.update.form(product.id)
        : ProductController.store.form();

    return (
        <>
            <Head title={title} />

            <div className="max-w-2xl space-y-8 p-4">
                <div className="flex items-center gap-2">
                    <Heading title={title} />
                    {product && (
                        <Badge variant="secondary" className="mb-8">
                            {digital ? 'デジタル作品' : 'グッズ'}
                        </Badge>
                    )}
                </div>

                {product?.hidden && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            運営により非表示になっています（理由:{' '}
                            {product.hidden_reason}）
                        </AlertDescription>
                    </Alert>
                )}

                {/* Keyed on the kinds and their stock too: after a save (or a sale) the rows and stock_seen start again from the current stock. */}
                <Form
                    key={
                        product
                            ? `${product.id}:${product.variants.map((v) => `${v.id}-${v.stock}`).join(',')}`
                            : 'new'
                    }
                    {...form}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            {!product && (
                                <fieldset className="grid gap-2">
                                    <legend className="mb-2 text-sm font-medium">
                                        売るもの
                                    </legend>
                                    <div className="flex gap-4">
                                        {(
                                            [
                                                ['physical', 'グッズ'],
                                                ['digital', 'デジタル作品'],
                                            ] as const
                                        ).map(([value, label]) => (
                                            <label
                                                key={value}
                                                className="flex items-center gap-2 text-sm"
                                            >
                                                <input
                                                    type="radio"
                                                    name="kind"
                                                    value={value}
                                                    checked={newKind === value}
                                                    onChange={() =>
                                                        setNewKind(value)
                                                    }
                                                />
                                                {label}
                                            </label>
                                        ))}
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {digital
                                            ? 'ファイルを売り、購入した人がダウンロードします。在庫はありません。作成後は変えられません。'
                                            : '発送する物の商品です。作成後は変えられません。'}
                                    </p>
                                    <InputError message={errors.kind} />
                                </fieldset>
                            )}
                            <div className="grid gap-2">
                                <Label htmlFor="name">商品名</Label>
                                <Input
                                    id="name"
                                    name="name"
                                    defaultValue={product?.name}
                                    maxLength={100}
                                    required
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="description">説明</Label>
                                <Textarea
                                    id="description"
                                    name="description"
                                    defaultValue={product?.description ?? ''}
                                    maxLength={5000}
                                    rows={6}
                                />
                                <InputError message={errors.description} />
                            </div>
                            <div className="grid max-w-xs gap-2">
                                <Label htmlFor="price">価格（円）</Label>
                                <Input
                                    id="price"
                                    name="price"
                                    type="number"
                                    min={50}
                                    max={1000000}
                                    step={1}
                                    defaultValue={product?.price}
                                    required
                                />
                                <InputError message={errors.price} />
                            </div>
                            {categories.length > 0 && (
                                <div className="grid max-w-xs gap-2">
                                    <Label htmlFor="product_category_id">
                                        カテゴリ
                                    </Label>
                                    <CategorySelect
                                        id="product_category_id"
                                        name="product_category_id"
                                        defaultValue={
                                            product?.category_id ?? ''
                                        }
                                        categories={categories}
                                    />
                                    <InputError
                                        message={errors.product_category_id}
                                    />
                                </div>
                            )}
                            {!digital && (
                                <VariantsField
                                    variants={product?.variants ?? []}
                                    errors={errors}
                                />
                            )}
                            <div className="grid gap-2">
                                <div className="flex items-center gap-2">
                                    <Checkbox
                                        id="is_published"
                                        name="is_published"
                                        value="1"
                                        defaultChecked={product?.is_published}
                                    />
                                    <Label htmlFor="is_published">
                                        公開する
                                    </Label>
                                </div>
                                {!canPublish && (
                                    <p className="text-xs text-muted-foreground">
                                        口座の登録が済むと公開できます。{' '}
                                        <Link
                                            href={earnings()}
                                            className="underline"
                                        >
                                            収益ページへ
                                        </Link>
                                    </p>
                                )}
                                <InputError message={errors.is_published} />
                            </div>
                            <Button disabled={processing}>
                                {product ? '保存' : '作成する'}
                            </Button>
                        </>
                    )}
                </Form>

                {product ? (
                    <>
                        {digital && (
                            <FilesSection
                                product={product}
                                filesMax={filesMax}
                                fileMaxKb={fileMaxKb}
                            />
                        )}
                        <ImagesSection product={product} />
                        <div className="border-t pt-6">
                            <Button
                                variant="destructive"
                                onClick={async () => {
                                    if (
                                        await confirm({
                                            title: 'この商品を削除しますか？',
                                            confirmLabel: '削除',
                                            destructive: true,
                                        })
                                    ) {
                                        router.delete(
                                            ProductController.destroy.url(
                                                product.id,
                                            ),
                                        );
                                    }
                                }}
                            >
                                商品を削除
                            </Button>
                        </div>
                    </>
                ) : (
                    <p className="text-sm text-muted-foreground">
                        {digital
                            ? 'ファイルと画像は作品を作成したあとに追加できます。'
                            : '画像は商品を作成したあとに追加できます。'}
                    </p>
                )}
            </div>
        </>
    );
}

ProductEdit.layout = {
    breadcrumbs: [{ title: '商品管理', href: index() }],
};
