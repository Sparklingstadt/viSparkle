import { Form, Head } from '@inertiajs/react';
import {
    archive,
    destroy,
    move,
    store,
    update,
} from '@/actions/App/Http/Controllers/Admin/CategoryController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/admin/categories';

type Row = {
    id: number;
    name: string;
    slug: string;
    archived: boolean;
    products_count: number;
};

function CategoryRow({
    category,
    first,
    last,
}: {
    category: Row;
    first: boolean;
    last: boolean;
}) {
    return (
        <li
            className={`space-y-3 p-4 ${category.archived ? 'opacity-60' : ''}`}
        >
            <Form
                {...update.form(category.id)}
                options={{ preserveScroll: true }}
                className="flex flex-wrap items-start gap-3"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="grid gap-1">
                            <Input
                                name="name"
                                aria-label="名前"
                                defaultValue={category.name}
                                required
                                maxLength={30}
                                className="w-48"
                            />
                            <InputError message={errors.name} />
                        </div>
                        <div className="grid gap-1">
                            <Input
                                name="slug"
                                aria-label="slug"
                                defaultValue={category.slug}
                                required
                                maxLength={40}
                                className="w-48"
                            />
                            <InputError message={errors.slug} />
                        </div>
                        <Button
                            type="submit"
                            size="sm"
                            variant="secondary"
                            disabled={processing}
                        >
                            保存
                        </Button>
                    </>
                )}
            </Form>

            <div className="flex flex-wrap items-center gap-2 text-sm">
                {category.archived && (
                    <Badge variant="outline">アーカイブ済み</Badge>
                )}
                <span className="text-muted-foreground">
                    商品 {category.products_count} 件
                </span>

                <Form
                    {...move.form(category.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="direction" value="up" />
                            <Button
                                size="sm"
                                variant="outline"
                                aria-label="上へ"
                                disabled={first || processing}
                            >
                                ↑
                            </Button>
                        </>
                    )}
                </Form>
                <Form
                    {...move.form(category.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="direction"
                                value="down"
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                aria-label="下へ"
                                disabled={last || processing}
                            >
                                ↓
                            </Button>
                        </>
                    )}
                </Form>

                <Form
                    {...archive.form(category.id)}
                    options={{ preserveScroll: true }}
                >
                    {({ processing }) => (
                        <>
                            <input
                                type="hidden"
                                name="archived"
                                value={category.archived ? '0' : '1'}
                            />
                            <Button
                                size="sm"
                                variant="outline"
                                disabled={processing}
                            >
                                {category.archived ? '再開' : 'アーカイブ'}
                            </Button>
                        </>
                    )}
                </Form>

                <Form
                    {...destroy.form(category.id)}
                    options={{ preserveScroll: true }}
                    onBefore={() =>
                        confirm(`「${category.name}」を削除しますか？`)
                    }
                >
                    {({ processing, errors }) => (
                        <>
                            <Button
                                size="sm"
                                variant="destructive"
                                disabled={
                                    category.products_count > 0 || processing
                                }
                            >
                                削除
                            </Button>
                            <InputError message={errors.category} />
                        </>
                    )}
                </Form>
            </div>
        </li>
    );
}

export default function Categories({ categories }: { categories: Row[] }) {
    return (
        <>
            <Head title="カテゴリ" />

            <div className="space-y-6">
                <Heading title="カテゴリ" />

                <Form
                    {...store.form()}
                    options={{ preserveScroll: true }}
                    resetOnSuccess
                    className="flex flex-wrap items-start gap-3 rounded-xl border p-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="new-name">名前</Label>
                                <Input
                                    id="new-name"
                                    name="name"
                                    required
                                    maxLength={30}
                                    className="w-48"
                                />
                                <InputError message={errors.name} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="new-slug">slug</Label>
                                <Input
                                    id="new-slug"
                                    name="slug"
                                    required
                                    maxLength={40}
                                    placeholder="illustration"
                                    className="w-48"
                                />
                                <InputError message={errors.slug} />
                            </div>
                            <Button
                                type="submit"
                                className="mt-6"
                                disabled={processing}
                            >
                                追加
                            </Button>
                        </>
                    )}
                </Form>

                {categories.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        カテゴリはまだありません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {categories.map((category, i) => (
                            <CategoryRow
                                key={category.id}
                                category={category}
                                first={i === 0}
                                last={i === categories.length - 1}
                            />
                        ))}
                    </ul>
                )}
            </div>
        </>
    );
}

Categories.layout = {
    breadcrumbs: [{ title: 'カテゴリ', href: index() }],
};
