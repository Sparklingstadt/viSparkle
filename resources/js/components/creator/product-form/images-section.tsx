import { ArrowLeft, ArrowRight, Trash2 } from 'lucide-react';
import ProductImageController from '@/actions/App/Http/Controllers/Creator/ProductImageController';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import { UploadField } from '@/components/upload-field';
import { useConfirmedDelete } from '@/hooks/use-confirmed-delete';
import { saveSwapped } from '@/lib/reorder';
import type { EditableProduct } from './types';

const MAX_IMAGES = 10;

export function ImagesSection({ product }: { product: EditableProduct }) {
    const confirmedDelete = useConfirmedDelete();
    const { images } = product;

    const move = (from: number, to: number) =>
        saveSwapped(
            images.map((image) => image.id),
            from,
            to,
            ProductImageController.reorder.url(product.id),
        );

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
                                onClick={() =>
                                    confirmedDelete(
                                        ProductImageController.destroy.url({
                                            product: product.id,
                                            image: image.id,
                                        }),
                                        'この画像を削除しますか？',
                                    )
                                }
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
                <UploadField
                    form={ProductImageController.store.form(product.id)}
                    id="product-image"
                    name="image"
                    label="画像を追加"
                    accept="image/jpeg,image/png,image/webp"
                />
            )}
        </section>
    );
}
