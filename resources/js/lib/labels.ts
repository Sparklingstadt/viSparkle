import type { ReportStatus } from '@/types';

export const REPORT_STATUS_LABEL: Record<ReportStatus, string> = {
    open: '未処理',
    resolved: '対応済み',
    dismissed: '却下',
};
