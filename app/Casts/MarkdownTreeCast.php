<?php

namespace App\Casts;

use App\Markdown\Tree\MarkdownTree;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use InvalidArgumentException;

final class MarkdownTreeCast implements CastsAttributes
{
    public function get($model, string $key, $value, array $attributes): MarkdownTree
    {
        if ($value instanceof MarkdownTree) {
            return $value;
        }

        if (is_array($value)) {
            return MarkdownTree::fromArray($value);
        }

        if (is_string($value) && $value !== '') {
            try {
                return MarkdownTree::fromJson($value);
            } catch (InvalidArgumentException $exception) {
                return MarkdownTree::fromPlainText($value);
            }
        }

        return MarkdownTree::empty();
    }

    public function set($model, string $key, $value, array $attributes): array
    {
        if ($value instanceof MarkdownTree) {
            return [$key => $value->toJson()];
        }

        if (is_array($value)) {
            $tree = MarkdownTree::fromArray($value);
            return [$key => $tree->toJson()];
        }

        if (is_string($value)) {
            try {
                $tree = MarkdownTree::fromJson($value);
            } catch (InvalidArgumentException $exception) {
                $tree = MarkdownTree::fromPlainText($value);
            }

            return [$key => $tree->toJson()];
        }

        if ($value === null) {
            return [$key => null];
        }

        return [$key => MarkdownTree::empty()->toJson()];
    }
}
