<?php

/**
 * Helpers voor kostenplaats/afdeling filtering.
 */

/**
 * Waarde voor "geen kostenplaats" in de UI.
 */
function bc_fetch_cost_center_none_value(): string
{
    return '__none__';
}

/**
 * Normaliseert een kostenplaatswaarde voor vergelijking en opslag.
 */
function bc_fetch_normalize_cost_center(string $costCenter): string
{
    return trim($costCenter);
}

/**
 * Normaliseert een kostenplaats voor vergelijking (o.a. "05" = "5").
 */
function bc_fetch_normalize_cost_center_for_match(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (ctype_digit($trimmed)) {
        $withoutLeadingZeros = ltrim($trimmed, '0');

        return $withoutLeadingZeros === '' ? '0' : $withoutLeadingZeros;
    }

    return $trimmed;
}

/**
 * Haalt de numerieke kostenplaatscode uit een BC-dimensiewaarde.
 *
 * Ondersteunt "50", "050" en "50 - Afdelingsnaam".
 */
function bc_fetch_extract_cost_center_code(string $value): string
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        return '';
    }

    if (preg_match('/^(\d+)/', $trimmed, $matches) === 1) {
        return bc_fetch_normalize_cost_center_for_match($matches[1]);
    }

    return bc_fetch_normalize_cost_center_for_match($trimmed);
}

/**
 * Vergelijkt twee kostenplaatswaarden met numerieke normalisatie.
 */
function bc_fetch_cost_centers_match(string $left, string $right): bool
{
    return bc_fetch_extract_cost_center_code($left) === bc_fetch_extract_cost_center_code($right);
}

/**
 * Controleert of een ProjectPosten-rij bij de gekozen kostenplaats hoort.
 *
 * ProjectPosten heeft vaak geen of onvolledige dimensievelden; lege velden worden
 * niet uitgesloten zodat filtering later op werkorder-niveau kan plaatsvinden.
 */
function bc_fetch_row_matches_cost_center(array $row, string $costCenter): bool
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '' || $normalized === bc_fetch_cost_center_none_value()) {
        return true;
    }

    $candidates = [
        trim((string) ($row['Global_Dimension_1_Code'] ?? '')),
        trim((string) ($row['LVS_Global_Dimension_1_Code'] ?? '')),
    ];

    $hasPopulatedDimension = false;
    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        $hasPopulatedDimension = true;
        if (bc_fetch_cost_centers_match($candidate, $normalized)) {
            return true;
        }
    }

    // Lege dimensies: meenemen voor latere filtering via werkorder.
    return !$hasPopulatedDimension;
}

/**
 * Strikt: alleen ProjectPosten met expliciet overeenkomende dimensiecode.
 */
function bc_fetch_row_matches_cost_center_explicit(array $row, string $costCenter): bool
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '' || $normalized === bc_fetch_cost_center_none_value()) {
        return true;
    }

    $candidates = [
        trim((string) ($row['Global_Dimension_1_Code'] ?? '')),
        trim((string) ($row['LVS_Global_Dimension_1_Code'] ?? '')),
    ];

    foreach ($candidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        if (bc_fetch_cost_centers_match($candidate, $normalized)) {
            return true;
        }
    }

    return false;
}

/**
 * Controleert of een werkorder bij de gekozen kostenplaats hoort (Job_Dimension_1_Value).
 */
function bc_fetch_workorder_matches_cost_center(array $workorder, string $costCenter): bool
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return true;
    }

    $workorderCostCenter = trim((string) ($workorder['Job_Dimension_1_Value'] ?? ''));
    if ($normalized === bc_fetch_cost_center_none_value()) {
        return $workorderCostCenter === '';
    }

    return bc_fetch_cost_centers_match($workorderCostCenter, $normalized);
}

/**
 * @param list<array> $workorders
 * @return list<array>
 */
function bc_fetch_filter_workorders_by_cost_center(array $workorders, string $costCenter): array
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return $workorders;
    }

    return array_values(array_filter($workorders, static function ($workorder) use ($normalized): bool {
        return is_array($workorder) && bc_fetch_workorder_matches_cost_center($workorder, $normalized);
    }));
}

/**
 * @param list<array> $rows
 * @return array<string, bool>
 */
function bc_fetch_pair_keys_from_projectposten_rows(array $rows, string $costCenter, bool $explicitDimensionOnly = false): array
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    $pairKeys = [];

    if (!function_exists('demeter_workorder_pair_key')) {
        require_once __DIR__ . '/workorder_state_cache.php';
    }

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }

        if ($normalized !== '') {
            $matches = $explicitDimensionOnly
                ? bc_fetch_row_matches_cost_center_explicit($row, $normalized)
                : bc_fetch_row_matches_cost_center($row, $normalized);
            if (!$matches) {
                continue;
            }
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        $pairKeys[demeter_workorder_pair_key($jobNo, $jobTaskNo)] = true;
    }

    return $pairKeys;
}

