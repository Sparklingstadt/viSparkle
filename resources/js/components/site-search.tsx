import { Form, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { search } from '@/routes';

const types = [
    { value: 'all', label: 'すべて' },
    { value: 'creators', label: 'クリエイター' },
    { value: 'products', label: '商品' },
    { value: 'posts', label: '投稿' },
];

export function SiteSearch({ className }: { className?: string }) {
    const { url } = usePage();
    const params = new URLSearchParams(url.split('?')[1]);
    const q = params.get('q') ?? '';
    const type = params.get('type') ?? 'all';

    return (
        <Form
            {...search.form()}
            role="search"
            className={cn('flex items-center gap-2', className)}
        >
            {/* Keyed so that moving to another search refills the fields. */}
            <Select
                key={`type-${type}`}
                name="type"
                defaultValue={
                    types.some(({ value }) => value === type) ? type : 'all'
                }
            >
                <SelectTrigger
                    size="sm"
                    aria-label="検索する種類"
                    className="w-32 shrink-0"
                >
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {types.map(({ value, label }) => (
                        <SelectItem key={value} value={value}>
                            {label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
            <Input
                key={q}
                type="search"
                name="q"
                aria-label="検索キーワード"
                placeholder="キーワードを入力"
                defaultValue={q}
                maxLength={100}
                className="h-8 min-w-0"
            />
            <Button type="submit" size="sm" variant="secondary">
                <Search />
                検索
            </Button>
        </Form>
    );
}
