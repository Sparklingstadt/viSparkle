import { Head } from '@inertiajs/react';

export default function LegalDocument({
    title,
    version,
    html,
}: {
    title: string;
    version: string;
    html: string;
}) {
    return (
        <>
            <Head title={title} />

            <article className="mx-auto max-w-3xl space-y-6">
                <header className="space-y-1">
                    <h1 className="text-2xl font-bold">{title}</h1>
                    <p className="text-sm text-muted-foreground">
                        版：{version}
                    </p>
                </header>

                <div
                    className="prose max-w-none dark:prose-invert"
                    // The source is our own Markdown in resources/legal, rendered with raw HTML stripped.
                    dangerouslySetInnerHTML={{ __html: html }}
                />
            </article>
        </>
    );
}
