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
    viewerSupportPrice,
    viewerSupportCancelling,
    acceptsSupports,
}: {
    creatorSlug: string;
    plan: PlanData;
    viewerPlanId: number | null;
    viewerSupportId: number | null;
    viewerSupportPrice: number | null;
    viewerSupportCancelling: boolean;
    acceptsSupports: boolean;
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

    const request = {
        preserveScroll: true,
        onStart: () => setProcessing(true),
        onFinish: () => setProcessing(false),
        onError,
    };

    if (viewerPlanId === plan.id) {
        return (
            <div className="space-y-2">
                <Button className="w-full" variant="secondary" disabled>
                    支援中
                </Button>
                {viewerSupportId !== null &&
                    (viewerSupportCancelling ? (
                        <Button
                            className="w-full"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                            onClick={() =>
                                router.post(
                                    SupportController.resume.url(
                                        viewerSupportId,
                                    ),
                                    {},
                                    request,
                                )
                            }
                        >
                            支援を再開する
                        </Button>
                    ) : (
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
                                    SupportController.destroy.url(
                                        viewerSupportId,
                                    ),
                                    request,
                                );
                            }}
                        >
                            支援を停止する
                        </Button>
                    ))}
            </div>
        );
    }

    if (viewerPlanId !== null && viewerSupportCancelling) {
        return (
            <Button className="w-full" variant="outline" disabled>
                停止予約中
            </Button>
        );
    }

    if (!acceptsSupports && viewerPlanId === null) {
        return (
            <Button className="w-full" variant="outline" disabled>
                準備中
            </Button>
        );
    }

    if (!plan.is_accepting) {
        return (
            <Button className="w-full" variant="outline" disabled>
                受付停止中
            </Button>
        );
    }

    const isUpgrade =
        viewerSupportPrice !== null && plan.price > viewerSupportPrice;

    return (
        <Button
            className="w-full"
            disabled={processing}
            onClick={() => {
                if (viewerPlanId === null) {
                    router.post(
                        SupportController.store.url({
                            creator: creatorSlug,
                            plan: plan.id,
                        }),
                        {},
                        request,
                    );

                    return;
                }

                if (viewerSupportId === null) {
                    return;
                }

                if (
                    !confirm(
                        isUpgrade
                            ? 'このプランに変更しますか？差額（日割り）がすぐに請求されます。'
                            : 'このプランに変更しますか？今月分の差額は返金されません。',
                    )
                ) {
                    return;
                }

                router.put(
                    SupportController.update.url(viewerSupportId),
                    { plan_id: plan.id },
                    request,
                );
            }}
        >
            {viewerPlanId === null
                ? 'このプランで支援する'
                : 'このプランに変更'}
        </Button>
    );
}
