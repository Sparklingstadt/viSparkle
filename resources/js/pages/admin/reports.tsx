import { Form, Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import Heading from '@/components/heading';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { formatDate } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index, update } from '@/routes/admin/reports';
import type { Paginated } from '@/types';

type ReportStatus = 'open' | 'resolved' | 'dismissed';

type Row = {
    id: number;
    reporter_name: string | null;
    target: { label: string; url: string | null };
    reason_label: string;
    body: string | null;
    status: ReportStatus;
    created_at: string;
    resolved_by_name: string | null;
    resolved_at: string | null;
    resolution_note: string | null;
};

const statusLabels: Record<ReportStatus, string> = {
    open: '未処理',
    resolved: '対応済み',
    dismissed: '却下',
};

const tabs: { label: string; status?: ReportStatus }[] = [
    { label: '未処理', status: 'open' },
    { label: '対応済み', status: 'resolved' },
    { label: '却下', status: 'dismissed' },
    { label: 'すべて' },
];

export default function Reports({
    reports,
    status,
}: {
    reports: Paginated<Row>;
    status: ReportStatus | null;
}) {
    const [acting, setActing] = useState<{
        report: Row;
        to: 'resolved' | 'dismissed';
    } | null>(null);

    return (
        <>
            <Head title="通報" />

            <div className="space-y-6">
                <Heading title="通報" />

                <div className="flex flex-wrap gap-1" role="tablist">
                    {tabs.map((tab) => (
                        <Button
                            key={tab.label}
                            size="sm"
                            variant="ghost"
                            asChild
                            className={cn({
                                'bg-muted': (tab.status ?? null) === status,
                            })}
                        >
                            <Link
                                href={index({
                                    query: tab.status
                                        ? { status: tab.status }
                                        : {},
                                })}
                            >
                                {tab.label}
                            </Link>
                        </Button>
                    ))}
                </div>

                {reports.data.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        通報はありません。
                    </p>
                ) : (
                    <ul className="divide-y rounded-xl border">
                        {reports.data.map((report) => (
                            <li key={report.id} className="space-y-2 p-4">
                                <div className="flex flex-wrap items-center gap-2 text-sm">
                                    <span className="rounded-full border px-2 py-0.5 text-xs">
                                        {statusLabels[report.status]}
                                    </span>
                                    <span className="font-medium">
                                        {report.reason_label}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {formatDate(report.created_at)} /{' '}
                                        {report.reporter_name ?? '（退会済み）'}
                                    </span>
                                </div>

                                <p className="text-sm">
                                    対象:{' '}
                                    {report.target.url ? (
                                        <Link
                                            href={report.target.url}
                                            className="underline"
                                        >
                                            {report.target.label}
                                        </Link>
                                    ) : (
                                        <span className="text-muted-foreground">
                                            {report.target.label}
                                        </span>
                                    )}
                                </p>

                                {report.body && (
                                    <p className="text-sm whitespace-pre-wrap">
                                        {report.body}
                                    </p>
                                )}

                                {report.status === 'open' ? (
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            onClick={() =>
                                                setActing({
                                                    report,
                                                    to: 'resolved',
                                                })
                                            }
                                        >
                                            対応済みにする
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            onClick={() =>
                                                setActing({
                                                    report,
                                                    to: 'dismissed',
                                                })
                                            }
                                        >
                                            却下する
                                        </Button>
                                    </div>
                                ) : (
                                    <p className="text-xs text-muted-foreground">
                                        {report.resolved_by_name}{' '}
                                        {formatDate(report.resolved_at)}
                                        {report.resolution_note &&
                                            `: ${report.resolution_note}`}
                                    </p>
                                )}
                            </li>
                        ))}
                    </ul>
                )}

                <div className="flex justify-between">
                    {reports.prev_page_url ? (
                        <Link href={reports.prev_page_url}>← 前へ</Link>
                    ) : (
                        <span />
                    )}
                    {reports.next_page_url && (
                        <Link href={reports.next_page_url}>次へ →</Link>
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
                            <DialogTitle>
                                {acting.to === 'resolved'
                                    ? '対応済みにする'
                                    : '却下する'}
                            </DialogTitle>
                            <DialogDescription>
                                メモは任意です。操作は記録されます。
                            </DialogDescription>

                            <Form
                                {...update.form(acting.report.id)}
                                options={{ preserveScroll: true }}
                                onSuccess={() => setActing(null)}
                                className="space-y-4"
                            >
                                {({ processing, errors }) => (
                                    <>
                                        <input
                                            type="hidden"
                                            name="status"
                                            value={acting.to}
                                        />
                                        <div className="grid gap-2">
                                            <Label htmlFor="report-note">
                                                メモ（任意）
                                            </Label>
                                            <textarea
                                                id="report-note"
                                                name="note"
                                                rows={4}
                                                maxLength={1000}
                                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                            />
                                            <InputError message={errors.note} />
                                            <InputError
                                                message={errors.status}
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
                                                disabled={processing}
                                            >
                                                {acting.to === 'resolved'
                                                    ? '対応済みにする'
                                                    : '却下する'}
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

Reports.layout = {
    breadcrumbs: [{ title: '通報', href: index() }],
};
