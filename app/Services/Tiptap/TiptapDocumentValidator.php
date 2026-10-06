<?php

namespace App\Services\Tiptap;

use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Allow-list validation for Tiptap JSON post bodies.
 *
 * Anything not explicitly allowed (node types, marks, keys, attributes) is
 * rejected, so the stored document can be rendered without HTML injection.
 * Images reference post files by id only; URLs are resolved at render time.
 */
class TiptapDocumentValidator
{
    private const MAX_DEPTH = 20;

    private const NODE_KEYS = ['type', 'content', 'text', 'marks', 'attrs'];

    /** @var list<int> */
    private array $allowedImageIds = [];

    /**
     * @param  array<mixed>  $doc
     * @param  array<int>  $allowedImageIds  ids of this post's image files
     *
     * @throws ValidationException
     */
    public function validate(array $doc, array $allowedImageIds): void
    {
        if (strlen((string) json_encode($doc)) > config('fanbox.body_max_bytes')) {
            $this->fail('本文が長すぎます。');
        }

        if (($doc['type'] ?? null) !== 'doc') {
            $this->fail('本文の形式が正しくありません。');
        }

        $this->allowedImageIds = array_map('intval', $allowedImageIds);
        $this->validateNode($doc, 0);
    }

    /**
     * @param  array<mixed>  $doc
     * @return list<int>
     */
    public function imageIds(array $doc): array
    {
        $ids = [];
        $walk = function (mixed $node) use (&$walk, &$ids): void {
            if (! is_array($node)) {
                return;
            }

            if (($node['type'] ?? null) === 'image' && is_int($node['attrs']['fileId'] ?? null)) {
                $ids[] = $node['attrs']['fileId'];
            }

            foreach (is_array($node['content'] ?? null) ? $node['content'] : [] as $child) {
                $walk($child);
            }
        };
        $walk($doc);

        return array_values(array_unique($ids));
    }

    /**
     * @return array<string, array{attrs: array<string, Closure(mixed): bool>, content: bool}>
     */
    private function nodes(): array
    {
        $int = fn (mixed $v) => is_int($v);

        return [
            'doc' => ['attrs' => [], 'content' => true],
            'paragraph' => ['attrs' => [], 'content' => true],
            'heading' => ['attrs' => ['level' => fn (mixed $v) => in_array($v, [2, 3], true)], 'content' => true],
            'bulletList' => ['attrs' => [], 'content' => true],
            'orderedList' => ['attrs' => ['start' => $int, 'type' => fn (mixed $v) => $v === null], 'content' => true],
            'listItem' => ['attrs' => [], 'content' => true],
            'blockquote' => ['attrs' => [], 'content' => true],
            'horizontalRule' => ['attrs' => [], 'content' => false],
            'hardBreak' => ['attrs' => [], 'content' => false],
            'text' => ['attrs' => [], 'content' => false],
            'image' => ['attrs' => ['fileId' => fn (mixed $v) => is_int($v) && in_array($v, $this->allowedImageIds, true)], 'content' => false],
        ];
    }

    /**
     * @return array<string, array<string, Closure(mixed): bool>>
     */
    private function marks(): array
    {
        $nullableString = fn (mixed $v) => $v === null || is_string($v);

        return [
            'bold' => [],
            'italic' => [],
            'strike' => [],
            'link' => [
                'href' => fn (mixed $v) => is_string($v) && preg_match('#^https?://[^\s]+$#i', $v) === 1,
                'target' => $nullableString,
                'rel' => $nullableString,
                'class' => fn (mixed $v) => $v === null,
            ],
        ];
    }

    private function validateNode(mixed $node, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            $this->fail('本文の入れ子が深すぎます。');
        }

        if (! is_array($node) || ! is_string($node['type'] ?? null)) {
            $this->fail('本文の形式が正しくありません。');
        }

        $spec = $this->nodes()[$node['type']] ?? $this->fail("使用できない要素が含まれています（{$node['type']}）。");

        if (array_diff(array_keys($node), self::NODE_KEYS) !== []) {
            $this->fail('本文の形式が正しくありません。');
        }

        if ($node['type'] === 'image' && ! array_key_exists('fileId', $node['attrs'] ?? [])) {
            $this->fail('画像の指定が正しくありません。');
        }

        $this->validateAttrs($node['attrs'] ?? null, $spec['attrs']);

        if ($node['type'] === 'text') {
            if (! is_string($node['text'] ?? null)) {
                $this->fail('本文の形式が正しくありません。');
            }

            $this->validateMarks($node['marks'] ?? null);

            return;
        }

        if (array_key_exists('text', $node) || array_key_exists('marks', $node)) {
            $this->fail('本文の形式が正しくありません。');
        }

        if (array_key_exists('content', $node)) {
            if (! $spec['content'] || ! is_array($node['content']) || ! array_is_list($node['content'])) {
                $this->fail('本文の形式が正しくありません。');
            }

            foreach ($node['content'] as $child) {
                $this->validateNode($child, $depth + 1);
            }
        }
    }

    /**
     * @param  array<string, Closure(mixed): bool>  $allowed
     */
    private function validateAttrs(mixed $attrs, array $allowed): void
    {
        if ($attrs === null) {
            return;
        }

        if (! is_array($attrs)) {
            $this->fail('本文の形式が正しくありません。');
        }

        foreach ($attrs as $key => $value) {
            if (! isset($allowed[$key]) || ! $allowed[$key]($value)) {
                $this->fail('本文に使用できない属性が含まれています。');
            }
        }
    }

    private function validateMarks(mixed $marks): void
    {
        if ($marks === null) {
            return;
        }

        if (! is_array($marks) || ! array_is_list($marks)) {
            $this->fail('本文の形式が正しくありません。');
        }

        foreach ($marks as $mark) {
            if (! is_array($mark) || ! is_string($mark['type'] ?? null) || array_diff(array_keys($mark), ['type', 'attrs']) !== []) {
                $this->fail('本文の形式が正しくありません。');
            }

            $allowed = $this->marks()[$mark['type']] ?? $this->fail("使用できない書式が含まれています（{$mark['type']}）。");

            if ($mark['type'] === 'link' && ! isset($mark['attrs']['href'])) {
                $this->fail('リンクのURLが正しくありません。');
            }

            $this->validateAttrs($mark['attrs'] ?? null, $allowed);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['body' => $message]);
    }
}