/**
 * Filtert werkorders op de werkorderkop (Job_Dimension_1_Value).
 *
 * Is de kop gevuld, dan telt alleen de kop: een werkorder met kop 70 hoort niet bij filter 15,
 * ook als een projectpost die dimensie wel heeft.
 *
 * Is de kop LEEG, dan geldt (in deze volgorde):
 *  1. de kostenplaats van zijn EIGEN ProjectPosten (LVS_Work_Order_No = werkordernummer,
 *     Global_Dimension_1_Code): hij hoort bij het filter als minstens één eigen post die
 *     kostenplaats heeft (Cost_Center_Source = 'projectposten');
 *  2. geven de eigen posten geen kostenplaats (geen eigen posten, of alleen posten zonder code),
 *     dan de projectkaart: Projecten.LVS_Global_Dimension_1_Code van Job_No
 *     (Cost_Center_Source = 'project'). Zo telt ook BC/Ariadne;
 *  3. is de projectkaart leeg of niet opgehaald, en heeft de werkorder geen eigen posten, dan de
 *     posten van andere werkorders van hetzelfde project (Job_No) in het geladen bereik
 *     (Cost_Center_Source = 'projectposten').
 * Bij een match krijgt de werkorder die kostenplaats als Job_Dimension_1_Value, zodat de kolom
 * Kostenplaats en het cachefilter hem ook tonen.
 * Filter 'geen kostenplaats' blijft strikt op de lege kop.
 *
 * @param list<array> $workorders
 * @param list<array> $allPostenRows ProjectPosten van het geladen bereik (voor de fallback).
 * @param bool $deferEmptyHeader Werkorders met lege kop (nog) niet wegfilteren, omdat de posten nog
 *                               niet geladen zijn; de definitieve filtering volgt later.
 * @param array<string, string> $projectCardCodesByJob Kostenplaats van de projectkaart per Job_No
 *                               (sleutel lowercase), zie bc_fetch_project_card_cost_centers().
 * @return list<array>
 */
function bc_fetch_filter_workorders_for_cost_center(
    array $workorders,
    array $allPostenRows,
    string $costCenter,
    bool $deferEmptyHeader = false,
    array $projectCardCodesByJob = []
): array {
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '' || $normalized === bc_fetch_cost_center_none_value()) {
        return bc_fetch_filter_workorders_by_cost_center($workorders, $costCenter);
    }

    $codesByWorkorder = [];
    $codesByJob = [];
    // Eigen posten tellen ook zonder kostenplaats: dan geen terugval op andere werkorders van het project.
    $hasPostsByWorkorder = [];
    foreach ($allPostenRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lvs = strtolower(trim((string) ($row['LVS_Work_Order_No'] ?? '')));
        if ($lvs !== '') {
            $hasPostsByWorkorder[$lvs] = true;
        }
        $code = trim((string) ($row['Global_Dimension_1_Code'] ?? ''));
        if ($code === '') {
            $code = trim((string) ($row['LVS_Global_Dimension_1_Code'] ?? ''));
        }
        if ($code === '') {
            continue;
        }
        if ($lvs !== '') {
            $codesByWorkorder[$lvs][$code] = true;
        }
        $job = strtolower(trim((string) ($row['Job_No'] ?? '')));
        if ($job !== '') {
            $codesByJob[$job][$code] = true;
        }
    }

    $result = [];
    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        if (trim((string) ($workorder['Job_Dimension_1_Value'] ?? '')) !== '') {
            if (bc_fetch_workorder_matches_cost_center($workorder, $normalized)) {
                $result[] = $workorder;
            }
            continue;
        }

        if ($deferEmptyHeader) {
            $result[] = $workorder;
            continue;
        }

        $no = strtolower(trim((string) ($workorder['No'] ?? '')));
        $job = strtolower(trim((string) ($workorder['Job_No'] ?? '')));
        $hasOwnPosts = $no !== '' && isset($hasPostsByWorkorder[$no]);
        $ownCodes = $hasOwnPosts ? ($codesByWorkorder[$no] ?? []) : [];

        if ($ownCodes !== []) {
            // 1. Eigen posten met kostenplaats: die zijn bepalend.
            $codes = $ownCodes;
            $source = 'projectposten';
        } else {
            $cardCode = $job !== '' ? trim((string) ($projectCardCodesByJob[$job] ?? '')) : '';
            if ($cardCode !== '') {
                // 2. Projectkaart (Projecten.LVS_Global_Dimension_1_Code).
                $codes = [$cardCode => true];
                $source = 'project';
            } elseif (!$hasOwnPosts && $job !== '' && isset($codesByJob[$job])) {
                // 3. Posten van andere werkorders van hetzelfde project.
                $codes = $codesByJob[$job];
                $source = 'projectposten';
            } else {
                $codes = [];
                $source = '';
            }
        }

        foreach (array_keys($codes) as $code) {
            if (bc_fetch_cost_centers_match((string) $code, $normalized)) {
                $workorder['Job_Dimension_1_Value'] = (string) $code;
                $workorder['Cost_Center_Source'] = $source;
                $result[] = $workorder;
                break;
            }
        }
    }

    return $result;
}

