import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import SupportController from '@/actions/App/Http/Controllers/Fan/SupportController';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import type { PlanData } from '@/types';

export function SupportButton({
    creatorSlug,
    plan,
    viewerPlanId,
    viewerSupportId,
}: {
    creatorSlug: string;
    plan: PlanData;
    viewerPlanId: number | null;
    viewerSupportId: number | null;
}) {
    const { auth } = usePage().props;
    const [processing, setProcessing] = useState(false);

    if (!auth.user) {
        return (
            <Button asChild className="w-full">
                <a href={login().url}>ログインして支援する</a>
            </Button>
        );
    }

    const onError = (errors: Record<string, string>) =>
        toast.error(errors.plan ?? '処理に失敗しました。');

    if (viewerPlanId === plan.id) {
        return (
            <div className="space-y-2">
                <Button className="w-full" variant="secondary" disabled>
                    支援中
                </Button>
                {viewerSupportId !== null && (
                    <Button
                        className="w-full"
                        variant="ghost"
                        size="sm"
                        disabled={processing}
                        onClick={() => {
                            if (!confirm('支援を停止しますか？')) {
                                return;
                            }

                            router.delete(
                                SupportController.destroy.url(viewerSupportId),
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onError,
                                },
                            );
                        }}
                    >
                        支援を停止する
                    </Button>
                )}
            </div>
        );
    }

    if (!plan.is_accepting) {
        return (
            <Button className="w-full" variant="outline" disabled>
                受付停止中
            </Button>
        );
    }

    return (
        <Button
            className="w-full"
            disabled={processing}
            onClick={() => {
                if (
                    viewerPlanId !== null &&
                    !confirm(
                        '現在のプランを終了して、このプランに変更しますか？',
                    )
                ) {
                    return;
                }

                router.post(
                    SupportController.store.url({
                        creator: creatorSlug,
                        plan: plan.id,
                    }),
                    {},
                    {
                        preserveScroll: true,
                        onStart: () => setProcessing(true),
                        onFinish: () => setProcessing(false),
                        onError,
                    },
                );
            }}
        >
            {viewerPlanId === null
                ? 'このプランで支援する'
                : 'このプランに変更'}
        </Button>
    );
}
