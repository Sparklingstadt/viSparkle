<?php

use App\Services\Tiptap\TiptapDocumentValidator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

uses(TestCase::class);

function tiptap(): TiptapDocumentValidator
{
    return new TiptapDocumentValidator;
}

function ttText(string $value, array $marks = []): array
{
    return array_filter(['type' => 'text', 'text' => $value, 'marks' => $marks]);
}

function ttDoc(array ...$nodes): array
{
    return ['type' => 'doc', 'content' => $nodes];
}

function ttParagraph(array ...$inline): array
{
    return ['type' => 'paragraph', 'content' => $inline];
}

function ttImage(int $fileId): array
{
    return ['type' => 'image', 'attrs' => ['fileId' => $fileId]];
}

function ttNested(int $depth): array
{
    $node = ttParagraph(ttText('deep'));
    for ($i = 0; $i < $depth; $i++) {
        $node = ['type' => 'blockquote', 'content' => [$node]];
    }

    return ttDoc($node);
}

function ttValidDoc(): array
{
    return ttDoc(
        ['type' => 'heading', 'attrs' => ['level' => 2], 'content' => [ttText('見出し')]],
        ttParagraph(
            ttText('太字', [['type' => 'bold']]),
            ttText('リンク', [['type' => 'link', 'attrs' => ['href' => 'https://example.com', 'target' => '_blank', 'rel' => 'noopener']]]),
            ['type' => 'hardBreak'],
            ttText('斜体と打ち消し', [['type' => 'italic'], ['type' => 'strike']]),
        ),
        ['type' => 'bulletList', 'content' => [['type' => 'listItem', 'content' => [ttParagraph(ttText('a'))]]]],
        ['type' => 'orderedList', 'attrs' => ['start' => 1], 'content' => [['type' => 'listItem', 'content' => [ttParagraph(ttText('b'))]]]],
        ['type' => 'blockquote', 'content' => [ttParagraph(ttText('引用'))]],
        ['type' => 'horizontalRule'],
        ttImage(5),
        ['type' => 'paragraph'],
    );
}

it('accepts a valid document', function () {
    tiptap()->validate(ttValidDoc(), [5]);

    expect(true)->toBeTrue();
});

it('rejects invalid documents', function (array $doc) {
    expect(fn () => tiptap()->validate($doc, [5]))->toThrow(ValidationException::class);
})->with([
    'not a doc root' => [ttParagraph(ttText('x'))],
    'unknown node' => [ttDoc(['type' => 'iframe'])],
    'code block' => [ttDoc(['type' => 'codeBlock', 'content' => [ttText('x')]])],
    'unknown mark' => [ttDoc(ttParagraph(ttText('x', [['type' => 'code']])))],
    'javascript link' => [ttDoc(ttParagraph(ttText('x', [['type' => 'link', 'attrs' => ['href' => 'javascript:alert(1)']]])))],
    'data link' => [ttDoc(ttParagraph(ttText('x', [['type' => 'link', 'attrs' => ['href' => 'data:text/html,hi']]])))],
    'protocol-relative link' => [ttDoc(ttParagraph(ttText('x', [['type' => 'link', 'attrs' => ['href' => '//evil.test']]])))],
    'link with onclick attr' => [ttDoc(ttParagraph(ttText('x', [['type' => 'link', 'attrs' => ['href' => 'https://a.test', 'onclick' => 'x']]])))],
    'h1' => [ttDoc(['type' => 'heading', 'attrs' => ['level' => 1], 'content' => [ttText('x')]])],
    'image with src' => [ttDoc(['type' => 'image', 'attrs' => ['fileId' => 5, 'src' => 'https://evil.test/a.png']])],
    'image from another post' => [ttDoc(ttImage(99))],
    'image id as string' => [ttDoc(['type' => 'image', 'attrs' => ['fileId' => '5']])],
    'unknown node key' => [ttDoc(['type' => 'paragraph', 'onclick' => 'x'])],
    'text without string' => [ttDoc(ttParagraph(['type' => 'text', 'text' => ['x']]))],
    'content not a list' => [['type' => 'doc', 'content' => 'x']],
    'too deep' => [ttNested(21)],
]);

it('rejects oversized bodies', function () {
    config(['fanbox.body_max_bytes' => 100]);

    expect(fn () => tiptap()->validate(ttDoc(ttParagraph(ttText(str_repeat('a', 200)))), []))
        ->toThrow(ValidationException::class);
});

it('reports errors under the body key', function () {
    try {
        tiptap()->validate(ttDoc(['type' => 'iframe']), []);
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('body');

        return;
    }

    $this->fail('Expected a ValidationException.');
});

it('extracts unique image ids in order', function () {
    expect(tiptap()->imageIds(ttDoc(ttImage(3), ttParagraph(ttText('x')), ttImage(5), ttImage(3))))->toBe([3, 5])
        ->and(tiptap()->imageIds(ttDoc()))->toBe([]);
});
