import { Link } from '@inertiajs/react';
import { formatDate } from '@/lib/format';
import { index as reportsIndex } from '@/routes/admin/reports';
import { REPORT_STATUS_LABEL } from '@/lib/labels';
import type { ReportStatus } from '@/types';

export type ItemReport = {
    id: number;
    reporter_name: string | null;
    reason_label: string;
    body: string | null;
    status: ReportStatus;
    created_at: string;
};

/** The reports about one post or product, on its admin page. */
export function ReportList({
    title,
    reports,
}: {
    title: string;
    reports: ItemReport[];
}) {
    return (
        <section className="space-y-2">
            <h2 className="font-medium">{title}</h2>
            {reports.length === 0 ? (
                <p className="text-sm text-muted-foreground">
                    通報はありません。
                </p>
            ) : (
                <ul className="divide-y rounded-xl border">
                    {reports.map((report) => (
                        <li key={report.id} className="space-y-1 p-4 text-sm">
                            <div className="flex flex-wrap items-center gap-2">
                                <span className="rounded-full border px-2 py-0.5 text-xs">
                                    {REPORT_STATUS_LABEL[report.status]}
                                </span>
                                <span className="font-medium">
                                    {report.reason_label}
                                </span>
                                <span className="text-muted-foreground">
                                    {formatDate(report.created_at)} /{' '}
                                    {report.reporter_name ?? '（退会済み）'}
                                </span>
                            </div>
                            {report.body && (
                                <p className="whitespace-pre-wrap">
                                    {report.body}
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            )}
            <Link
                href={reportsIndex()}
                className="text-sm underline underline-offset-4"
            >
                通報一覧へ
            </Link>
        </section>
    );
}
