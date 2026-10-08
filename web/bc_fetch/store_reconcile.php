<?php
/**
 * Reconciliatie: store vs volledige BC-fetch, per bedrijf × afdeling, rij voor rij op werkordernummer
 * (alle store-velden) plus kosten (Σ ProjectPosten per werkorder, tolerantie € 0,01). Alleen lezen.
 */

require_once __DIR__ . '/store.php';

/**
 * @param list<string>|null $afdelingen null = alle afdelingen (volledige bedrijfsfetch)
 */
function demeter_store_reconcile(array $store, array $transport, ?array $afdelingen = null): array
{
    $started = microtime(true);
    $bcRows = [];
    if ($afdelingen === null) {
        $bcRows = demeter_store_fetch_all_workorders($transport);
    } else {
        foreach ($afdelingen as $afdeling) {
            $bcRows = array_merge($bcRows, demeter_store_fetch_all_workorders($transport, 'Job_Dimension_1_Value eq ' . demeter_store_q((string) $afdeling)));
        }
    }
    $fetchWoMs = (int) round((microtime(true) - $started) * 1000);
    $postings = demeter_store_fetch_all_postings($transport);
    $result = demeter_store_reconcile_compare($store, $bcRows, $postings, $afdelingen);
    $result['fetch_workorders_ms'] = $fetchWoMs;
    $result['duration_ms'] = (int) round((microtime(true) - $started) * 1000);

    return $result;
}

/**
 * Vergelijkt een store met BC-rijen + BC-posten (zonder I/O). Posten ná de store-stand (Entry_No > max)
 * tellen niet mee, zodat een boeking na de laatste sync geen vals verschil geeft.
 */
