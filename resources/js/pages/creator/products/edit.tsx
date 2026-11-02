import { Form, Head, Link, router } from '@inertiajs/react';
import { ArrowLeft, ArrowRight, Trash2 } from 'lucide-react';
import ProductController from '@/actions/App/Http/Controllers/Creator/ProductController';
import ProductImageController from '@/actions/App/Http/Controllers/Creator/ProductImageController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index as earnings } from '@/routes/creator/earnings';
import { index } from '@/routes/creator/products';

const MAX_IMAGES = 10;

type ProductImage = { id: number; url: string };

type EditableProduct = {
    id: number;
    name: string;
    description: string | null;
    price: number;
    stock: number;
    is_published: boolean;
    hidden: boolean;
    hidden_reason: string | null;
    images: ProductImage[];
};

function ImagesSection({ product }: { product: EditableProduct }) {
    const { images } = product;

    const move = (from: number, to: number) => {
        const ids = images.map((image) => image.id);
        [ids[from], ids[to]] = [ids[to], ids[from]];
        router.put(
            ProductImageController.reorder.url(product.id),
            { ids },
            { preserveScroll: true },
        );
    };

    return (
        <section className="space-y-3">
            <Heading
                variant="small"
                title={`商品画像（${images.length}/${MAX_IMAGES}）`}
            />
            <p className="text-xs text-muted-foreground">
                先頭の画像が一覧に表示されます。
            </p>
            <div className="flex flex-wrap gap-3">
                {images.map((image, i) => (
                    <div key={image.id} className="w-28 space-y-1">
                        <img
                            src={image.url}
                            alt=""
                            className="size-28 rounded-md border object-cover"
                        />
                        <div className="flex justify-between">
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="前へ"
                                disabled={i === 0}
                                onClick={() => move(i, i - 1)}
                            >
                                <ArrowLeft />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="削除"
                                onClick={() => {
                                    if (confirm('この画像を削除しますか？')) {
                                        router.delete(
                                            ProductImageController.destroy.url({
                                                product: product.id,
                                                image: image.id,
                                            }),
                                            { preserveScroll: true },
                                        );
                                    }
                                }}
                            >
                                <Trash2 />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="後ろへ"
                                disabled={i === images.length - 1}
                                onClick={() => move(i, i + 1)}
                            >
                                <ArrowRight />
                            </Button>
                        </div>
                    </div>
                ))}
            </div>
            {images.length < MAX_IMAGES && (
                <Form
                    {...ProductImageController.store.form(product.id)}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="grid max-w-sm gap-2"
                >
                    {({ processing, errors }) => (
                        <>
                            <Label htmlFor="product-image">画像を追加</Label>
                            <Input
                                id="product-image"
                                name="image"
                                type="file"
                                accept="image/jpeg,image/png,image/webp"
                                required
                            />
                            <InputError message={errors.image} />
                            <Button
                                variant="outline"
                                size="sm"
                                className="w-fit"
                                disabled={processing}
                            >
                                アップロード
                            </Button>
                        </>
                    )}
                </Form>
            )}
        </section>
    );
}

export default function ProductEdit({
    product,
    can_publish: canPublish,
}: {
    product: EditableProduct | null;
    can_publish: boolean;
}) {
    const title = product ? '商品を編集' : '商品を追加';
    const form = product
        ? ProductController.update.form(product.id)
        : ProductController.store.form();

    return (
        <>
            <Head title={title} />

            <div className="max-w-2xl space-y-8 p-4">
                <Heading title={title} />

                {product?.hidden && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            運営により非表示になっています（理由:{' '}
                            {product.hidden_reason}）
                        </AlertDescription>
                    </Alert>
                )}

                <Form
                    key={product?.id ?? 'new'}
                    {...form}
                    options={{ preserveScroll: true }}
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
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
                                <textarea
                                    id="description"
                                    name="description"
                                    defaultValue={product?.description ?? ''}
                                    maxLength={5000}
                                    rows={6}
                                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                                />
                                <InputError message={errors.description} />
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <div className="grid gap-2">
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
                                <div className="grid gap-2">
                                    <Label htmlFor="stock">在庫数</Label>
                                    <Input
                                        id="stock"
                                        name="stock"
                                        type="number"
                                        min={0}
                                        max={99999}
                                        step={1}
                                        defaultValue={product?.stock ?? 1}
                                        required
                                    />
                                    <InputError message={errors.stock} />
                                </div>
                            </div>
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
                        <ImagesSection product={product} />
                        <div className="border-t pt-6">
                            <Button
                                variant="destructive"
                                onClick={() => {
                                    if (confirm('この商品を削除しますか？')) {
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
                        画像は商品を作成したあとに追加できます。
                    </p>
                )}
            </div>
        </>
    );
}

ProductEdit.layout = {
    breadcrumbs: [{ title: '商品管理', href: index() }],
};