/**
 * Job_No's (origineel geschreven) van werkorders met lege kop waarvan de eigen ProjectPosten geen
 * kostenplaats geven: voor die werkorders is de projectkaart nodig.
 *
 * @param list<array> $workorders
 * @param list<array> $allPostenRows
 * @return list<string>
 */
function bc_fetch_job_nos_needing_project_card_cost_center(array $workorders, array $allPostenRows): array
{
    $ownCodeNos = [];
    foreach ($allPostenRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $lvs = strtolower(trim((string) ($row['LVS_Work_Order_No'] ?? '')));
        if ($lvs === '' || isset($ownCodeNos[$lvs])) {
            continue;
        }
        $code = trim((string) ($row['Global_Dimension_1_Code'] ?? ''));
        if ($code === '') {
            $code = trim((string) ($row['LVS_Global_Dimension_1_Code'] ?? ''));
        }
        if ($code !== '') {
            $ownCodeNos[$lvs] = true;
        }
    }

    $jobs = [];
    foreach ($workorders as $workorder) {
        if (!is_array($workorder) || trim((string) ($workorder['Job_Dimension_1_Value'] ?? '')) !== '') {
            continue;
        }
        $no = strtolower(trim((string) ($workorder['No'] ?? '')));
        if ($no !== '' && isset($ownCodeNos[$no])) {
            continue;
        }
        $job = trim((string) ($workorder['Job_No'] ?? ''));
        if ($job !== '') {
            $jobs[strtolower($job)] = $job;
        }
    }

    return array_values($jobs);
}

/**
 * Houdt UI-rijen waarvan Cost_Center (werkorderkop) bij de gekozen kostenplaats hoort.
 *
 * @param array<string, mixed> $rowsByKey
 * @return array<string, array>
 */
function bc_fetch_filter_display_rows_for_cost_center(array $rowsByKey, string $costCenter): array
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return $rowsByKey;
    }

    $filtered = [];
    foreach ($rowsByKey as $key => $row) {
        if (!is_array($row)) {
            continue;
        }

        if (!bc_fetch_workorder_matches_cost_center([
            'Job_Dimension_1_Value' => (string) ($row['Cost_Center'] ?? ''),
        ], $normalized)) {
            continue;
        }

        $filtered[$key] = $row;
    }

    return $filtered;
}

/**
 * @param list<array> $workorders
 * @return array<string, bool>
 */
function bc_fetch_pair_keys_from_workorders(array $workorders): array
{
    $pairKeys = [];

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($workorder['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        if (!function_exists('demeter_workorder_pair_key')) {
            require_once __DIR__ . '/workorder_state_cache.php';
        }

        $pairKeys[demeter_workorder_pair_key($jobNo, $jobTaskNo)] = true;
    }

    return $pairKeys;
}

/**
 * Filtert ProjectPosten-rijen op job/task-paren die bij de opgehaalde werkorders horen.
 *
 * @param list<array> $rows
 * @param array<string, bool> $allowedPairKeys
 * @return list<array>
 */
function bc_fetch_filter_projectposten_rows_by_pair_keys(array $rows, array $allowedPairKeys): array
{
    if ($allowedPairKeys === []) {
        return [];
    }

    if (!function_exists('demeter_workorder_pair_key')) {
        require_once __DIR__ . '/workorder_state_cache.php';
    }

    return array_values(array_filter($rows, static function ($row) use ($allowedPairKeys): bool {
        if (!is_array($row)) {
            return false;
        }

        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            return false;
        }

        return isset($allowedPairKeys[demeter_workorder_pair_key($jobNo, $jobTaskNo)]);
    }));
}

/**
 * Houdt ALLE ProjectPosten-rijen van de projecten (Job_No) van de geladen werkorders over,
 * ook posten zonder LVS_Work_Order_No of met een niet-uniek Job/Task-paar.
 * Bedoeld voor projecttotalen (Kosten/Opbrengst project), die net als het BC-projecttotaal
 * elke post van het project moeten meetellen. Werkordertotalen blijven via
 * bc_fetch_filter_projectposten_rows_for_workorders() lopen.
 *
 * @param list<array> $rows
 * @param list<array> $workorders
 * @return list<array>
 */
