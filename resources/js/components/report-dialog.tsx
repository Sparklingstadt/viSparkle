import { Form, usePage } from '@inertiajs/react';
import { useState } from 'react';
import ReportController from '@/actions/App/Http/Controllers/ReportController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';

export type ReportReasonOption = { value: string; label: string };

// A small "report" link for logged-in viewers who don't own the target.
export function ReportDialog({
    targetType,
    targetId,
    reasons,
    isOwner,
}: {
    targetType: 'post' | 'creator' | 'product';
    targetId: number;
    reasons: ReportReasonOption[];
    isOwner: boolean;
}) {
    const { auth } = usePage().props;
    const [open, setOpen] = useState(false);

    if (!auth?.user || isOwner) {
        return null;
    }

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    variant="link"
                    size="sm"
                    className="h-auto p-0 text-xs text-muted-foreground"
                    data-test="report-button"
                >
                    通報する
                </Button>
            </DialogTrigger>
            <DialogContent>
                <DialogTitle>
                    {targetType === 'post'
                        ? 'この投稿を通報する'
                        : targetType === 'product'
                          ? 'この商品を通報する'
                          : 'このクリエイターを通報する'}
                </DialogTitle>
                <DialogDescription>
                    理由を選んでください。運営が内容を確認します。
                </DialogDescription>

                <Form
                    {...ReportController.store.form()}
                    options={{ preserveScroll: true }}
                    onSuccess={() => setOpen(false)}
                    resetOnSuccess
                    className="space-y-4"
                >
                    {({ processing, errors }) => (
                        <>
                            <input
                                type="hidden"
                                name="target_type"
                                value={targetType}
                            />
                            <input
                                type="hidden"
                                name="target_id"
                                value={targetId}
                            />

                            <fieldset className="grid gap-2">
                                <legend className="sr-only">理由</legend>
                                {reasons.map((reason) => (
                                    <Label
                                        key={reason.value}
                                        className="flex items-center gap-2 font-normal"
                                    >
                                        <input
                                            type="radio"
                                            name="reason"
                                            value={reason.value}
                                            required
                                        />
                                        {reason.label}
                                    </Label>
                                ))}
                                <InputError message={errors.reason} />
                            </fieldset>

                            <div className="grid gap-2">
                                <Label htmlFor="report-body">
                                    詳細（任意）
                                </Label>
                                <Textarea
                                    id="report-body"
                                    name="body"
                                    rows={4}
                                    maxLength={1000}
                                />
                                <InputError message={errors.body} />
                                <InputError message={errors.target_id} />
                            </div>

                            <DialogFooter className="gap-2">
                                <DialogClose asChild>
                                    <Button variant="secondary">
                                        キャンセル
                                    </Button>
                                </DialogClose>
                                <Button
                                    type="submit"
                                    disabled={processing}
                                    data-test="report-submit"
                                >
                                    通報する
                                </Button>
                            </DialogFooter>
                        </>
                    )}
                </Form>
            </DialogContent>
        </Dialog>
    );
}
