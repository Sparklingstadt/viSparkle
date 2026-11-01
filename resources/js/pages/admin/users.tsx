import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { index, update } from '@/routes/admin/users';
import type { Paginated } from '@/types';

type Row = {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    creator_slug: string | null;
    suspended: boolean;
    suspended_at: string | null;
    suspended_reason: string | null;
    created_at: string;
};

export default function Users({
    users,
    q,
}: {
    users: Paginated<Row>;
    q: string | null;
}) {
    const [acting, setActing] = useState<Row | null>(null);

    return (
        <>
            <Head title="会員" />

            <div className="space-y-6">
                <Heading title="会員" />

                <Form
                    {...index.form()}
                    className="flex flex-wrap items-end gap-3"
                >
                    <Input
                        name="q"
                        aria-label="検索"
                        placeholder="名前・メール・ページIDで検索"
                        defaultValue={q ?? ''}
                        className="max-w-sm"
                    />
                    <Button type="submit" variant="secondary">
                        検索
                    </Button>
                    {q && (
                        <Button variant="ghost" asChild>
                            <Link href={index()}>解除</Link>
                        </Button>
                    )}
                </Form>

                {users.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        会員が見つかりません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {users.data.map((user) => (
                            <li
                                key={user.id}
                                className="flex flex-wrap items-center justify-between gap-3 p-4"
                            >
                                <div className="space-y-1 text-sm">
                                    <div className="flex flex-wrap items-center gap-2">
                                        <span className="font-medium">
                                            {user.name}
                                        </span>
                                        {user.is_admin && (
                                            <Badge variant="outline">
                                                運営
                                            </Badge>
                                        )}
                                        {user.suspended && (
                                            <Badge variant="destructive">
                                                利用停止中
                                            </Badge>
                                        )}
                                    </div>
                                    <p className="text-muted-foreground">
                                        {user.email}
                                        {user.creator_slug &&
                                            ` / @${user.creator_slug}`}
                                        {' / 登録 '}
                                        {formatDate(user.created_at)}
                                    </p>
                                    {user.suspended && (
                                        <p className="text-xs text-muted-foreground">
                                            {formatDate(user.suspended_at)}
                                            {user.suspended_reason &&
                                                `: ${user.suspended_reason}`}
                                        </p>
                                    )}
                                </div>

                                {user.suspended ? (
                                    <Form
                                        {...update.form(user.id)}
                                        options={{ preserveScroll: true }}
                                    >
                                        {({ processing }) => (
                                            <>
                                                <input
                                                    type="hidden"
                                                    name="suspended"
                                                    value="0"
                                                />
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    disabled={processing}
                                                >
                                                    利用停止を解除する
                                                </Button>
                                            </>
                                        )}
                                    </Form>
                                ) : (
                                    <Button
                                        size="sm"
                                        variant="destructive"
                                        onClick={() => setActing(user)}
                                    >
                                        利用停止にする
                                    </Button>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="flex justify-between">
                    {users.prev_page_url ? (
                        <Link href={users.prev_page_url}>← 前へ</Link>
                    ) : (
                        <span />
                    )}
                    {users.next_page_url && (
                        <Link href={users.next_page_url}>次へ →</Link>
                    )}
                </div>
            </div>

            <Dialog
                open={acting !== null}
                onOpenChange={(open) => !open && setActing(null)}
            >
                <DialogContent>
                    {acting && (
                        <>
                            <DialogTitle>利用停止にする</DialogTitle>
                            <DialogDescription>
                                {acting.name}
                                さんはログインできなくなります。支援中のサブスクリプションは即時解約されます。
                                {acting.creator_slug &&
                                    'クリエイターページは非公開になり、支援者全員の支援を即時解約して直近の支払いを全額返金します。'}
                                解除しても解約・返金は元に戻りません。
                            </DialogDescription>

                            <Form
                                {...update.form(acting.id)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setActing(null)}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="suspended"
                                            value="1"
                                        />
                                        <div className="grid gap-2">
                                            <Label htmlFor="suspend-reason">
                                                理由
                                            </Label>
                                            <textarea
                                                id="suspend-reason"
                                                name="reason"
                                                rows={4}
                                                required
                                                maxLength={500}
                                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            />
                                            <InputError
                                                message={errors.reason}
                                            />
                                            <InputError
                                                message={errors.suspended}
                                            />
                                        </div>
                                        <DialogFooter className="gap-2">
                                            <DialogClose asChild>
                                                <Button
                                                    type="button"
                                                    variant="secondary"
                                                >
                                                    キャンセル
                                                </Button>
                                            </DialogClose>
                                            <Button
                                                type="submit"
                                                variant="destructive"
                                                disabled={processing}
                                            >
                                                利用停止にする
                                            </Button>
                                        </DialogFooter>
                                    </>
                                )}
                            </Form>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

Users.layout = {
    breadcrumbs: [{ title: '会員', href: index() }],
};
