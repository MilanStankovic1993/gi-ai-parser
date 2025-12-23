<?php

namespace App\Services;

use App\Models\Inquiry;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class InquiryAiExtractor
{
    public function extract(Inquiry $inquiry): array
    {
        $text = trim((string) ($inquiry->raw_message ?? ''));
        if ($text === '') {
            return $this->fallbackExtract($inquiry);
        }

        $aiEnabled =
            (bool) config('app.ai_enabled', false) ||
            (string) env('AI_ENABLED', 'false') === 'true';

        if (! $aiEnabled) {
            return $this->fallbackExtract($inquiry);
        }

        $apiKey = (string) config('services.openai.key');
        $model  = (string) config('services.openai.model', 'gpt-4.1');

        if (trim($apiKey) === '') {
            return $this->fallbackExtract($inquiry);
        }

        $textForAi = Str::of($text)
            ->replace("\r", "\n")
            ->squish()
            ->limit(9000)
            ->toString();

        $today = $inquiry->received_at
            ? Carbon::parse($inquiry->received_at)->toDateString()
            : now()->toDateString();

        $system = <<<SYS
Ti si asistent za GrckaInfo. Iz poruke gosta izvuci parametre kao "kandidate" (nizove), da bi backend mogao da proba vise kombinacija u bazi.

Vrati ISKLJUČIVO validan JSON (bez objašnjenja, bez markdown-a).

OBAVEZNO:
- property_candidates: niz naziva smeštaja (ako gost pominje), { "query": string, "confidence": 0..1 }
- location_candidates: niz mesta/oblasti (kako god gost napiše), { "query": string, "confidence": 0..1 }
- region_candidates: niz regija (ako postoje), { "query": string, "confidence": 0..1 }

- location_json: objekat za "najširu pretragu":
  {
    "primary":   [ { "query": string, "confidence": 0..1 } ],
    "fallback":  [ { "query": string, "confidence": 0..1 } ],
    "notes":     string|null
  }
  Pravilo:
  - Ako gost navodi više lokacija / opseg (npr "od Nikitija do Toronija" + "sa druge strane Vourvuru do Sartija"),
    ubaci sve u primary (redosled po verovatnoći), a fallback neka bude prazno ili šire varijante.

- date_candidates: niz kandidata za period:
  - ili { "from":"YYYY-MM-DD", "to":"YYYY-MM-DD", "confidence":0..1 }
  - ili { "from_window":{"from":"YYYY-MM-DD","to":"YYYY-MM-DD"}, "nights":int|null, "confidence":0..1 }

- party: objekat sa više grupa (za više porodica / više apartmana):
  {
    "units_needed": int|null,
    "groups": [
      {
        "adults": int|null,
        "children": int|null,
        "children_ages": int[],
        "requirements": string[]
      }
    ],
    "confidence": 0..1
  }

✅ KLJUČNO (ODVOJENE PRETRAGE):
- units: niz (jedan element = jedna porodica/jedinica/apartman koji tražimo)
  {
    "unit_index": int,  // 1..N
    "party_group": { "adults":int|null, "children":int|null, "children_ages":int[], "requirements":string[] },
    "property_candidates": [ { "query": string, "confidence": 0..1 } ],
    "wishes_override": {near_beach, parking, quiet, pets, pool, separate_bedroom} true/false/null | null
  }

PRAVILO MAPIRANJA:
- Ako party.groups ima N elemenata i property_candidates ima N elemenata:
  - units.length = N
  - units[i].party_group = party.groups[i]
  - units[i].property_candidates = [ property_candidates[i] ]
- Ako nije jasno mapiranje: vrati units sa party_group, a property_candidates ostavi prazno ili kopiraj globalno.

- wishes: tri-state {near_beach, parking, quiet, pets, pool, separate_bedroom} true/false/null
- questions: niz tagova (deposit, guarantee, availability, price, payment, cancellation)
- intent: specific_property | standard_search | long_stay_private | owner_request | spam | unknown
- language: "sr" ili "en" (ili null)

Kompatibilnost:
- Popuni i legacy polja (region, location, date_from, date_to, nights, adults, children, children_ages, budget_min, budget_max, wants_*)
SYS;

        $user = <<<USR
Danas je: {$today}

PORUKA GOSTA:
{$textForAi}

Vrati JSON sa poljima:
intent,
out_of_scope_reason,

property_candidates,
location_candidates,
region_candidates,
location_json,
date_candidates,

party,
units,

wishes,
questions,
tags,
why_no_offer,

budget_min,
budget_max,

language,

// legacy:
region,
location,
month_hint,
date_from,
date_to,
nights,
adults,
children,
children_ages,
wants_near_beach,
wants_parking,
wants_quiet,
wants_pets,
wants_pool,
special_requirements
USR;

        try {
            $payload = [
                'model' => $model,
                'temperature' => 0.1,
                'messages' => [
                    ['role' => 'system', 'content' => $system],
                    ['role' => 'user', 'content' => $user],
                ],
                'response_format' => ['type' => 'json_object'],
            ];

            $resp = Http::timeout(40)
                ->retry(2, 400)
                ->withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/chat/completions', $payload);

            if (! $resp->successful()) {
                Log::warning('InquiryAiExtractor: OpenAI non-success', [
                    'status' => $resp->status(),
                    'inquiry_id' => $inquiry->id ?? null,
                    'body' => $resp->body(),
                ]);

                return $this->fallbackExtract($inquiry);
            }

            $content = data_get($resp->json(), 'choices.0.message.content');
            if (! is_string($content) || trim($content) === '') {
                return $this->fallbackExtract($inquiry);
            }

            $json = $this->decodeJsonSafely($content);
            if (! is_array($json)) {
                return $this->fallbackExtract($inquiry);
            }

            [$out, $warnings] = $this->normalizeAiOutputV3($json, $text, $inquiry);

            $out['_mode'] = 'ai';
            $out['_warnings'] = $warnings;

            return $out;
        } catch (\Throwable $e) {
            Log::warning('InquiryAiExtractor: exception', [
                'inquiry_id' => $inquiry->id ?? null,
                'message' => $e->getMessage(),
            ]);

            return $this->fallbackExtract($inquiry);
        }
    }

    /**
     * @return array{0: array, 1: array}
     */
    private function normalizeAiOutputV3(array $data, string $rawText, Inquiry $inquiry): array
    {
        $warnings = [];

        $intent = $this->nullIfEmpty(data_get($data, 'intent'));
        if (! in_array($intent, ['specific_property', 'standard_search', 'long_stay_private', 'owner_request', 'spam', 'unknown'], true)) {
            $intent = 'unknown';
        }

        $propertyCandidates = $this->sortCandidatesByConfidence(
            $this->normalizeCandidateList(data_get($data, 'property_candidates'))
        );
        $locationCandidates = $this->sortCandidatesByConfidence(
            $this->normalizeCandidateList(data_get($data, 'location_candidates'))
        );
        $regionCandidates   = $this->sortCandidatesByConfidence(
            $this->normalizeCandidateList(data_get($data, 'region_candidates'))
        );
        $dateCandidates     = $this->sortDateCandidatesByConfidence(
            $this->normalizeDateCandidates(data_get($data, 'date_candidates'))
        );

        // location_json (kanon)
        $locationJsonRaw = $this->normalizeObject(data_get($data, 'location_json'), []);
        $locationJson = [
            'primary'  => $this->sortCandidatesByConfidence($this->normalizeCandidateList($locationJsonRaw['primary'] ?? [])),
            'fallback' => $this->sortCandidatesByConfidence($this->normalizeCandidateList($locationJsonRaw['fallback'] ?? [])),
            'notes'    => $this->nullIfEmpty((string) ($locationJsonRaw['notes'] ?? '')),
        ];

        // ako AI nije dao location_json, napravi iz location_candidates
        if (empty($locationJson['primary']) && ! empty($locationCandidates)) {
            $locationJson['primary'] = $locationCandidates;
            $warnings[] = 'location_json missing; derived primary from location_candidates';
        }

        // party (groups)
        $partyRaw = $this->normalizeObject(data_get($data, 'party'), []);

        $unitsNeeded = $this->normalizeInt(data_get($partyRaw, 'units_needed'));
        $groups      = $this->normalizePartyGroups(data_get($partyRaw, 'groups'));

        $partyConfidence = data_get($partyRaw, 'confidence');
        $partyConfidence = is_numeric($partyConfidence) ? max(0, min(1, (float) $partyConfidence)) : null;

        // totals derived from groups (kompatibilnost)
        [$adults, $children, $ages] = $this->derivePartyTotalsFromGroups($groups);

        if (empty($groups)) {
            $warnings[] = 'party.groups missing; derived party totals from legacy adults/children/children_ages if present';

            $adults   = $adults   ?? $this->normalizeInt(data_get($data, 'adults'));
            $children = $children ?? $this->normalizeInt(data_get($data, 'children'));

            $agesFromLegacy = $this->normalizeAgesToArray(data_get($data, 'children_ages'));
            if (! empty($agesFromLegacy)) {
                // ✅ NE unique
                $ages = array_values(array_merge($ages, $agesFromLegacy));
            }

            $groups = [[
                'adults' => $adults,
                'children' => $children,
                'children_ages' => $agesFromLegacy,
                'requirements' => [],
            ]];
        }

        if ($unitsNeeded === null && count($groups) > 1) {
            $unitsNeeded = count($groups);
        }

        $party = [
            'units_needed' => $unitsNeeded,
            'adults' => $adults,
            'children' => $children,
            'children_ages' => $ages, // ✅ duplikati dozvoljeni
            'groups' => $groups,
            'confidence' => $partyConfidence,
        ];

        // wishes
        $wishesRaw = $this->normalizeObject(data_get($data, 'wishes'), []);
        $wishes = [
            'near_beach' => $this->normalizeBool(data_get($wishesRaw, 'near_beach')),
            'parking'    => $this->normalizeBool(data_get($wishesRaw, 'parking')),
            'quiet'      => $this->normalizeBool(data_get($wishesRaw, 'quiet')),
            'pets'       => $this->normalizeBool(data_get($wishesRaw, 'pets')),
            'pool'       => $this->normalizeBool(data_get($wishesRaw, 'pool')),
            'separate_bedroom' => $this->normalizeBool(data_get($wishesRaw, 'separate_bedroom')),
        ];

        $questions = $this->normalizeStringArray(data_get($data, 'questions'));
        $tags      = $this->normalizeStringArray(data_get($data, 'tags'));
        $why       = $this->normalizeStringArray(data_get($data, 'why_no_offer'));

        $budgetMin = $this->normalizeInt(data_get($data, 'budget_min'));
        $budgetMax = $this->normalizeInt(data_get($data, 'budget_max'));

        // LEGACY best
        $bestLoc = $locationJson['primary'][0]['query'] ?? $locationCandidates[0]['query'] ?? $this->nullIfEmpty(data_get($data, 'location'));
        $bestReg = $regionCandidates[0]['query'] ?? $this->nullIfEmpty(data_get($data, 'region'));

        $bestDateFrom = $this->normalizeDate(data_get($data, 'date_from'));
        $bestDateTo   = $this->normalizeDate(data_get($data, 'date_to'));
        $bestNights   = $this->normalizeInt(data_get($data, 'nights'));

        $bestWindow = null;

        if (empty($bestDateFrom) && empty($bestDateTo) && ! empty($dateCandidates)) {
            $dc0 = $dateCandidates[0];

            if (! empty($dc0['from']) && ! empty($dc0['to'])) {
                $bestDateFrom = $dc0['from'];
                $bestDateTo   = $dc0['to'];
            } elseif (! empty($dc0['from_window']['from']) && ! empty($dc0['from_window']['to'])) {
                $bestWindow = $dc0['from_window'];
                $warnings[] = 'Only date window provided; legacy date_from/date_to left null';
            }

            if (empty($bestNights) && array_key_exists('nights', $dc0)) {
                $bestNights = $this->normalizeInt($dc0['nights']);
            }
        }

        if ($bestDateFrom) {
            [$df, $dt] = $this->normalizeFutureDates(
                $bestDateFrom,
                $bestDateTo,
                $bestNights,
                $inquiry->received_at ? Carbon::parse($inquiry->received_at) : null
            );
            $bestDateFrom = $df;
            $bestDateTo   = $dt;
        }

        // wants_* from wishes (tri-state)
        $wantsNear  = $this->normalizeBool(data_get($data, 'wants_near_beach'));
        $wantsPark  = $this->normalizeBool(data_get($data, 'wants_parking'));
        $wantsQuiet = $this->normalizeBool(data_get($data, 'wants_quiet'));
        $wantsPets  = $this->normalizeBool(data_get($data, 'wants_pets'));
        $wantsPool  = $this->normalizeBool(data_get($data, 'wants_pool'));

        if ($wantsNear === null)  $wantsNear  = $wishes['near_beach'];
        if ($wantsPark === null)  $wantsPark  = $wishes['parking'];
        if ($wantsQuiet === null) $wantsQuiet = $wishes['quiet'];
        if ($wantsPets === null)  $wantsPets  = $wishes['pets'];
        if ($wantsPool === null)  $wantsPool  = $wishes['pool'];

        $language = $this->nullIfEmpty(data_get($data, 'language')) ?: $this->guessLanguage($rawText);

        // intent auto-fix
        if ($intent === 'standard_search' && ! empty($propertyCandidates)) {
            $intent = 'specific_property';
            $warnings[] = 'Intent adjusted to specific_property based on property_candidates';
        }
        if ($intent === 'unknown') {
            $intent = ! empty($propertyCandidates) ? 'specific_property' : 'standard_search';
        }

        // entities + travel_time (kanon)
        $entities = [
            'property_candidates' => $propertyCandidates,
            'location_candidates' => $locationCandidates,
            'region_candidates'   => $regionCandidates,
            'date_candidates'     => $dateCandidates,
        ];

        $travelTime = [
            'date_from'   => $bestDateFrom,
            'date_to'     => $bestDateTo,
            'date_window' => $bestWindow,
            'nights'      => $bestNights,
        ];

        // ✅ UNITS (najvažnije)
        $units = $this->normalizeUnits(
            data_get($data, 'units'),
            $groups,
            $propertyCandidates,
            $wishes
        );

        if (empty($units) && ! empty($groups)) {
            $warnings[] = 'units missing; built deterministically from party.groups and property_candidates';
        }

        $out = [
            'intent' => $intent,
            'out_of_scope_reason' => $this->nullIfEmpty(data_get($data, 'out_of_scope_reason')),

            'entities' => $entities,
            'travel_time' => $travelTime,
            'party' => $party,
            'location_json' => $locationJson,
            'units' => $units,

            'wishes' => $wishes,
            'questions' => $questions,
            'tags' => $tags,
            'why_no_offer' => $why,

            'budget_min' => $budgetMin,
            'budget_max' => $budgetMax,
            'language' => $language,

            // legacy
            'region' => $bestReg,
            'location' => $bestLoc,
            'month_hint' => $this->nullIfEmpty(data_get($data, 'month_hint')),
            'date_from' => $bestDateFrom,
            'date_to' => $bestDateTo,
            'nights' => $bestNights,

            'adults' => $adults,
            'children' => $children,
            'children_ages' => $ages, // ✅ duplikati dozvoljeni

            'wants_near_beach' => $wantsNear,
            'wants_parking'    => $wantsPark,
            'wants_quiet'      => $wantsQuiet,
            'wants_pets'       => $wantsPets,
            'wants_pool'       => $wantsPool,

            'special_requirements' => $this->nullIfEmpty(data_get($data, 'special_requirements')),
        ];

        return [$out, $warnings];
    }

    private function normalizeUnits($v, array $groups, array $propertyCandidates, array $wishes): array
    {
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : null;
        }

        if (is_array($v) && ! empty($v)) {
            $out = [];
            $i = 0;

            foreach ($v as $row) {
                $i++;
                if (! is_array($row)) continue;

                $unitIndex = $this->normalizeInt($row['unit_index'] ?? $i) ?? $i;

                $pg = $row['party_group'] ?? null;
                $pg = is_array($pg) ? $pg : [];

                $partyGroup = [
                    'adults' => $this->normalizeInt($pg['adults'] ?? null),
                    'children' => $this->normalizeInt($pg['children'] ?? null),
                    'children_ages' => $this->normalizeAgesToArray($pg['children_ages'] ?? null), // ✅ NE unique
                    'requirements' => $this->normalizeStringArray($pg['requirements'] ?? []),
                ];

                $pc = $this->normalizeCandidateList($row['property_candidates'] ?? []);
                $pc = $this->sortCandidatesByConfidence($pc);

                $wo = $row['wishes_override'] ?? null;
                $wo = is_array($wo) ? [
                    'near_beach' => $this->normalizeBool($wo['near_beach'] ?? null),
                    'parking' => $this->normalizeBool($wo['parking'] ?? null),
                    'quiet' => $this->normalizeBool($wo['quiet'] ?? null),
                    'pets' => $this->normalizeBool($wo['pets'] ?? null),
                    'pool' => $this->normalizeBool($wo['pool'] ?? null),
                    'separate_bedroom' => $this->normalizeBool($wo['separate_bedroom'] ?? null),
                ] : null;

                $out[] = [
                    'unit_index' => $unitIndex,
                    'party_group' => $partyGroup,
                    'property_candidates' => $pc,
                    'wishes_override' => $wo,
                ];
            }

            if (! empty($out)) return $out;
        }

        // deterministički fallback
        $out = [];

        $gN = count($groups);
        $pN = count($propertyCandidates);

        if ($gN > 0 && $pN > 0 && $gN === $pN) {
            for ($i = 0; $i < $gN; $i++) {
                $out[] = [
                    'unit_index' => $i + 1,
                    'party_group' => [
                        'adults' => $groups[$i]['adults'] ?? null,
                        'children' => $groups[$i]['children'] ?? null,
                        'children_ages' => $groups[$i]['children_ages'] ?? [],
                        'requirements' => $groups[$i]['requirements'] ?? [],
                    ],
                    'property_candidates' => [$propertyCandidates[$i]],
                    'wishes_override' => null,
                ];
            }
            return $out;
        }

        if ($gN > 0) {
            for ($i = 0; $i < $gN; $i++) {
                $out[] = [
                    'unit_index' => $i + 1,
                    'party_group' => [
                        'adults' => $groups[$i]['adults'] ?? null,
                        'children' => $groups[$i]['children'] ?? null,
                        'children_ages' => $groups[$i]['children_ages'] ?? [],
                        'requirements' => $groups[$i]['requirements'] ?? [],
                    ],
                    'property_candidates' => [],
                    'wishes_override' => null,
                ];
            }
            return $out;
        }

        return [];
    }

    private function normalizeCandidateList($v): array
    {
        if ($v === null) return [];
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : [$v];
        }
        if (! is_array($v)) return [];

        $out = [];
        foreach ($v as $row) {
            if (is_string($row)) {
                $q = trim($row);
                if ($q !== '') $out[] = ['query' => $q, 'confidence' => null];
                continue;
            }

            if (! is_array($row)) continue;

            $q = trim((string) ($row['query'] ?? $row['value'] ?? ''));
            if ($q === '') continue;

            $c = $row['confidence'] ?? null;
            $c = is_numeric($c) ? max(0, min(1, (float) $c)) : null;

            $out[] = ['query' => $q, 'confidence' => $c];
        }

        // unique by query (OK za lokacije/nazive)
        $uniq = [];
        foreach ($out as $r) {
            $k = mb_strtolower($r['query']);
            if (! array_key_exists($k, $uniq)) {
                $uniq[$k] = $r;
            }
        }

        return array_values($uniq);
    }

    private function normalizeDateCandidates($v): array
    {
        if ($v === null) return [];
        if (is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($v)) return [];

        $out = [];
        foreach ($v as $row) {
            if (! is_array($row)) continue;

            $conf = $row['confidence'] ?? null;
            $conf = is_numeric($conf) ? max(0, min(1, (float) $conf)) : null;

            $from = $this->normalizeDate($row['from'] ?? null);
            $to   = $this->normalizeDate($row['to'] ?? null);

            $fw = $row['from_window'] ?? null;
            $fwFrom = is_array($fw) ? $this->normalizeDate($fw['from'] ?? null) : null;
            $fwTo   = is_array($fw) ? $this->normalizeDate($fw['to'] ?? null) : null;

            $nights = $this->normalizeInt($row['nights'] ?? null);

            if ($from && $to) {
                $out[] = ['from' => $from, 'to' => $to, 'confidence' => $conf];
            } elseif ($fwFrom && $fwTo) {
                $out[] = [
                    'from_window' => ['from' => $fwFrom, 'to' => $fwTo],
                    'nights' => $nights,
                    'confidence' => $conf,
                ];
            }
        }

        return $out;
    }

    // ---------------------------
    // Helpers
    // ---------------------------

    private function sortCandidatesByConfidence(array $candidates): array
    {
        usort($candidates, function ($a, $b) {
            $ca = $a['confidence'] ?? null;
            $cb = $b['confidence'] ?? null;

            $ca = is_numeric($ca) ? (float) $ca : -1.0;
            $cb = is_numeric($cb) ? (float) $cb : -1.0;

            return $cb <=> $ca;
        });

        return $candidates;
    }

    private function sortDateCandidatesByConfidence(array $candidates): array
    {
        usort($candidates, function ($a, $b) {
            $ca = $a['confidence'] ?? null;
            $cb = $b['confidence'] ?? null;

            $ca = is_numeric($ca) ? (float) $ca : -1.0;
            $cb = is_numeric($cb) ? (float) $cb : -1.0;

            return $cb <=> $ca;
        });

        return $candidates;
    }

    private function decodeJsonSafely(string $content): ?array
    {
        $c = trim($content);

        $firstBrace = strpos($c, '{');
        $lastBrace  = strrpos($c, '}');

        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $c = substr($c, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        $decoded = json_decode($c, true);
        return is_array($decoded) ? $decoded : null;
    }

    private function normalizeObject($v, array $default = []): array
    {
        if ($v === null) return $default;
        if (is_array($v)) return $v;

        if (is_string($v)) {
            $decoded = json_decode($v, true);
            return is_array($decoded) ? $decoded : $default;
        }

        return $default;
    }

    private function normalizeStringArray($v): array
    {
        if ($v === null) return [];

        if (is_string($v)) {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                $v = $decoded;
            } else {
                $v = preg_split('/[,;\n]+/u', $v) ?: [];
            }
        }

        if (! is_array($v)) return [];

        $out = [];
        foreach ($v as $item) {
            if (! is_string($item)) continue;
            $s = trim($item);
            if ($s !== '') $out[] = $s;
        }

        return array_values(array_unique($out));
    }

    private function normalizeInt($v): ?int
    {
        if ($v === null) return null;
        if (is_int($v)) return $v;
        if (is_float($v)) return (int) round($v);
        if (is_bool($v)) return $v ? 1 : 0;

        if (is_string($v)) {
            $n = preg_replace('/[^\d]/', '', $v);
            return $n === '' ? null : (int) $n;
        }

        return null;
    }

    private function normalizeBool($v): ?bool
    {
        if ($v === null) return null;
        if (is_bool($v)) return $v;
        if (is_int($v)) return $v === 1 ? true : ($v === 0 ? false : null);

        if (is_string($v)) {
            $t = mb_strtolower(trim($v));
            if (in_array($t, ['true', 'da', 'yes', '1'], true)) return true;
            if (in_array($t, ['false', 'ne', 'no', '0'], true)) return false;
        }

        return null;
    }

    private function normalizeDate($v): ?string
    {
        if (! is_string($v) || trim($v) === '') return null;

        try {
            return Carbon::parse($v)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function nullIfEmpty($v): ?string
    {
        if (! is_string($v)) return null;

        $v = trim($v);
        return $v === '' ? null : $v;
    }

    /**
     * ✅ ages: NE unique, valid 0..17 (0 = beba)
     */
    private function normalizeAgesToArray($v): array
    {
        if ($v === null) return [];

        if (is_string($v)) {
            $decoded = json_decode($v, true);
            if (is_array($decoded)) {
                $v = $decoded;
            } else {
                preg_match_all('/\d{1,2}/', $v, $m);
                $v = $m[0] ?? [];
            }
        }

        if (! is_array($v)) return [];

        $ages = [];
        foreach ($v as $item) {
            $n = $this->normalizeInt($item);
            if ($n !== null && $n >= 0 && $n <= 17) {
                $ages[] = $n; // ✅ NE unique
            }
        }

        return array_values($ages);
    }

    private function normalizePartyGroups($v): array
    {
        if ($v === null) return [];

        if (is_string($v)) {
            $decoded = json_decode($v, true);
            $v = is_array($decoded) ? $decoded : [];
        }

        if (! is_array($v)) return [];

        $out = [];
        foreach ($v as $g) {
            if (! is_array($g)) continue;

            $adults   = $this->normalizeInt($g['adults'] ?? null);
            $children = $this->normalizeInt($g['children'] ?? null);

            $ages = $this->normalizeAgesToArray($g['children_ages'] ?? ($g['ages'] ?? null));

            $req = $g['requirements'] ?? [];
            if (is_string($req)) {
                $req = preg_split('/[,;\n]+/u', $req) ?: [];
            }

            $reqOut = [];
            if (is_array($req)) {
                foreach ($req as $r) {
                    if (! is_string($r)) continue;
                    $r = trim($r);
                    if ($r !== '') $reqOut[] = $r;
                }
            }
            $reqOut = array_values(array_unique($reqOut));

            $out[] = [
                'adults' => $adults,
                'children' => $children,
                'children_ages' => $ages,
                'requirements' => $reqOut,
            ];
        }

        return array_values(array_filter($out, function ($g) {
            return !(
                ($g['adults'] === null) &&
                ($g['children'] === null) &&
                empty($g['children_ages']) &&
                empty($g['requirements'])
            );
        }));
    }

    /**
     * ✅ totals: children_ages NE unique
     */
    private function derivePartyTotalsFromGroups(array $groups): array
    {
        $adults = 0;
        $children = 0;
        $ages = [];

        $hasAdults = false;
        $hasChildren = false;

        foreach ($groups as $g) {
            if (! is_array($g)) continue;

            if (array_key_exists('adults', $g) && $g['adults'] !== null) {
                $hasAdults = true;
                $adults += (int) $g['adults'];
            }

            if (array_key_exists('children', $g) && $g['children'] !== null) {
                $hasChildren = true;
                $children += (int) $g['children'];
            }

            $a = $g['children_ages'] ?? [];
            if (is_array($a)) {
                foreach ($a as $age) {
                    $n = $this->normalizeInt($age);
                    if ($n !== null && $n >= 0 && $n <= 17) $ages[] = $n; // ✅ NE unique
                }
            }
        }

        return [
            $hasAdults ? $adults : null,
            $hasChildren ? $children : null,
            array_values($ages),
        ];
    }

    private function normalizeFutureDates(?string $dateFrom, ?string $dateTo, ?int $nights, ?Carbon $receivedAt = null): array
    {
        if (! $dateFrom) return [$dateFrom, $dateTo];

        try {
            $from = Carbon::parse($dateFrom)->startOfDay();
        } catch (\Throwable) {
            return [null, null];
        }

        $to = null;
        if ($dateTo) {
            try {
                $to = Carbon::parse($dateTo)->startOfDay();
            } catch (\Throwable) {
                $to = null;
            }
        }

        $ref = ($receivedAt ?: now())->startOfDay();

        if ($from->lt($ref->copy()->subDays(7))) {
            $from->addYear();
            if ($to) {
                $to->addYear();
            } elseif ($nights) {
                $to = $from->copy()->addDays((int) $nights);
            }
        }

        return [$from->toDateString(), $to?->toDateString()];
    }

    private function guessLanguage(string $text): string
    {
        $t = mb_strtolower($text);
        if (Str::contains($t, ['hello', 'hi', 'please', 'regards'])) return 'en';
        return 'sr';
    }

    // ------------------------------
    // FallbackExtract
    // ------------------------------
    private function fallbackExtract(Inquiry $inquiry): array
    {
        $unit = [
            'unit_index' => 1,
            'party_group' => [
                'adults' => $inquiry->adults,
                'children' => $inquiry->children,
                'children_ages' => $inquiry->children_ages ?? [],
                'requirements' => [],
            ],
            'property_candidates' => [],
            'wishes_override' => null,
        ];

        $locPrimary = [];
        if (! empty($inquiry->location)) {
            $locPrimary[] = ['query' => (string) $inquiry->location, 'confidence' => null];
        }

        return [
            'intent' => 'standard_search',
            'out_of_scope_reason' => null,

            'entities' => [
                'property_candidates' => [],
                'location_candidates' => [],
                'region_candidates'   => [],
                'date_candidates'     => [],
            ],
            'travel_time' => [
                'date_from' => $inquiry->date_from ? $inquiry->date_from->toDateString() : null,
                'date_to' => $inquiry->date_to ? $inquiry->date_to->toDateString() : null,
                'date_window' => null,
                'nights' => $inquiry->nights,
            ],

            'party' => [
                'units_needed' => null,
                'adults' => $inquiry->adults,
                'children' => $inquiry->children,
                'children_ages' => $inquiry->children_ages ?? [],
                'groups' => [[
                    'adults' => $inquiry->adults,
                    'children' => $inquiry->children,
                    'children_ages' => $inquiry->children_ages ?? [],
                    'requirements' => [],
                ]],
                'confidence' => null,
            ],

            'location_json' => [
                'primary' => $locPrimary,
                'fallback' => [],
                'notes' => null,
            ],

            'units' => [$unit],

            'wishes' => [
                'near_beach' => null,
                'parking' => null,
                'quiet' => null,
                'pets' => null,
                'pool' => null,
                'separate_bedroom' => null,
            ],
            'questions' => [],
            'tags' => [],
            'why_no_offer' => [],

            'budget_min' => $inquiry->budget_min,
            'budget_max' => $inquiry->budget_max,
            'language' => $this->guessLanguage((string) $inquiry->raw_message),

            // legacy:
            'region' => $inquiry->region,
            'location' => $inquiry->location,
            'month_hint' => $inquiry->month_hint,
            'date_from' => $inquiry->date_from ? $inquiry->date_from->toDateString() : null,
            'date_to' => $inquiry->date_to ? $inquiry->date_to->toDateString() : null,
            'nights' => $inquiry->nights,
            'adults' => $inquiry->adults,
            'children' => $inquiry->children,
            'children_ages' => $inquiry->children_ages ?? [],
            'wants_near_beach' => $inquiry->wants_near_beach,
            'wants_parking' => $inquiry->wants_parking,
            'wants_quiet' => $inquiry->wants_quiet,
            'wants_pets' => $inquiry->wants_pets,
            'wants_pool' => $inquiry->wants_pool,
            'special_requirements' => $inquiry->special_requirements,

            '_mode' => 'fallback',
            '_warnings' => [],
        ];
    }
}
