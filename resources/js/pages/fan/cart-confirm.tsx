import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { toast } from 'sonner';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatYen } from '@/lib/format';
import { index as cartIndex, checkout } from '@/routes/cart';
import { tokushoho } from '@/routes/legal';
import type { CartGroup, CreatorSummary } from '@/types';

type SupportOptions = { presets: number[]; min: number; max: number };

export default function CartConfirm({
    creator,
    group,
    has_digital: hasDigital,
    has_physical: hasPhysical,
    support,
}: {
    creator: CreatorSummary;
    group: CartGroup;
    has_digital: boolean;
    has_physical: boolean;
    support: SupportOptions | null;
}) {
    const [processing, setProcessing] = useState(false);
    // 'none', a preset amount as a string, or 'custom' for the free input.
    const [choice, setChoice] = useState('none');
    const [custom, setCustom] = useState('');
    const [supportError, setSupportError] = useState<string>();

    const supportAmount =
        support === null || choice === 'none'
            ? 0
            : choice === 'custom'
              ? Math.max(0, Math.trunc(Number(custom)) || 0)
              : Number(choice);

    const proceed = () =>
        router.post(
            checkout(creator.slug).url,
            { support_amount: supportAmount },
            {
                onStart: () => {
                    setProcessing(true);
                    setSupportError(undefined);
                },
                onFinish: () => setProcessing(false),
                onError: (errors) => {
                    if (errors.support_amount) {
                        setSupportError(errors.support_amount);

                        return;
                    }

                    toast.error(errors.cart ?? '処理に失敗しました。');
                },
            },
        );

    const rows: [string, string][] = [
        ...(hasPhysical
            ? ([['お届け', 'お届けは国内のみです。']] as [string, string][])
            : []),
        ...(hasDigital
            ? ([
                  [
                      'ダウンロード',
                      'お支払いの確認後、注文の画面からダウンロードできます。作品が非公開になるとダウンロードできなくなるため、早めに保存してください',
                  ],
              ] as [string, string][])
            : []),
        [
            '取り消し',
            hasDigital
                ? 'デジタル作品は購入後に取り消せません。お支払いの確定後は、購入者から注文を取り消すことはできません。'
                : 'お支払いの確定後は、購入者から注文を取り消すことはできません。',
        ],
    ];

    return (
        <>
            <Head title="ご注文内容の確認" />

            <div className="mx-auto max-w-2xl space-y-6 p-4">
                <Heading
                    title="ご注文内容の確認"
                    description="内容をご確認のうえ、お支払いへお進みください。"
                />

                <section className="overflow-hidden rounded-xl border">
                    <h2 className="border-b bg-muted/40 p-4 font-semibold">
                        {creator.display_name}
                    </h2>
                    <ul className="divide-y text-sm">
                        {group.items.map((item) => (
                            <li
                                key={item.id}
                                className="flex justify-between gap-4 p-4"
                            >
                                <span>
                                    {item.name}
                                    {item.kind === 'digital'
                                        ? '（デジタル）'
                                        : ` × ${item.quantity}`}
                                </span>
                                <span>
                                    {formatYen(item.price * item.quantity)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    <dl className="space-y-1 border-t p-4 text-sm">
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">小計</dt>
                            <dd>{formatYen(group.subtotal)}</dd>
                        </div>
                        <div className="flex justify-between">
                            <dt className="text-muted-foreground">送料</dt>
                            <dd>{formatYen(group.shipping_fee)}</dd>
                        </div>
                        {supportAmount > 0 && (
                            <div className="flex justify-between">
                                <dt className="text-muted-foreground">応援</dt>
                                <dd>{formatYen(supportAmount)}</dd>
                            </div>
                        )}
                        <div className="flex justify-between text-base font-bold">
                            <dt>合計（税込）</dt>
                            <dd>{formatYen(group.total + supportAmount)}</dd>
                        </div>
                    </dl>
                </section>

                {support !== null && (
                    <section className="space-y-3 rounded-xl border p-4">
                        <h2 className="font-semibold">
                            クリエイターを応援する（任意）
                        </h2>
                        <ToggleGroup
                            type="single"
                            variant="outline"
                            value={choice}
                            onValueChange={(value) => value && setChoice(value)}
                            className="flex-wrap justify-start gap-2"
                            aria-label="応援額"
                        >
                            <ToggleGroupItem value="none">なし</ToggleGroupItem>
                            {support.presets.map((amount) => (
                                <ToggleGroupItem
                                    key={amount}
                                    value={String(amount)}
                                >
                                    {formatYen(amount)}
                                </ToggleGroupItem>
                            ))}
                            <ToggleGroupItem value="custom">
                                金額を入力
                            </ToggleGroupItem>
                        </ToggleGroup>
                        {choice === 'custom' && (
                            <Input
                                type="number"
                                inputMode="numeric"
                                min={support.min}
                                max={support.max}
                                step={1}
                                value={custom}
                                onChange={(e) => setCustom(e.target.value)}
                                placeholder={`${support.min}〜${support.max}円`}
                                aria-label="応援額（円）"
                                className="max-w-48"
                            />
                        )}
                        <InputError message={supportError} />
                        <p className="text-xs text-muted-foreground">
                            応援額は全額返金の場合を除き返金されません。応援によって特典は付きません
                        </p>
                    </section>
                )}

                <dl className="divide-y rounded-xl border text-sm">
                    {rows.map(([label, value]) => (
                        <div
                            key={label}
                            className="grid gap-1 p-4 sm:grid-cols-[10rem_1fr]"
                        >
                            <dt className="text-muted-foreground">{label}</dt>
                            <dd>{value}</dd>
                        </div>
                    ))}
                    <div className="grid gap-1 p-4 sm:grid-cols-[10rem_1fr]">
                        <dt className="text-muted-foreground">返品</dt>
                        <dd>
                            返品・交換については、
                            <Link href={tokushoho()} className="underline">
                                特定商取引法に基づく表記
                            </Link>
                            をご確認ください。
                        </dd>
                    </div>
                </dl>

                <div className="space-y-2">
                    <Button
                        className="w-full"
                        disabled={processing}
                        onClick={proceed}
                    >
                        支払いへ進む
                    </Button>
                    <Button
                        asChild
                        className="w-full"
                        variant="ghost"
                        size="sm"
                    >
                        <Link href={cartIndex()}>カートに戻る</Link>
                    </Button>
                </div>
            </div>
        </>
    );
}
