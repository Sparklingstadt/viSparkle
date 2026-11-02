import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { formatYen } from '@/lib/format';

export default function FakeCheckout({
    session_id,
    amount,
    mode,
    plan_name,
    items,
    status,
    cancel_url,
}: {
    session_id: string;
    amount: number;
    mode: 'subscription' | 'payment';
    plan_name: string | null;
    items: { name: string; unit_price: number; quantity: number }[];
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
                    {mode === 'payment' ? (
                        items.map((item) => (
                            <div
                                key={item.name}
                                className="flex justify-between gap-4"
                            >
                                <dt className="text-muted-foreground">
                                    {item.name} × {item.quantity}
                                </dt>
                                <dd>
                                    {formatYen(item.unit_price * item.quantity)}
                                </dd>
                            </div>
                        ))
                    ) : (
                        <div className="flex justify-between gap-4">
                            <dt className="text-muted-foreground">プラン</dt>
                            <dd className="font-medium">{plan_name ?? '-'}</dd>
                        </div>
                    )}
                    <div className="flex justify-between gap-4">
                        <dt className="text-muted-foreground">金額（税込）</dt>
                        <dd className="text-lg font-bold">
                            {formatYen(amount)}
                            {mode === 'subscription' && ' / 月'}
                        </dd>
                    </div>
                </dl>

                {status === 'open' ? (
                    <div className="flex flex-col gap-2">
                        {/* A literal URL: Wayfinder only generates this route's helper when the fake driver is on, so importing it breaks production builds. */}
                        <Form
                            action={`/dev/fake-checkout/${session_id}`}
                            method="post"
                        >
                            {({ processing }) => (
                                <div className="flex flex-col gap-2">
                                    <Button
                                        type="submit"
                                        name="action"
                                        value="pay"
                                        className="w-full"
                                        disabled={processing}
                                    >
                                        支払う
                                    </Button>
                                    {mode === 'payment' && (
                                        <>
                                            <Button
                                                type="submit"
                                                name="action"
                                                value="later"
                                                variant="outline"
                                                className="w-full"
                                                disabled={processing}
                                            >
                                                あとで支払う
                                            </Button>
                                            <Button
                                                type="submit"
                                                name="action"
                                                value="expire"
                                                variant="outline"
                                                className="w-full"
                                                disabled={processing}
                                            >
                                                期限切れにする
                                            </Button>
                                        </>
                                    )}
                                </div>
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
