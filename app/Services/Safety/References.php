<?php

declare(strict_types=1);

namespace App\Services\Safety;

use App\Models\PortalObject;
use App\Models\SafetyCase;
use App\Models\Sanction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class References
{
    public const PREFIX = 'TS';

    public static function allocate(string $objectType, ?string $label = null, ?int $year = null): string
    {
        $year ??= (int) date('Y');

        return DB::transaction(function () use ($objectType, $label, $year) {
            $current = DB::table('reference_counters')
                ->where('year', $year)
                ->lockForUpdate()
                ->value('allocated');

            if ($current === null) {
                DB::table('reference_counters')->insertOrIgnore([
                    'year' => $year,
                    'allocated' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $current = (int) DB::table('reference_counters')
                    ->where('year', $year)
                    ->lockForUpdate()
                    ->value('allocated');
            }

            $sequence = ((int) $current) + 1;

            DB::table('reference_counters')
                ->where('year', $year)
                ->update(['allocated' => $sequence, 'updated_at' => now()]);

            $reference = sprintf('%s-%d-%04d', self::PREFIX, $year, $sequence);

            PortalObject::create([
                'reference' => $reference,
                'year' => $year,
                'sequence' => $sequence,
                'object_type' => $objectType,
                'object_id' => null,
                'label' => $label === null ? null : Str::limit($label, 250, ''),
                'allocated_by' => Auth::id(),
                'created_at' => now(),
            ]);

            return $reference;
        });
    }

    public static function attach(string $reference, Model $object, ?string $label = null): void
    {
        PortalObject::query()
            ->where('reference', $reference)
            ->update(array_filter([
                'object_type' => class_basename($object),
                'object_id' => $object->getKey(),
                'label' => $label === null ? null : Str::limit($label, 250, ''),
            ], fn ($v) => $v !== null));
    }

    public static function assign(Model $object, ?string $label = null): string
    {
        $reference = self::allocate(class_basename($object), $label);
        self::attach($reference, $object, $label);

        return $reference;
    }

    public static function adopt(Model $object, ?string $label = null): void
    {
        $reference = (string) ($object->reference ?? '');
        if ($reference === '') {
            return;
        }

        preg_match('/^([A-Z]+)-(\d{4})-(\d+)$/', $reference, $m);
        $year = isset($m[2]) ? (int) $m[2] : (int) date('Y');
        $sequence = isset($m[3]) ? (int) $m[3] : 0;

        PortalObject::query()->firstOrCreate(
            ['reference' => $reference],
            [
                'year' => $year,
                'sequence' => $sequence,
                'object_type' => class_basename($object),
                'object_id' => $object->getKey(),
                'label' => $label === null ? null : Str::limit($label, 250, ''),
                'allocated_by' => Auth::id(),
                'created_at' => $object->created_at ?? now(),
            ],
        );

        if (($m[1] ?? '') !== self::PREFIX) {
            return;
        }

        DB::table('reference_counters')->insertOrIgnore([
            'year' => $year,
            'allocated' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('reference_counters')
            ->where('year', $year)
            ->where('allocated', '<', $sequence)
            ->update(['allocated' => $sequence, 'updated_at' => now()]);
    }

    /**
     * @return array{type: string, id: int|null, reference: string, label: string|null}|null
     */
    public static function resolve(string $reference): ?array
    {
        $reference = strtoupper(trim($reference));

        $row = PortalObject::query()->where('reference', $reference)->first();
        if ($row !== null) {
            return [
                'type' => $row->object_type,
                'id' => $row->object_id,
                'reference' => $row->reference,
                'label' => $row->label,
            ];
        }

        foreach (self::LEGACY as $prefix => [$model, $type]) {
            if (! str_starts_with($reference, $prefix.'-')) {
                continue;
            }

            $found = $model::query()->where('reference', $reference)->first();
            if ($found !== null) {
                return [
                    'type' => $type,
                    'id' => $found->getKey(),
                    'reference' => $reference,
                    'label' => null,
                ];
            }
        }

        return null;
    }

    private const LEGACY = [
        'AP' => [SafetyCase::class, 'SafetyCase'],
        'DR' => [SafetyCase::class, 'SafetyCase'],
        'CT' => [SafetyCase::class, 'SafetyCase'],
        'AC' => [Sanction::class, 'Sanction'],
    ];
}
