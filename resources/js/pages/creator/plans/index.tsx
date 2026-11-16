import { Form, Head, Link, usePage } from '@inertiajs/react';
import { useState } from 'react';
import PlanController from '@/actions/App/Http/Controllers/Creator/PlanController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { PlanCard } from '@/components/plan-card';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/creator/plans';
import type { PlanData } from '@/types';

type Limits = { min: number; max: number; count: number };

function PlanFields({
    plan,
    errors,
}: {
    plan?: PlanData;
    errors: Record<string, string>;
}) {
    const prefix = plan ? `plan-${plan.id}` : 'new-plan';

    return (
        <>
            <div className="grid gap-2">
                <Label htmlFor={`${prefix}-name`}>プラン名</Label>
                <Input
                    id={`${prefix}-name`}
                    name="name"
                    defaultValue={plan?.name}
                    required
                    maxLength={50}
                />
                <InputError message={errors.name} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${prefix}-description`}>説明</Label>
                <textarea
                    id={`${prefix}-description`}
                    name="description"
                    defaultValue={plan?.description ?? ''}
                    maxLength={1000}
                    rows={3}
                    className="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs"
                />
                <InputError message={errors.description} />
            </div>
            <div className="grid gap-2">
                <Label htmlFor={`${prefix}-cover`}>カバー画像</Label>
                <Input
                    id={`${prefix}-cover`}
                    name="cover"
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                />
                <InputError message={errors.cover} />
            </div>
        </>
    );
}

function EditPlan({ plan, onDone }: { plan: PlanData; onDone: () => void }) {
    return (
        <Form
            {...PlanController.update.form(plan.id)}
            options={{ preserveScroll: true }}
            onSuccess={onDone}
            className="mt-2 space-y-4 border-t pt-4"
        >
            {({ processing, errors }) => (
                <>
                    <PlanFields plan={plan} errors={errors} />
                    <div className="flex items-center gap-2">
                        <Checkbox
                            id={`plan-${plan.id}-accepting`}
                            name="is_accepting"
                            value="1"
                            defaultChecked={plan.is_accepting}
                        />
                        <Label htmlFor={`plan-${plan.id}-accepting`}>
                            新規の支援を受け付ける
                        </Label>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        金額は変更できません。変更したい場合は新しいプランを作成してください。
                    </p>
                    <div className="flex gap-2">
                        <Button size="sm" disabled={processing}>
                            保存
                        </Button>
                        <Button
                            size="sm"
                            type="button"
                            variant="ghost"
                            onClick={onDone}
                        >
                            キャンセル
                        </Button>
                    </div>
                </>
            )}
        </Form>
    );
}

export default function PlansIndex({
    plans,
    limits,
}: {
    plans: PlanData[];
    limits: Limits;
}) {
    const [editing, setEditing] = useState<number | null>(null);
    const { errors } = usePage<{ errors: Record<string, string> }>().props;

    return (
        <>
            <Head title="プラン管理" />

            <div className="space-y-8 p-4">
                <Heading
                    title="プラン管理"
                    description={`月額 ${limits.min.toLocaleString()}〜${limits.max.toLocaleString()} 円、最大 ${limits.count} 個まで作成できます。`}
                />

                <InputError message={errors.plan} />

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    {plans.map((plan) => (
                        <PlanCard
                            key={plan.id}
                            plan={plan}
                            footer={
                                <>
                                    <p className="text-sm text-muted-foreground">
                                        支援者 {plan.active_supporters_count} 人
                                    </p>
                                    {editing === plan.id ? (
                                        <EditPlan
                                            plan={plan}
                                            onDone={() => setEditing(null)}
                                        />
                                    ) : (
                                        <div className="flex gap-2 pt-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setEditing(plan.id)
                                                }
                                            >
                                                編集
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                asChild
                                            >
                                                <Link
                                                    href={PlanController.destroy(
                                                        plan.id,
                                                    )}
                                                    as="button"
                                                    preserveScroll
                                                    onBefore={() =>
                                                        confirm(
                                                            'このプランを削除しますか？',
                                                        )
                                                    }
                                                >
                                                    削除
                                                </Link>
                                            </Button>
                                        </div>
                                    )}
                                </>
                            }
                        />
                    ))}
                </div>

                {plans.length < limits.count && (
                    <section className="max-w-xl space-y-4">
                        <Heading variant="small" title="新しいプランを作成" />
                        <Form
                            {...PlanController.store.form()}
                            options={{ preserveScroll: true }}
                            resetOnSuccess
                            className="space-y-4"
                        >
                            {({ processing, errors: formErrors }) => (
                                <>
                                    <PlanFields errors={formErrors} />
                                    <div className="grid gap-2">
                                        <Label htmlFor="new-plan-price">
                                            月額（円）
                                        </Label>
                                        <Input
                                            id="new-plan-price"
                                            name="price"
                                            type="number"
                                            min={limits.min}
                                            max={limits.max}
                                            step={1}
                                            required
                                        />
                                        <p className="text-xs text-muted-foreground">
                                            作成後は変更できません。
                                        </p>
                                        <InputError
                                            message={formErrors.price}
                                        />
                                    </div>
                                    <Button disabled={processing}>
                                        作成する
                                    </Button>
                                </>
                            )}
                        </Form>
                    </section>
                )}
            </div>
        </>
    );
}

PlansIndex.layout = {
    breadcrumbs: [{ title: 'プラン管理', href: index() }],
};
