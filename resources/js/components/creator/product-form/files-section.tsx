import { ArrowDown, ArrowUp, Download, Trash2 } from 'lucide-react';
import ProductFileController from '@/actions/App/Http/Controllers/Creator/ProductFileController';
import Heading from '@/components/heading';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { UploadField } from '@/components/upload-field';
import { useConfirmedDelete } from '@/hooks/use-confirmed-delete';
import { formatBytes } from '@/lib/format';
import { saveSwapped } from '@/lib/reorder';
import type { EditableProduct } from './types';

/**
 * The files a buyer of a digital work downloads. Each is kept as uploaded; buyers see the current files.
 */
export function FilesSection({
    product,
    filesMax,
    fileMaxKb,
}: {
    product: EditableProduct;
    filesMax: number;
    fileMaxKb: number;
}) {
    const confirmedDelete = useConfirmedDelete();
    const { files } = product;

    const move = (from: number, to: number) =>
        saveSwapped(
            files.map((file) => file.id),
            from,
            to,
            ProductFileController.reorder.url(product.id),
        );

    return (
        <section className="space-y-3">
            <Heading
                variant="small"
                title={`ファイル（${files.length}/${filesMax}）`}
            />
            <p className="text-xs text-muted-foreground">
                購入した人が注文の画面からダウンロードします。1ファイル
                {Math.floor(fileMaxKb / 1024)}MB まで、種類は問いません。
            </p>
            {files.length === 0 && (
                <Alert>
                    <AlertDescription>
                        公開するにはファイルを1件以上アップロードしてください
                    </AlertDescription>
                </Alert>
            )}
            {files.length > 0 && (
                <ul className="divide-y rounded-md border">
                    {files.map((file, i) => (
                        <li
                            key={file.id}
                            className="flex items-center gap-2 px-3 py-2"
                        >
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm">
                                    {file.original_name}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatBytes(file.size)}
                                </p>
                            </div>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="上へ"
                                disabled={i === 0}
                                onClick={() => move(i, i - 1)}
                            >
                                <ArrowUp />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="下へ"
                                disabled={i === files.length - 1}
                                onClick={() => move(i, i + 1)}
                            >
                                <ArrowDown />
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="ダウンロード"
                                asChild
                            >
                                <a
                                    href={ProductFileController.download.url({
                                        product: product.id,
                                        file: file.id,
                                    })}
                                >
                                    <Download />
                                </a>
                            </Button>
                            <Button
                                size="icon"
                                variant="ghost"
                                aria-label="削除"
                                onClick={() =>
                                    confirmedDelete(
                                        ProductFileController.destroy.url({
                                            product: product.id,
                                            file: file.id,
                                        }),
                                        'このファイルを削除しますか？',
                                    )
                                }
                            >
                                <Trash2 />
                            </Button>
                        </li>
                    ))}
                </ul>
            )}
            {files.length < filesMax && (
                <UploadField
                    form={ProductFileController.store.form(product.id)}
                    id="product-file"
                    name="file"
                    label="ファイルを追加"
                    showProgress
                />
            )}
        </section>
    );
}
