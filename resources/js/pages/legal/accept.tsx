import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/actions/App/Http/Controllers/Legal/AcceptTermsController';
import { privacy, terms } from '@/routes/legal';

export default function AcceptTerms({ version }: { version: string }) {
    return (
        <>
            <Head title="利用規約の改定" />

            <div className="mx-auto max-w-xl space-y-6 p-6">
                <h1 className="text-2xl font-bold">利用規約の改定</h1>
                <p className="text-sm leading-6">
                    利用規約・プライバシーポリシーが改定されました（版：
                    {version}
                    ）。内容をご確認のうえ、同意してサービスをご利用ください。
                </p>
                <ul className="list-inside list-disc text-sm">
                    <li>
                        <a
                            href={terms.url()}
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            利用規約
                        </a>
                    </li>
                    <li>
                        <a
                            href={privacy.url()}
                            target="_blank"
                            rel="noreferrer"
                            className="underline"
                        >
                            プライバシーポリシー
                        </a>
                    </li>
                </ul>

                <Form {...store.form()}>
                    {({ processing }) => (
                        <Button type="submit" disabled={processing}>
                            {processing && <Spinner />}
                            同意して続ける
                        </Button>
                    )}
                </Form>
            </div>
        </>
    );
}
