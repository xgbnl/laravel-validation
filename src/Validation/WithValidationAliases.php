<?php

declare(strict_types=1);

namespace Elephant\Validation\Validation;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;

trait WithValidationAliases
{
    /**
     * @param array<string, string> $aliases
     */
    protected function applyAliasesToSafeData(array &$safeData, array $aliases): void
    {
        if ($aliases === []) {
            return;
        }

        $flat = [];
        $dotted = [];
        $wild = [];

        foreach ($aliases as $from => $to) {
            if ($from === $to) {
                continue;
            }

            $from = (string)$from;
            $to = (string)$to;

            if (str_contains($from, '*')) {
                $this->assertWildcardPairValid($from, $to);
                $wild[$from] = $to;
            } elseif (str_contains($from, '.')) {
                $dotted[$from] = $to;
            } else {
                $flat[$from] = $to;
            }
        }

        foreach (array_intersect_key($flat, $safeData) as $old => $alias) {
            $safeData[$alias] = $safeData[$old];
            unset($safeData[$old]);
        }

        foreach ($dotted as $old => $alias) {
            $this->applySinglePathAlias($safeData, $old, $alias);
        }

        foreach ($wild as $oldTpl => $newTpl) {
            $this->applyWildcardAliasPair($safeData, $oldTpl, $newTpl);
        }
    }

    private function assertWildcardPairValid(string $from, string $to): void
    {
        $rightHasStar = str_contains($to, '*');
        $rightHasDot = str_contains($to, '.');

        if (!$rightHasStar && !$rightHasDot) {
            return;
        }

        if (!$rightHasStar) {
            throw new InvalidArgumentException("Alias [{$from} => {$to}]：右侧含 . 时必须同时含对应位置的 *。");
        }

        if (!$this->wildcardTemplatesAligned($from, $to)) {
            throw new InvalidArgumentException("Alias [{$from} => {$to}]：* 位置与段数必须与左侧一致。");
        }
    }

    private function wildcardTemplatesAligned(string $from, string $to): bool
    {
        $a = explode('.', $from);
        $b = explode('.', $to);

        if (count($a) !== count($b)) {
            return false;
        }

        foreach ($a as $i => $seg) {
            if (($seg === '*') !== (($b[$i] ?? '') === '*')) {
                return false;
            }
        }

        return true;
    }

    private function applySinglePathAlias(array &$safeData, string $old, string $alias): void
    {
        if (!Arr::has($safeData, $old)) {
            return;
        }

        $value = data_get($safeData, $old);
        Arr::forget($safeData, $old);

        if (!str_contains($alias, '.')) {
            $parent = Str::beforeLast($old, '.');
            $path = $parent === '' ? $alias : "{$parent}.{$alias}";

            data_set($safeData, $path, $value);
        } else {
            data_set($safeData, $alias, $value);
        }
    }

    private function applyWildcardAliasPair(array &$safeData, string $oldTpl, string $newTpl): void
    {
        $paths = $this->collectConcretePathsForTemplate($safeData, $oldTpl);

        foreach ($paths as $oldPath) {
            if (!Arr::has($safeData, $oldPath)) {
                continue;
            }

            $captured = $this->extractWildcardSegments($oldTpl, $oldPath);

            if ($captured === null) {
                continue;
            }

            $value = data_get($safeData, $oldPath);
            Arr::forget($safeData, $oldPath);

            if (!str_contains($newTpl, '*') && !str_contains($newTpl, '.')) {
                $parent = Str::beforeLast($oldPath, '.');
                $newPath = $parent === '' ? $newTpl : "{$parent}.{$newTpl}";
            } else {
                $newPath = $this->fillWildcardTemplate($newTpl, $captured);
            }

            data_set($safeData, $newPath, $value);
        }
    }

    /**
     * @return list<string>
     */
    private function collectConcretePathsForTemplate(array $root, string $template): array
    {
        $segments = explode('.', $template);

        return $this->collectPathsRecursive($root, $segments, '');
    }

    /**
     * @param list<string> $segments
     * @return list<string>
     */
    private function collectPathsRecursive(mixed $node, array $segments, string $prefix): array
    {
        if ($segments === []) {
            return $prefix === '' ? [] : [$prefix];
        }

        $head = $segments[0];
        $rest = array_slice($segments, 1);

        if ($head === '*') {
            if (!is_array($node)) {
                return [];
            }

            $out = [];

            foreach (array_keys($node) as $k) {
                $seg = is_int($k) ? (string)$k : $k;
                $next = $prefix === '' ? $seg : "{$prefix}.{$seg}";

                foreach ($this->collectPathsRecursive($node[$k], $rest, $next) as $p) {
                    $out[] = $p;
                }
            }

            return $out;
        }

        if (!is_array($node) || !array_key_exists($head, $node)) {
            return [];
        }

        $next = $prefix === '' ? $head : "{$prefix}.{$head}";

        return $this->collectPathsRecursive($node[$head], $rest, $next);
    }

    /**
     * @return list<string>|null
     */
    private function extractWildcardSegments(string $template, string $concretePath): ?array
    {
        $t = explode('.', $template);
        $p = explode('.', $concretePath);

        if (count($t) !== count($p)) {
            return null;
        }

        $captured = [];

        foreach ($t as $i => $seg) {
            if ($seg === '*') {
                $captured[] = $p[$i];
            } elseif ($seg !== $p[$i]) {
                return null;
            }
        }

        return $captured;
    }

    /**
     * @param list<string> $captured
     */
    private function fillWildcardTemplate(string $template, array $captured): string
    {
        $parts = explode('.', $template);
        $i = 0;

        foreach ($parts as $j => $part) {
            if ($part === '*') {
                if (!array_key_exists($i, $captured)) {
                    throw new InvalidArgumentException('Wildcard 捕获数量与模板不一致。');
                }

                $parts[$j] = $captured[$i];
                $i++;
            }
        }

        return implode('.', $parts);
    }
}