<?php

declare(strict_types=1);

namespace App\Services\AutoReview;

final class LineDiff
{
    private const CONTEXT_LINES = 2;

    private const CONTEXT_CHARS = 160;

    private const LCS_LIMIT = 250000;

    /**
     * @return array{0: string, 1: bool}
     */
    public function render(string $before, string $after, int $maxChars): array
    {
        $old = $this->lines($before);
        $new = $this->lines($after);

        $prefix = 0;
        $limit = min(count($old), count($new));
        while ($prefix < $limit && $old[$prefix] === $new[$prefix]) {
            $prefix++;
        }

        $suffix = 0;
        while (
            $suffix < $limit - $prefix
            && $old[count($old) - 1 - $suffix] === $new[count($new) - 1 - $suffix]
        ) {
            $suffix++;
        }

        $oldMiddle = array_slice($old, $prefix, count($old) - $prefix - $suffix);
        $newMiddle = array_slice($new, $prefix, count($new) - $prefix - $suffix);

        if ($oldMiddle === [] && $newMiddle === []) {
            return ['(no change to the text)', false];
        }

        $out = [];

        if ($prefix > 0) {
            $out[] = sprintf('@@ line %d @@', $prefix + 1);
            foreach (array_slice($old, max(0, $prefix - self::CONTEXT_LINES), min($prefix, self::CONTEXT_LINES)) as $line) {
                $out[] = '  '.$this->clip($line);
            }
        }

        foreach ($this->operations($oldMiddle, $newMiddle) as $op) {
            $out = [...$out, ...$op];
        }

        foreach (array_slice($old, count($old) - $suffix, self::CONTEXT_LINES) as $line) {
            $out[] = '  '.$this->clip($line);
        }

        $text = implode("\n", $out);

        if (mb_strlen($text) > $maxChars) {
            return [mb_substr($text, 0, $maxChars)."\n… (diff cut short)", true];
        }

        return [$text, false];
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<list<string>>
     */
    private function operations(array $old, array $new): array
    {
        if (count($old) * count($new) > self::LCS_LIMIT) {
            return [
                array_map(fn ($l) => '- '.$this->clip($l), array_values(array_diff($old, $new))),
                array_map(fn ($l) => '+ '.$l, array_values(array_diff($new, $old))),
            ];
        }

        $script = $this->script($old, $new);
        $blocks = [];
        $removed = [];
        $added = [];
        $sameRun = [];

        $flush = function () use (&$blocks, &$removed, &$added) {
            if ($removed === [] && $added === []) {
                return;
            }

            if (count($removed) === 1 && count($added) === 1) {
                $blocks[] = $this->inline($removed[0], $added[0]);
            } else {
                $block = [];
                foreach ($removed as $line) {
                    $block[] = '- '.$this->clip($line);
                }
                foreach ($added as $line) {
                    $block[] = '+ '.$line;
                }
                $blocks[] = $block;
            }

            $removed = [];
            $added = [];
        };

        foreach ($script as [$kind, $line]) {
            if ($kind === '=') {
                $flush();
                $sameRun[] = $line;

                continue;
            }

            if ($sameRun !== []) {
                if ($blocks !== []) {
                    $blocks[] = count($sameRun) > self::CONTEXT_LINES * 2
                        ? [
                            ...array_map(fn ($l) => '  '.$this->clip($l), array_slice($sameRun, 0, self::CONTEXT_LINES)),
                            '  …',
                            ...array_map(fn ($l) => '  '.$this->clip($l), array_slice($sameRun, -self::CONTEXT_LINES)),
                        ]
                        : array_map(fn ($l) => '  '.$this->clip($l), $sameRun);
                }
                $sameRun = [];
            }

            if ($kind === '-') {
                $removed[] = $line;
            } else {
                $added[] = $line;
            }
        }

        $flush();

        return $blocks;
    }

    /**
     * @param  list<string>  $old
     * @param  list<string>  $new
     * @return list<array{0: string, 1: string}>
     */
    private function script(array $old, array $new): array
    {
        $n = count($old);
        $m = count($new);
        $table = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $table[$i][$j] = $old[$i] === $new[$j]
                    ? $table[$i + 1][$j + 1] + 1
                    : max($table[$i + 1][$j], $table[$i][$j + 1]);
            }
        }

        $script = [];
        $i = 0;
        $j = 0;

        while ($i < $n && $j < $m) {
            if ($old[$i] === $new[$j]) {
                $script[] = ['=', $old[$i]];
                $i++;
                $j++;
            } elseif ($table[$i + 1][$j] >= $table[$i][$j + 1]) {
                $script[] = ['-', $old[$i++]];
            } else {
                $script[] = ['+', $new[$j++]];
            }
        }

        while ($i < $n) {
            $script[] = ['-', $old[$i++]];
        }
        while ($j < $m) {
            $script[] = ['+', $new[$j++]];
        }

        return $script;
    }

    /**
     * @return list<string>
     */
    private function inline(string $before, string $after): array
    {
        $a = mb_str_split($before);
        $b = mb_str_split($after);

        $start = 0;
        $limit = min(count($a), count($b));
        while ($start < $limit && $a[$start] === $b[$start]) {
            $start++;
        }

        $end = 0;
        while ($end < $limit - $start && $a[count($a) - 1 - $end] === $b[count($b) - 1 - $end]) {
            $end++;
        }

        if ($start + $end < self::CONTEXT_CHARS * 2 || mb_strlen($before) < 600) {
            return ['- '.$this->clip($before), '+ '.$after];
        }

        $lead = $start > self::CONTEXT_CHARS ? '…'.implode('', array_slice($a, $start - self::CONTEXT_CHARS, self::CONTEXT_CHARS)) : implode('', array_slice($a, 0, $start));
        $tailFrom = count($a) - $end;
        $trail = $end > self::CONTEXT_CHARS ? implode('', array_slice($a, $tailFrom, self::CONTEXT_CHARS)).'…' : implode('', array_slice($a, $tailFrom));

        $removed = implode('', array_slice($a, $start, count($a) - $start - $end));
        $added = implode('', array_slice($b, $start, count($b) - $start - $end));

        return [
            '- '.$lead.'[-'.$removed.'-]'.$trail,
            '+ '.$lead.'[+'.$added.'+]'.$trail,
        ];
    }

    private function clip(string $line): string
    {
        return mb_strlen($line) > 600 ? mb_substr($line, 0, 300).' … '.mb_substr($line, -200) : $line;
    }

    /**
     * @return list<string>
     */
    private function lines(string $text): array
    {
        return explode("\n", str_replace(["\r\n", "\r"], "\n", $text));
    }
}
