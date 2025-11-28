<?php

namespace App\Services;

use Carbon\Carbon;

class PriceListValidator
{
    /**
     * @param array $rows  Niz redova kao što dolazi iz parsera
     * @return array       Niz "issue"-ova (ako je prazan → sve ok)
     */
    public function validate(array $rows): array
    {
        $issues = [];

        // Grupisanje po tipu jedinice (npr. 1/2 studio, 1/3 apartman...)
        $grouped = [];

        foreach ($rows as $index => $row) {
            $key = $row['tip_jedinice'] ?? 'DEFAULT';

            $grouped[$key][] = array_merge($row, [
                '_index' => $index, // da znamo koji je red
            ]);
        }

        foreach ($grouped as $unitType => $unitRows) {
            // sortiraj po sezona_od
            usort($unitRows, function ($a, $b) {
                $aFrom = $a['sezona_od'] ?? null;
                $bFrom = $b['sezona_od'] ?? null;

                return strcmp((string) $aFrom, (string) $bFrom);
            });

            // proveri preklapanja i rupe
            $prev = null;

            foreach ($unitRows as $row) {
                $from = $row['sezona_od'] ?? null;
                $to   = $row['sezona_do'] ?? null;

                if (! $from || ! $to) {
                    // ako nema datuma, prijavimo kao issue
                    $issues[] = [
                        'type'    => 'missing_dates',
                        'message' => "Nedostaju datumi za tip \"{$unitType}\" (index {$row['_index']}).",
                        'row'     => $row['_index'],
                    ];
                    continue;
                }

                $fromDate = Carbon::parse($from);
                $toDate   = Carbon::parse($to);

                if ($fromDate->gt($toDate)) {
                    $issues[] = [
                        'type'    => 'invalid_range',
                        'message' => "Datum od je posle datuma do za tip \"{$unitType}\" (index {$row['_index']}).",
                        'row'     => $row['_index'],
                    ];
                }

                if ($prev) {
                    $prevFrom = Carbon::parse($prev['sezona_od']);
                    $prevTo   = Carbon::parse($prev['sezona_do']);

                    // PREKLAPANJE: nova sezona počinje pre ili na dan kad prethodna završava
                    if ($fromDate->lte($prevTo)) {
                        $issues[] = [
                            'type'    => 'overlap',
                            'message' => "Preklapanje sezona za tip \"{$unitType}\" između redova {$prev['_index']} i {$row['_index']}.",
                            'rows'    => [$prev['_index'], $row['_index']],
                        ];
                    }

                    // RUPA: nova sezona počinje više od 1 dan nakon prethodne
                    $expectedNextStart = $prevTo->copy()->addDay();
                    if ($fromDate->gt($expectedNextStart)) {
                        $issues[] = [
                            'type'    => 'gap',
                            'message' => "Postoji rupa između sezona za tip \"{$unitType}\" (između redova {$prev['_index']} i {$row['_index']}).",
                            'rows'    => [$prev['_index'], $row['_index']],
                        ];
                    }
                }

                $prev = $row;
            }
        }

        return $issues;
    }
}
