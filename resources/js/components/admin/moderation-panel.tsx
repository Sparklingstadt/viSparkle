import { Form } from '@inertiajs/react';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDate } from '@/lib/format';
import type { RouteFormDefinition } from '@/wayfinder';

/**
 * Hiding a post or a product as the admin: the hidden state with a button to show it again, or the form to hide it
 * with a reason the creator sees. `form` is the item's admin update route.
 */
export function ModerationPanel({
    hidden,
    hiddenAt,
    hiddenReason,
    form,
}: {
    hidden: boolean;
    hiddenAt: string | null;
    hiddenReason: string | null;
    form: RouteFormDefinition<'post'>;
}) {
    if (hidden) {
        return (
            <section className="space-y-3">
                <Alert>
                    <AlertDescription>
                        {formatDate(hiddenAt)} に非公開にしました（理由:{' '}
                        {hiddenReason}）
                    </AlertDescription>
                </Alert>
                <Form {...form} options={{ preserveScroll: true }}>
                    {({ processing }) => (
                        <>
                            <input type="hidden" name="hidden" value="0" />
                            <Button disabled={processing}>公開に戻す</Button>
                        </>
                    )}
                </Form>
            </section>
        );
    }

    return (
        <Form
            {...form}
            options={{ preserveScroll: true }}
            resetOnSuccess
            className="space-y-3"
        >
            {({ processing, errors }) => (
                <>
                    <input type="hidden" name="hidden" value="1" />
                    <div className="grid gap-2">
                        <Label htmlFor="hide-reason">
                            非公開にする理由（作者に表示されます）
                        </Label>
                        <Textarea
                            id="hide-reason"
                            name="reason"
                            rows={3}
                            required
                            maxLength={500}
                        />
                        <InputError message={errors.reason} />
                    </div>
                    <Button
                        type="submit"
                        variant="destructive"
                        disabled={processing}
                    >
                        非公開にする
                    </Button>
                </>
            )}
        </Form>
    );
}