function demeter_store_reconcile_compare(array $store, array $bcRows, array $postings, ?array $afdelingen = null): array
{
    $started = microtime(true);
    $fetchWoMs = 0;
    $bcFin = demeter_store_empty($store['company'] ?? '');
    usort($postings, static function (array $a, array $b): int {
        return (int) ($a['Entry_No'] ?? 0) <=> (int) ($b['Entry_No'] ?? 0);
    });
    foreach ($postings as $p) {
        if ((int) ($p['Entry_No'] ?? 0) <= (int) ($store['max_entry_no'] ?? 0)) {
            demeter_store_apply_posting($bcFin, $p);
        }
    }
    $newerPostings = count(array_filter($postings, static function (array $p) use ($store): bool {
        return (int) ($p['Entry_No'] ?? 0) > (int) ($store['max_entry_no'] ?? 0);
    }));

    $bcByNo = [];
    foreach ($bcRows as $row) {
        $norm = demeter_store_normalize_row($row);
        $bcByNo[$norm['No']] = $norm;
    }
    $inScope = static function (array $row) use ($afdelingen): bool {
        return $afdelingen === null || in_array((string) $row['Job_Dimension_1_Value'], array_map('strval', $afdelingen), true);
    };
    $per = [];
    $bump = static function (string $afdeling, string $key, $detail = null) use (&$per): void {
        if (!isset($per[$afdeling])) {
            $per[$afdeling] = ['bc' => 0, 'store' => 0, 'missing' => 0, 'extra' => 0, 'changed' => 0, 'finance' => 0, 'examples' => []];
        }
        $per[$afdeling][$key]++;
        if ($detail !== null && count($per[$afdeling]['examples']) < 15) {
            $per[$afdeling]['examples'][] = $detail;
        }
    };
    foreach ($bcByNo as $no => $row) {
        $bump($row['Job_Dimension_1_Value'], 'bc');
    }
    foreach ($store['workorders'] as $no => $row) {
        if ($inScope($row)) {
            $bump((string) $row['Job_Dimension_1_Value'], 'store');
        }
    }
    foreach ($bcByNo as $no => $bcRow) {
        $storeRow = $store['workorders'][$no] ?? null;
        if ($storeRow === null) {
            $bump($bcRow['Job_Dimension_1_Value'], 'missing', ['No' => $no, 'type' => 'missing']);
            continue;
        }
        $fields = [];
        foreach (demeter_store_workorder_fields() as $field) {
            if ((string) ($storeRow[$field] ?? '') !== (string) $bcRow[$field]) {
                $fields[$field] = ['bc' => $bcRow[$field], 'store' => $storeRow[$field] ?? null];
            }
        }
        if ($fields !== []) {
            $bump($bcRow['Job_Dimension_1_Value'], 'changed', ['No' => $no, 'type' => 'changed', 'fields' => $fields]);
        }
        $a = demeter_store_finance_for($bcFin, (string) $no);
        $b = demeter_store_finance_for($store, (string) $no);
        foreach (['cost', 'usage_amount', 'sale_amount'] as $k) {
            if (abs((float) $a[$k] - (float) $b[$k]) > 0.01 || (int) $a['entries'] !== (int) $b['entries']) {
                $bump($bcRow['Job_Dimension_1_Value'], 'finance', ['No' => $no, 'type' => 'finance', 'bc' => $a, 'store' => $b]);
                break;
            }
        }
    }
    foreach ($store['workorders'] as $no => $row) {
        if ($inScope($row) && !isset($bcByNo[(string) $no])) {
            $bump((string) $row['Job_Dimension_1_Value'], 'extra', ['No' => (string) $no, 'type' => 'extra']);
        }
    }
    // Kosten zonder werkordernummer (job|taak) bedrijfsbreed.
    $jobTaskDiffs = 0;
    $keys = array_unique(array_merge(array_keys($bcFin['finance_jobtask']), array_keys($store['finance_jobtask'] ?? [])));
    foreach ($keys as $key) {
        $a = $bcFin['finance_jobtask'][$key] ?? demeter_store_empty_finance();
        $b = $store['finance_jobtask'][$key] ?? demeter_store_empty_finance();
        if (abs($a['cost'] - $b['cost']) > 0.01 || abs($a['sale_amount'] - $b['sale_amount']) > 0.01 || abs($a['usage_amount'] - $b['usage_amount']) > 0.01) {
            $jobTaskDiffs++;
        }
    }
    $totalDiffs = $jobTaskDiffs;
    foreach ($per as $a => $p) {
        $totalDiffs += $p['missing'] + $p['extra'] + $p['changed'] + $p['finance'];
    }
    ksort($per);

    return [
        'company' => $store['company'] ?? '',
        'at' => time(),
        'store_synced_at' => (int) ($store['synced_at'] ?? 0),
        'afdelingen' => $afdelingen,
        'per_afdeling' => $per,
        'jobtask_finance_diffs' => $jobTaskDiffs,
        'postings_after_store' => $newerPostings,
        'total_diffs' => $totalDiffs,
        'bc_rows' => count($bcByNo),
        'fetch_workorders_ms' => $fetchWoMs,
        'duration_ms' => (int) round((microtime(true) - $started) * 1000),
    ];
}

function demeter_store_reconcile_summary(array $r): string
{
    $out = sprintf("Reconciliatie %s (%s): %d verschillen\n", $r['company'], date('Y-m-d H:i:s T', $r['at']), $r['total_diffs']);
    foreach ($r['per_afdeling'] as $afdeling => $p) {
        $out .= sprintf("  afdeling %-6s BC %5d / store %5d | ontbreekt %d, extra %d, velden %d, kosten %d\n",
            $afdeling === '' ? '(leeg)' : $afdeling, $p['bc'], $p['store'], $p['missing'], $p['extra'], $p['changed'], $p['finance']);
    }
    $out .= sprintf("  kosten zonder werkorder (job|taak): %d verschillen; posten na store-stand: %d; duur %.1fs\n",
        $r['jobtask_finance_diffs'], $r['postings_after_store'], $r['duration_ms'] / 1000);

    return $out;
}

/** Bewaart het resultaat compact in de store (onder lock, zonder de werkorders te wijzigen). */
function demeter_store_record_reconciliation(string $company, array $r): void
{
    demeter_store_with_lock($company, static function () use ($company, $r) {
        $store = demeter_store_read($company);
        if ($store === null) {
            return;
        }
        $summary = $r;
        foreach ($summary['per_afdeling'] as &$p) {
            $p['examples'] = array_slice($p['examples'], 0, 5);
        }
        unset($p);
        $store['last_reconciliation'] = $summary;
        demeter_store_write($company, $store);
    }, 120.0);
}
