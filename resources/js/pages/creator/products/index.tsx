import { Head, Link } from '@inertiajs/react';
import { ImageOff, Plus, Settings } from 'lucide-react';
import ProductController from '@/actions/App/Http/Controllers/Creator/ProductController';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';
import { index as earnings } from '@/routes/creator/earnings';
import { index } from '@/routes/creator/products';
import { edit as shopSettings } from '@/routes/creator/shop';

type Row = {
    id: number;
    name: string;
    price: number;
    stock: number;
    is_published: boolean;
    hidden: boolean;
    cover_url: string | null;
};

export default function ProductsIndex({
    products,
    can_publish: canPublish,
}: {
    products: Row[];
    can_publish: boolean;
}) {
    return (
        <>
            <Head title="商品管理" />

            <div className="space-y-6 p-4">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <Heading title="商品管理" />
                    <div className="flex gap-2">
                        <Button variant="outline" asChild>
                            <Link href={shopSettings()}>
                                <Settings />
                                ショップ設定
                            </Link>
                        </Button>
                        <Button asChild>
                            <Link href={ProductController.create()}>
                                <Plus />
                                商品を追加
                            </Link>
                        </Button>
                    </div>
                </div>

                {!canPublish && (
                    <div className="rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                        口座の登録が済むと商品を公開できます。{' '}
                        <Link href={earnings()} className="underline">
                            収益ページへ
                        </Link>
                    </div>
                )}

                {products.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        まだ商品がありません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {products.map((product) => (
                            <li key={product.id}>
                                <Link
                                    href={ProductController.edit(product.id)}
                                    className="flex items-center gap-4 p-3 hover:bg-muted/50"
                                >
                                    <div className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-md border bg-muted">
                                        {product.cover_url ? (
                                            <img
                                                src={product.cover_url}
                                                alt=""
                                                className="size-full object-cover"
                                            />
                                        ) : (
                                            <ImageOff className="size-5 text-muted-foreground" />
                                        )}
                                    </div>
                                    <div className="min-w-0 flex-1">
                                        <p className="truncate font-medium">
                                            {product.name}
                                        </p>
                                        <p className="text-sm text-muted-foreground">
                                            {formatYen(product.price)} ・ 在庫{' '}
                                            {product.stock}
                                        </p>
                                    </div>
                                    <div className="flex shrink-0 gap-1">
                                        {product.hidden && (
                                            <Badge variant="destructive">
                                                運営により非表示
                                            </Badge>
                                        )}
                                        <Badge
                                            variant={
                                                product.is_published
                                                    ? 'default'
                                                    : 'secondary'
                                            }
                                        >
                                            {product.is_published
                                                ? '公開中'
                                                : '非公開'}
                                        </Badge>
                                    </div>
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

ProductsIndex.layout = {
    breadcrumbs: [{ title: '商品管理', href: index() }],
};
