import type { ReactNode } from 'react';
import type { TiptapMark, TiptapNode } from '@/types';

/**
 * Renders a validated Tiptap document as React elements (never as raw HTML).
 * Unknown node types and marks are dropped.
 */
function applyMarks(text: ReactNode, marks: TiptapMark[] = []): ReactNode {
    return marks.reduce<ReactNode>((child, mark) => {
        switch (mark.type) {
            case 'bold':
                return <strong>{child}</strong>;
            case 'italic':
                return <em>{child}</em>;
            case 'strike':
                return <s>{child}</s>;
            case 'link': {
                const href = String(mark.attrs?.href ?? '');

                return /^https?:\/\//i.test(href) ? (
                    <a
                        href={href}
                        target="_blank"
                        rel="noopener noreferrer nofollow"
                    >
                        {child}
                    </a>
                ) : (
                    child
                );
            }
            default:
                return child;
        }
    }, text);
}

function renderNodes(
    nodes: TiptapNode[] | undefined,
    imageUrls: Record<string, string>,
): ReactNode[] {
    return (nodes ?? []).map((node, index) =>
        renderNode(node, imageUrls, index),
    );
}

function renderNode(
    node: TiptapNode,
    imageUrls: Record<string, string>,
    key: number,
): ReactNode {
    const children = () => renderNodes(node.content, imageUrls);

    switch (node.type) {
        case 'paragraph':
            return <p key={key}>{children()}</p>;
        case 'heading':
            return node.attrs?.level === 3 ? (
                <h3 key={key}>{children()}</h3>
            ) : (
                <h2 key={key}>{children()}</h2>
            );
        case 'bulletList':
            return <ul key={key}>{children()}</ul>;
        case 'orderedList':
            return (
                <ol key={key} start={Number(node.attrs?.start ?? 1)}>
                    {children()}
                </ol>
            );
        case 'listItem':
            return <li key={key}>{children()}</li>;
        case 'blockquote':
            return <blockquote key={key}>{children()}</blockquote>;
        case 'horizontalRule':
            return <hr key={key} />;
        case 'hardBreak':
            return <br key={key} />;
        case 'text':
            return (
                <span key={key}>{applyMarks(node.text ?? '', node.marks)}</span>
            );
        case 'image': {
            const url = imageUrls[String(node.attrs?.fileId)];

            return url ? (
                <img key={key} src={url} alt="" loading="lazy" />
            ) : null;
        }
        default:
            return null;
    }
}

export function PostRenderer({
    doc,
    imageUrls,
}: {
    doc: TiptapNode | null | undefined;
    imageUrls: Record<string, string>;
}) {
    if (!doc) {
        return null;
    }

    return (
        <div className="prose max-w-none dark:prose-invert">
            {renderNodes(doc.content, imageUrls)}
        </div>
    );
}
