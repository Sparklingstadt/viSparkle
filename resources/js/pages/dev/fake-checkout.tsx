import { Form, Head } from '@inertiajs/react';
import FakeCheckoutController from '@/actions/App/Http/Controllers/Dev/FakeCheckoutController';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';

export default function FakeCheckout({
    session_id,
    amount,
    plan_name,
    status,
    cancel_url,
}: {
    session_id: string;
    amount: number;
    plan_name: string | null;
    status: string;
    cancel_url: string;
}) {
    return (
        <>
            <Head title="テスト決済（開発用）" />

            <div className="mx-auto max-w-md space-y-6 rounded-xl border p-6">
                <h1 className="text-xl font-semibold">テスト決済（開発用）</h1>

                <p className="rounded-md bg-muted p-3 text-sm text-muted-foreground">
                    これは開発用の偽の決済画面です。実際の請求は発生しません。
                </p>

                <dl className="space-y-2 text-sm">
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">プラン</dt>
                        <dd className="font-medium">{plan_name ?? '-'}</dd>
                    </div>
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">金額（税込）</dt>
                        <dd className="text-lg font-bold">
                            {formatYen(amount)} / 月
                        </dd>
                    </div>
                </dl>

                {status === 'open' ? (
                    <div className="flex flex-col gap-2">
                        <Form {...FakeCheckoutController.pay.form(session_id)}>
                            {({ processing }) => (
                                <Button
                                    type="submit"
                                    className="w-full"
                                    disabled={processing}
                                >
                                    支払う
                                </Button>
                            )}
                        </Form>
                        <Button asChild variant="outline" className="w-full">
                            <a href={cancel_url}>キャンセル</a>
                        </Button>
                    </div>
                ) : (
                    <div className="space-y-2">
                        <p className="text-sm text-muted-foreground">
                            このセッションはすでに終了しています（{status}）。
                        </p>
                        <Button asChild variant="outline" className="w-full">
                            <a href={cancel_url}>戻る</a>
                        </Button>
                    </div>
                )}
            </div>
        </>
    );
}