function bc_fetch_filter_projectposten_rows_for_projects(array $rows, array $workorders): array
{
    $allowedJobs = [];
    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }
        $jobNo = strtolower(trim((string) ($workorder['Job_No'] ?? '')));
        if ($jobNo !== '') {
            $allowedJobs[$jobNo] = true;
        }
    }

    if ($allowedJobs === []) {
        return [];
    }

    return array_values(array_filter($rows, static function ($row) use ($allowedJobs): bool {
        if (!is_array($row)) {
            return false;
        }
        $jobNo = strtolower(trim((string) ($row['Job_No'] ?? '')));

        return $jobNo !== '' && isset($allowedJobs[$jobNo]);
    }));
}

/**
 * Koppelt ProjectPosten aan bekende werkorders via LVS_Work_Order_No.
 * Lege LVS + uniek Job/Task-paar valt terug op dat ene werkorder.
 *
 * @param list<array> $rows
 * @param list<array> $workorders
 * @return list<array>
 */
function bc_fetch_filter_projectposten_rows_for_workorders(array $rows, array $workorders): array
{
    $allowedNos = [];
    $allowedJobs = [];
    $pairCounts = [];
    $uniquePairNos = [];

    foreach ($workorders as $workorder) {
        if (!is_array($workorder)) {
            continue;
        }

        $no = strtolower(trim((string) ($workorder['No'] ?? '')));
        if ($no !== '') {
            $allowedNos[$no] = true;
        }

        $jobNo = trim((string) ($workorder['Job_No'] ?? ''));
        if ($jobNo !== '') {
            $allowedJobs[strtolower($jobNo)] = true;
        }
        $jobTaskNo = trim((string) ($workorder['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            continue;
        }

        if (!function_exists('demeter_workorder_pair_key')) {
            require_once __DIR__ . '/workorder_state_cache.php';
        }

        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);
        $pairCounts[$pairKey] = ($pairCounts[$pairKey] ?? 0) + 1;
        if (($pairCounts[$pairKey] ?? 0) === 1 && $no !== '') {
            $uniquePairNos[$pairKey] = $no;
        } else {
            unset($uniquePairNos[$pairKey]);
        }
    }

    if ($allowedNos === [] && $uniquePairNos === [] && $allowedJobs === []) {
        return [];
    }

    return array_values(array_filter($rows, static function ($row) use ($allowedNos, $uniquePairNos, $allowedJobs): bool {
        if (!is_array($row)) {
            return false;
        }

        $lvs = strtolower(trim((string) ($row['LVS_Work_Order_No'] ?? '')));
        if ($lvs !== '') {
            return isset($allowedNos[$lvs]);
        }

        $description = trim((string) ($row['Description'] ?? ''));
        $jobNo = trim((string) ($row['Job_No'] ?? ''));
        if ($description !== '' && strncasecmp($description, 'IMPORT SAP', 10) === 0) {
            return $jobNo !== '' && isset($allowedJobs[strtolower($jobNo)]);
        }

        $jobTaskNo = trim((string) ($row['Job_Task_No'] ?? ''));
        if ($jobNo === '' || $jobTaskNo === '') {
            return false;
        }

        if (!function_exists('demeter_workorder_pair_key')) {
            require_once __DIR__ . '/workorder_state_cache.php';
        }

        $pairKey = demeter_workorder_pair_key($jobNo, $jobTaskNo);

        return isset($uniquePairNos[$pairKey]);
    }));
}

/**
 * Filtert ProjectPosten-rijen op kostenplaats in PHP (BC OData ondersteunt complexe filters niet altijd).
 *
 * @param list<array> $rows
 * @return list<array>
 */
function bc_fetch_filter_rows_by_cost_center(array $rows, string $costCenter): array
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        return $rows;
    }

    return array_values(array_filter($rows, static function ($row) use ($normalized): bool {
        return is_array($row) && bc_fetch_row_matches_cost_center($row, $normalized);
    }));
}

/**
 * @deprecated BC OData ondersteunt deze OR-filter vaak niet; gebruik PHP-filter na ophalen.
 */
function bc_fetch_cost_center_odata_filter(string $costCenter): string
{
    $normalized = bc_fetch_normalize_cost_center($costCenter);
    if ($normalized === '') {
        throw new InvalidArgumentException('Kostenplaats is verplicht.');
    }

    $escaped = str_replace("'", "''", $normalized);

    return "Global_Dimension_1_Code eq '" . $escaped . "'";
}

/**
 * Voegt een OData-filter toe aan een bestaand OData-filter.
 */
function bc_fetch_append_odata_filter(string $baseFilter, string $additionalFilter): string
{
    $base = trim($baseFilter);
    $additional = trim($additionalFilter);
    if ($additional === '') {
        return $base;
    }

    if ($base === '') {
        return $additional;
    }

    return $base . ' and ' . $additional;
}
