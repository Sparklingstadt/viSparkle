import { mergeAttributes } from '@tiptap/core';
import Image from '@tiptap/extension-image';
import type { ImageOptions } from '@tiptap/extension-image';
import type { Editor } from '@tiptap/react';
import { EditorContent, useEditor, useEditorState } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import {
    Bold,
    Heading2,
    Heading3,
    ImagePlus,
    Italic,
    Link2,
    List,
    ListOrdered,
    Minus,
    Quote,
    Redo2,
    Strikethrough,
    Undo2,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useRef } from 'react';
import { Toggle } from '@/components/ui/toggle';
import type { TiptapNode } from '@/types';

type ResolveUrl = (fileId: number) => string | undefined;

/**
 * Image node that stores only the post file id; the (short-lived, signed) URL is resolved at render time.
 */
const PostImage = Image.extend<ImageOptions & { resolveUrl: ResolveUrl }>({
    addOptions() {
        return {
            ...this.parent!(),
            resolveUrl: () => undefined,
        };
    },
    addAttributes() {
        return {
            fileId: {
                default: null,
                parseHTML: (element) =>
                    Number(element.getAttribute('data-file-id')) || null,
                renderHTML: (attributes) => ({
                    'data-file-id': attributes.fileId as number,
                }),
            },
        };
    },
    renderHTML({ node, HTMLAttributes }) {
        return [
            'img',
            mergeAttributes(this.options.HTMLAttributes, HTMLAttributes, {
                src: this.options.resolveUrl(node.attrs.fileId as number) ?? '',
            }),
        ];
    },
});

function ToolbarButton({
    label,
    active = false,
    disabled = false,
    onClick,
    children,
}: {
    label: string;
    active?: boolean;
    disabled?: boolean;
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <Toggle
            size="sm"
            aria-label={label}
            title={label}
            pressed={active}
            disabled={disabled}
            onPressedChange={onClick}
        >
            {children}
        </Toggle>
    );
}

function Toolbar({
    editor,
    onPickImage,
}: {
    editor: Editor;
    onPickImage: () => void;
}) {
    const state = useEditorState({
        editor,
        selector: ({ editor: e }) => ({
            h2: e.isActive('heading', { level: 2 }),
            h3: e.isActive('heading', { level: 3 }),
            bold: e.isActive('bold'),
            italic: e.isActive('italic'),
            strike: e.isActive('strike'),
            link: e.isActive('link'),
            bullet: e.isActive('bulletList'),
            ordered: e.isActive('orderedList'),
            quote: e.isActive('blockquote'),
            canUndo: e.can().undo(),
            canRedo: e.can().redo(),
        }),
    });

    const setLink = () => {
        if (state.link) {
            editor.chain().focus().unsetLink().run();

            return;
        }

        const href = window.prompt('リンク先のURL（https://〜）');

        if (href && /^https?:\/\//i.test(href)) {
            editor.chain().focus().setLink({ href }).run();
        }
    };

    return (
        <div className="flex flex-wrap gap-1 border-b p-1">
            <ToolbarButton
                label="見出し（大）"
                active={state.h2}
                onClick={() =>
                    editor.chain().focus().toggleHeading({ level: 2 }).run()
                }
            >
                <Heading2 />
            </ToolbarButton>
            <ToolbarButton
                label="見出し（小）"
                active={state.h3}
                onClick={() =>
                    editor.chain().focus().toggleHeading({ level: 3 }).run()
                }
            >
                <Heading3 />
            </ToolbarButton>
            <ToolbarButton
                label="太字"
                active={state.bold}
                onClick={() => editor.chain().focus().toggleBold().run()}
            >
                <Bold />
            </ToolbarButton>
            <ToolbarButton
                label="斜体"
                active={state.italic}
                onClick={() => editor.chain().focus().toggleItalic().run()}
            >
                <Italic />
            </ToolbarButton>
            <ToolbarButton
                label="打ち消し線"
                active={state.strike}
                onClick={() => editor.chain().focus().toggleStrike().run()}
            >
                <Strikethrough />
            </ToolbarButton>
            <ToolbarButton label="リンク" active={state.link} onClick={setLink}>
                <Link2 />
            </ToolbarButton>
            <ToolbarButton
                label="箇条書き"
                active={state.bullet}
                onClick={() => editor.chain().focus().toggleBulletList().run()}
            >
                <List />
            </ToolbarButton>
            <ToolbarButton
                label="番号付きリスト"
                active={state.ordered}
                onClick={() => editor.chain().focus().toggleOrderedList().run()}
            >
                <ListOrdered />
            </ToolbarButton>
            <ToolbarButton
                label="引用"
                active={state.quote}
                onClick={() => editor.chain().focus().toggleBlockquote().run()}
            >
                <Quote />
            </ToolbarButton>
            <ToolbarButton
                label="区切り線"
                onClick={() => editor.chain().focus().setHorizontalRule().run()}
            >
                <Minus />
            </ToolbarButton>
            <ToolbarButton label="画像を挿入" onClick={onPickImage}>
                <ImagePlus />
            </ToolbarButton>
            <ToolbarButton
                label="元に戻す"
                disabled={!state.canUndo}
                onClick={() => editor.chain().focus().undo().run()}
            >
                <Undo2 />
            </ToolbarButton>
            <ToolbarButton
                label="やり直す"
                disabled={!state.canRedo}
                onClick={() => editor.chain().focus().redo().run()}
            >
                <Redo2 />
            </ToolbarButton>
        </div>
    );
}

