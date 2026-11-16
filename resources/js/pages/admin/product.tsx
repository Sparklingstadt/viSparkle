import { Head } from '@inertiajs/react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { formatYen } from '@/lib/format';
import { index as reportsIndex } from '@/routes/admin/reports';
import { update } from '@/routes/admin/products';
import { ModerationPanel } from '@/components/admin/moderation-panel';
import { ReportList, type ItemReport } from '@/components/admin/report-list';
import { HiddenByAdminBadge } from '@/components/hidden-by-admin';

type ModeratedProduct = {
    id: number;
    name: string;
    description: string | null;
    price: number;
    stock: number;
    is_published: boolean;
    hidden: boolean;
    hidden_at: string | null;
    hidden_reason: string | null;
    creator: { name: string; slug: string };
    url: string;
};

export default function AdminProduct({
    product,
    reports,
}: {
    product: ModeratedProduct;
    reports: ItemReport[];
}) {
    return (
        <>
            <Head title="商品の管理" />

            <div className="max-w-3xl space-y-6">
                <Heading title="商品の管理" />

                <section className="space-y-2 rounded-xl border p-4 text-sm">
                    <div className="flex flex-wrap items-center gap-2">
                        <span className="text-base font-medium">
                            {product.name}
                        </span>
                        <Badge variant="outline">
                            {product.is_published ? '販売中' : '非公開（作者）'}
                        </Badge>
                        {product.hidden && <HiddenByAdminBadge />}
                    </div>
                    <p className="text-muted-foreground">
                        {product.creator.name}（@{product.creator.slug}） /{' '}
                        {formatYen(product.price)} / 在庫 {product.stock} 点
                    </p>
                    {product.description && (
                        <p className="whitespace-pre-wrap">
                            {product.description}
                        </p>
                    )}
                    <a
                        href={product.url}
                        target="_blank"
                        rel="noreferrer"
                        className="underline underline-offset-4"
                    >
                        公開ページを開く
                    </a>
                </section>

                <ModerationPanel
                    hidden={product.hidden}
                    hiddenAt={product.hidden_at}
                    hiddenReason={product.hidden_reason}
                    form={update.form(product.id)}
                />

                <ReportList title="この商品への通報" reports={reports} />
            </div>
        </>
    );
}

AdminProduct.layout = {
    breadcrumbs: [{ title: '通報', href: reportsIndex() }],
};
