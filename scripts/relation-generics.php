<?php

declare(strict_types=1);

/*
 * Relation Generics Post-Processor
 *
 * Run AFTER `php artisan ide-helper:models --write --reset`
 * (wired as `composer ide-helper`).
 *
 * ide-helper writes class-level @property-read/@method but does not add
 * generic @return docblocks to relation methods. When a relation method
 * declares a bare non-generic return type (e.g. `: BelongsToMany`),
 * Larastan loses the related model through `->get()` / `->map()` chains,
 * typing items as the base Eloquent Model and the pivot as the base
 * Pivot. This adds `@return RelationType<\App\Models\Target, $this>`
 * docblocks so those chains stay typed. Idempotent; never overwrites an
 * existing @return.
 */

$singleGeneric = ['HasMany', 'HasOne', 'BelongsTo', 'MorphMany', 'MorphOne', 'BelongsToMany', 'MorphToMany'];

$models = glob(__DIR__.'/../app/Models/*.php');
$changedFiles = 0;
$added = 0;

foreach ($models as $file) {
    $src = file_get_contents($file);
    $lines = explode("\n", $src);
    $out = [];
    $count = count($lines);

    for ($i = 0; $i < $count; $i++) {
        $line = $lines[$i];

        if (preg_match('/^\s*public function (\w+)\(\)\s*:\s*\??(\w+)\s*$/', $line, $m)
            && in_array($m[2], $singleGeneric, true)) {
            $relType = $m[2];

            // Find the related model from the method body.
            $body = implode("\n", array_slice($lines, $i, 12));
            if (preg_match('/->(?:belongsToMany|hasMany|hasOne|belongsTo|morphMany|morphOne|morphToMany)\(\s*([A-Za-z0-9_]+)::class/', $body, $bm)) {
                $target = $bm[1];

                // Already has a docblock with @return directly above? skip.
                $prev = trim($out[count($out) - 1] ?? '');
                $hasDocblock = $prev === '*/' || str_starts_with($prev, '/**');
                $alreadyReturned = false;
                if ($hasDocblock) {
                    for ($k = count($out) - 1; $k >= 0 && $k > count($out) - 12; $k--) {
                        if (str_contains($out[$k], '@return')) {
                            $alreadyReturned = true;
                            break;
                        }
                        if (str_starts_with(trim($out[$k]), '/**')) {
                            break;
                        }
                    }
                }

                $generic = '\\Illuminate\\Database\\Eloquent\\Relations\\'.$relType.'<\\App\\Models\\'.$target.', $this>';

                if (! $alreadyReturned && ! $hasDocblock) {
                    $out[] = str_repeat(' ', 4).'/** @return '.$generic.' */';
                    $added++;
                } elseif (! $alreadyReturned && $hasDocblock && $prev === '*/') {
                    // Inject @return before the closing */ of the existing docblock.
                    $close = array_pop($out);
                    $indent = mb_substr($close, 0, mb_strpos($close, '*'));
                    $out[] = $indent.'* @return '.$generic;
                    $out[] = $close;
                    $added++;
                }
            }
        }

        $out[] = $line;
    }

    $new = implode("\n", $out);
    if ($new !== $src) {
        file_put_contents($file, $new);
        $changedFiles++;
    }
}

echo "relation-generics: {$changedFiles} models updated, {$added} @return docblocks added\n";
