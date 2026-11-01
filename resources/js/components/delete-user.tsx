import { Form } from '@inertiajs/react';
import { useRef } from 'react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
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
import { formatYen } from '@/lib/format';

export type BillingSupport = { creator: string; price: number };

export default function DeleteUser({
    billingSupports,
}: {
    billingSupports: BillingSupport[];
}) {
    const passwordInput = useRef<HTMLInputElement>(null);

    return (
        <div className="space-y-6">
            <Heading
                variant="small"
                title="退会"
                description="アカウントと関連するすべてのデータを削除します"
            />
            <div className="space-y-4 rounded-lg border border-red-100 bg-red-50 p-4 dark:border-red-200/10 dark:bg-red-700/10">
                <div className="relative space-y-0.5 text-red-600 dark:text-red-100">
                    <p className="font-medium">Warning</p>
                    <p className="text-sm">
                        この操作は取り消せません。十分ご注意ください。
                    </p>
                </div>

                <Dialog>
                    <DialogTrigger asChild>
                        <Button
                            variant="destructive"
                            data-test="delete-user-button"
                        >
                            退会する
                        </Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogTitle>本当に退会しますか？</DialogTitle>
                        <DialogDescription>
                            退会すると、アカウントと関連するデータ（クリエイターページ、投稿、支援情報を含む）はすべて完全に削除されます。確認のため、パスワードを入力してください。
                        </DialogDescription>

                        {billingSupports.length > 0 && (
                            <div className="space-y-2 rounded-md border border-red-100 bg-red-50 p-3 text-sm text-red-700 dark:border-red-200/10 dark:bg-red-700/10 dark:text-red-100">
                                <ul className="list-inside list-disc">
                                    {billingSupports.map((support, index) => (
                                        <li key={index}>
                                            {support.creator}（月額
                                            {formatYen(support.price)}）
                                        </li>
                                    ))}
                                </ul>
                                <p>
                                    削除すると、これらの支援は直ちに解約されます。今月分の返金はありません。
                                </p>
                            </div>
                        )}

                        <Form
                            {...ProfileController.destroy.form()}
                            options={{
                                preserveScroll: true,
                            }}
                            onError={() => passwordInput.current?.focus()}
                            resetOnSuccess
                            className="space-y-6"
                        >
                            {({ resetAndClearErrors, processing, errors }) => (
                                <>
                                    <div className="grid gap-2">
                                        <Label
                                            htmlFor="password"
                                            className="sr-only"
                                        >
                                            パスワード
                                        </Label>

                                        <PasswordInput
                                            id="password"
                                            name="password"
                                            ref={passwordInput}
                                            placeholder="パスワード"
                                            autoComplete="current-password"
                                        />

                                        <InputError message={errors.password} />
                                    </div>

                                    <DialogFooter className="gap-2">
                                        <DialogClose asChild>
                                            <Button
                                                variant="secondary"
                                                onClick={() =>
                                                    resetAndClearErrors()
                                                }
                                            >
                                                キャンセル
                                            </Button>
                                        </DialogClose>

                                        <Button
                                            variant="destructive"
                                            disabled={processing}
                                            asChild
                                        >
                                            <button
                                                type="submit"
                                                data-test="confirm-delete-user-button"
                                            >
                                                退会する
                                            </button>
                                        </Button>
                                    </DialogFooter>
                                </>
                            )}
                        </Form>
                    </DialogContent>
                </Dialog>
            </div>
        </div>
    );
}
