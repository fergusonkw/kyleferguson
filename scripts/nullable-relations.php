<?php

declare(strict_types=1);

/*
 * Nullable Relations Post-Processor
 *
 * Run AFTER `php artisan ide-helper:models --write --reset`
 * (wired as `composer ide-helper` so it always runs together).
 *
 * laravel-ide-helper annotates a single-related relation
 * (belongsTo / hasOne / morphTo / morphOne) read-property as non-null
 * whenever the foreign key column is NOT NULL. That is inaccurate:
 * Eloquent returns null for these relations whenever the relation is
 * unset (transient/unsaved models, e.g. the points calculators), not
 * loaded, or the parent row is missing. The non-null annotation makes
 * PHPStan flag the codebase's intentional ?->, ?? and ternary guards
 * as dead code.
 *
 * This script rewrites those relation read-property tags to be
 * nullable, restoring accurate types. It is idempotent and only
 * touches relation-backed properties (never scalars or collections).
 */
$models = glob(__DIR__.'/../app/Models/*.php');
$changed = 0;
$relsTouched = 0;

foreach ($models as $f) {
    $original = file_get_contents($f);

    if (! preg_match_all(
        '/public function (\w+)\(\)\s*:\s*(BelongsTo|HasOne|MorphTo|MorphOne)\b/',
        $original,
        $mm,
        PREG_SET_ORDER
    )) {
        continue;
    }

    $relNames = [];
    foreach ($mm as $set) {
        $relNames[$set[1]] = true;
    }

    $src = preg_replace_callback(
        '/^(\s*\*\s*@property-read\s+)([^\s|]+)(\s+\$(\w+))$/m',
        function ($m) use ($relNames, &$relsTouched) {
            $name = $m[4];
            $type = $m[2];
            if (! isset($relNames[$name])) {
                return $m[0];
            }
            if (str_contains($type, 'Collection') || str_starts_with($type, '?')) {
                return $m[0];
            }
            $relsTouched++;

            return $m[1].$type.'|null'.$m[3];
        },
        $original
    );

    if ($src !== $original) {
        file_put_contents($f, $src);
        $changed++;
    }
}

echo "nullable-relations: {$changed} models updated, {$relsTouched} relation properties made nullable\n";
