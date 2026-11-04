import { Form, usePage } from '@inertiajs/react';
import { Search } from 'lucide-react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { search } from '@/routes';

export function SiteSearch({ className }: { className?: string }) {
    const { url } = usePage();
    const q = new URLSearchParams(url.split('?')[1]).get('q') ?? '';

    return (
        <Form
            {...search.form()}
            role="search"
            className={cn('flex items-center gap-2', className)}
        >
            {/* Keyed so that moving to another search refills the box. */}
            <Input
                key={q}
                type="search"
                name="q"
                aria-label="検索キーワード"
                placeholder="クリエイター・商品・投稿を検索"
                defaultValue={q}
                maxLength={100}
                className="h-8"
            />
            <Button type="submit" size="sm" variant="secondary">
                <Search />
                検索
            </Button>
        </Form>
    );
}
