import { Link, router, usePage } from '@inertiajs/react';
import { useState } from 'react';
import CheckoutConfirmController from '@/actions/App/Http/Controllers/Fan/CheckoutConfirmController';
import SupportController from '@/actions/App/Http/Controllers/Fan/SupportController';
import { Button } from '@/components/ui/button';
import { login } from '@/routes';
import type { PlanData } from '@/types';
import { toastErrors } from '@/lib/toast-errors';
import { useConfirm } from '@/components/confirm-dialog';

export function SupportButton({
    creatorSlug,
    plan,
    viewerPlanId,
    viewerSupportId,
    viewerSupportPrice,
    viewerSupportCancelling,
    acceptsSupports,
    closed,
}: {
    creatorSlug: string;
    plan: PlanData;
    viewerPlanId: number | null;
    viewerSupportId: number | null;
    viewerSupportPrice: number | null;
    viewerSupportCancelling: boolean;
    acceptsSupports: boolean;
    closed: boolean;
}) {
    const confirm = useConfirm();
    const { auth } = usePage().props;
    const [processing, setProcessing] = useState(false);

    if (!auth.user) {
        return (
            <Button asChild className="w-full">
                <a href={login().url}>ログインして支援する</a>
            </Button>
        );
    }

    const onError = toastErrors('plan');

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
                        // A closed creator's supports cannot be renewed.
                        !closed && (
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
                        )
                    ) : (
                        <Button
                            className="w-full"
                            variant="ghost"
                            size="sm"
                            disabled={processing}
                            onClick={async () => {
                                if (
                                    !(await confirm({
                                        title: '支援を停止しますか？',
                                        confirmLabel: '支援を停止',
                                        destructive: true,
                                    }))
                                ) {
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

    if (closed) {
        return (
            <Button className="w-full" variant="outline" disabled>
                受付終了
            </Button>
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

    if (viewerPlanId === null) {
        return (
            <Button asChild className="w-full">
                <Link
                    href={CheckoutConfirmController.url({
                        creator: creatorSlug,
                        plan: plan.id,
                    })}
                >
                    このプランで支援する
                </Link>
            </Button>
        );
    }

    const isUpgrade =
        viewerSupportPrice !== null && plan.price > viewerSupportPrice;

    return (
        <Button
            className="w-full"
            disabled={processing}
            onClick={async () => {
                if (viewerSupportId === null) {
                    return;
                }

                if (
                    !(await confirm({
                        title: 'このプランに変更しますか？',
                        description: isUpgrade
                            ? '差額（日割り）がすぐに請求されます。'
                            : '今月分の差額は返金されません。',
                        confirmLabel: 'プランを変更',
                    }))
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
            このプランに変更
        </Button>
    );
}
