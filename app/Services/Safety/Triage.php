<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\CaseCategory;
use App\Models\SafetyCase;

final class Triage
{
    public const PRIORITIES = [
        SafetyCase::PRIORITY_URGENT,
        SafetyCase::PRIORITY_HIGH,
        SafetyCase::PRIORITY_NORMAL,
        SafetyCase::PRIORITY_LOW,
    ];

    /**
     * @return list<string>
     */
    public static function threatToLifeCategories(): array
    {
        $ids = (array) config('categories.threat_to_life.categories', []);

        return array_values(array_unique(array_filter(array_map(
            fn ($id) => is_string($id) ? CaseCategory::canonical($id) : null,
            $ids,
        ))));
    }

    /**
     * @param  iterable<mixed>  $categoryIds
     */
    public static function isThreatToLife(iterable $categoryIds): bool
    {
        $watched = self::threatToLifeCategories();

        if ($watched === []) {
            return false;
        }

        foreach ($categoryIds as $id) {
            if (is_string($id) && $id !== '' && in_array(CaseCategory::canonical($id), $watched, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  iterable<mixed>  $categoryIds
     * @param  array<string, mixed>  $answers
     */
    public static function detect(iterable $categoryIds, array $answers = []): bool
    {
        return self::isThreatToLife($categoryIds) || self::inAnswers($answers);
    }

    /**
     * @param  array<string, mixed>  $answers
     */
    public static function inAnswers(array $answers): bool
    {
        $watched = self::threatToLifeCategories();

        if ($watched === []) {
            return false;
        }

        foreach ($answers as $field => $value) {
            if (is_string($field)
                && in_array(CaseCategory::canonical($field), $watched, true)
                && self::answered($value)) {
                return true;
            }

            foreach ((is_array($value) ? $value : [$value]) as $item) {
                if (is_scalar($item)
                    && in_array(CaseCategory::canonical((string) $item), $watched, true)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function answered(mixed $value): bool
    {
        if ($value === null || $value === false || $value === '' || $value === []) {
            return false;
        }

        if (is_array($value)) {
            foreach ($value as $item) {
                if (self::answered($item)) {
                    return true;
                }
            }

            return false;
        }

        if (! is_scalar($value)) {
            return false;
        }

        return ! in_array(strtolower(trim((string) $value)), ['no', 'false', 'none', 'n'], true);
    }

    public static function escalatedPriority(): string
    {
        $priority = (string) config('categories.threat_to_life.priority', SafetyCase::PRIORITY_URGENT);

        return in_array($priority, self::PRIORITIES, true) ? $priority : SafetyCase::PRIORITY_URGENT;
    }

    /**
     * @param  iterable<mixed>  $categoryIds
     * @param  array<string, mixed>  $answers
     */
    public static function priorityFor(iterable $categoryIds, ?string $current, array $answers = []): string
    {
        $current = in_array($current, self::PRIORITIES, true) ? $current : SafetyCase::PRIORITY_NORMAL;

        if (! self::detect($categoryIds, $answers)) {
            return $current;
        }

        $escalated = self::escalatedPriority();

        return self::rank($escalated) < self::rank($current) ? $escalated : $current;
    }

    public static function rank(?string $priority): int
    {
        $index = array_search($priority, self::PRIORITIES, true);

        return $index === false ? array_search(SafetyCase::PRIORITY_NORMAL, self::PRIORITIES, true) : $index;
    }

    public static function orderByPrioritySql(string $column = 'priority'): string
    {
        $cases = [];

        foreach (self::PRIORITIES as $rank => $priority) {
            $cases[] = sprintf("WHEN '%s' THEN %d", $priority, $rank);
        }

        return sprintf(
            'CASE %s %s ELSE %d END',
            $column,
            implode(' ', $cases),
            self::rank(SafetyCase::PRIORITY_NORMAL),
        );
    }
}
