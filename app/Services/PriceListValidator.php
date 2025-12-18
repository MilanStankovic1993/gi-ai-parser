<?php

namespace App\Services;

use Carbon\Carbon;

class PriceListValidator
{
    /**
     * @param array $rows  Niz redova kao što dolazi iz parsera
     * @return array       Niz issue-ova (ako je prazan → sve ok)
     */
    public function validate(array $rows): array
    {
        $issues = [];

        // Grupisanje po tipu jedinice (npr. 1/2 studio, 1/3 apartman...)
        $grouped = [];

        foreach ($rows as $index => $row) {
            $key = trim((string) ($row['tip_jedinice'] ?? ''));
            if ($key === '') $key = 'DEFAULT';

            $grouped[$key][] = array_merge($row, [
                '_index' => $index,
            ]);
        }

        foreach ($grouped as $unitType => $unitRows) {
            // Sortiraj po sezona_od (stabilno)
            usort($unitRows, function ($a, $b) {
                return strcmp((string) ($a['sezona_od'] ?? ''), (string) ($b['sezona_od'] ?? ''));
            });

            $prev = null; // BITNO: resetuje se po tipu

            foreach ($unitRows as $row) {
                $from = $row['sezona_od'] ?? null;
                $to   = $row['sezona_do'] ?? null;

                if (! $from || ! $to) {
                    $issues[] = [
                        'type'    => 'missing_dates',
                        'message' => "Nedostaju datumi za tip \"{$unitType}\" (index {$row['_index']}).",
                        'row'     => $row['_index'],
                    ];
                    continue;
                }

                try {
                    $fromDate = Carbon::parse($from)->startOfDay();
                    $toDate   = Carbon::parse($to)->startOfDay();
                } catch (\Throwable $e) {
                    $issues[] = [
                        'type'    => 'invalid_date_format',
                        'message' => "Nevalidan format datuma za tip \"{$unitType}\" (index {$row['_index']}).",
                        'row'     => $row['_index'],
                    ];
                    continue;
                }

                if ($fromDate->gt($toDate)) {
                    $issues[] = [
                        'type'    => 'invalid_range',
                        'message' => "Datum od je posle datuma do za tip \"{$unitType}\" (index {$row['_index']}).",
                        'row'     => $row['_index'],
                    ];
                }

                if ($prev) {
                    try {
                        $prevTo = Carbon::parse($prev['sezona_do'])->startOfDay();
                    } catch (\Throwable $e) {
                        // ako je prev bio loš, preskoči logiku rupe/preklapanja
                        $prev = $row;
                        continue;
                    }

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