export function PostEditor({
    initialContent,
    imageUrls,
    onChange,
    onUploadImage,
}: {
    initialContent: TiptapNode | null;
    imageUrls: Record<number, string>;
    onChange: (doc: TiptapNode) => void;
    onUploadImage: (file: File) => Promise<number | null>;
}) {
    const urlsRef = useRef(imageUrls);
    urlsRef.current = imageUrls;
    const fileInput = useRef<HTMLInputElement>(null);

    const insertImages = async (editor: Editor, files: File[]) => {
        for (const file of files.filter((f) => f.type.startsWith('image/'))) {
            const fileId = await onUploadImage(file);

            if (fileId !== null) {
                editor
                    .chain()
                    .focus()
                    .insertContent({ type: 'image', attrs: { fileId } })
                    .run();
            }
        }
    };

    const editor = useEditor({
        extensions: [
            StarterKit.configure({
                heading: { levels: [2, 3] },
                code: false,
                codeBlock: false,
                underline: false,
                link: {
                    openOnClick: false,
                    autolink: true,
                    protocols: ['http', 'https'],
                    isAllowedUri: (url) => /^https?:\/\//i.test(url),
                },
            }),
            PostImage.configure({
                resolveUrl: (fileId) => urlsRef.current[fileId],
            }),
        ],
        content: initialContent ?? undefined,
        immediatelyRender: false,
        editorProps: {
            attributes: {
                class: 'prose dark:prose-invert max-w-none min-h-80 p-4 focus:outline-none',
            },
            handleDrop: (view, event) => {
                const files = Array.from(event.dataTransfer?.files ?? []);

                if (files.length === 0 || !editorRef.current) {
                    return false;
                }

                event.preventDefault();
                void insertImages(editorRef.current, files);

                return true;
            },
            handlePaste: (view, event) => {
                const files = Array.from(event.clipboardData?.files ?? []);

                if (files.length === 0 || !editorRef.current) {
                    return false;
                }

                void insertImages(editorRef.current, files);

                return true;
            },
        },
        onUpdate: ({ editor: e }) => onChange(e.getJSON() as TiptapNode),
    });
    const editorRef = useRef<Editor | null>(null);
    editorRef.current = editor;

    if (!editor) {
        return <div className="min-h-80 rounded-md border" />;
    }

    return (
        <div className="rounded-md border">
            <Toolbar
                editor={editor}
                onPickImage={() => fileInput.current?.click()}
            />
            <EditorContent editor={editor} />
            <input
                ref={fileInput}
                type="file"
                accept="image/jpeg,image/png,image/gif,image/webp"
                multiple
                hidden
                onChange={(event) => {
                    void insertImages(
                        editor,
                        Array.from(event.target.files ?? []),
                    );
                    event.target.value = '';
                }}
            />
        </div>
    );
}
