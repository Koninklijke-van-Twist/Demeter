<?php
/**
 * Gesimuleerde BC voor store-tests: evalueert de OData-filters die store.php genereert.
 */

final class DemeterFakeBc
{
    /** @var array<string, array> */
    public array $workorders = [];
    /** @var list<array> */
    public array $postings = [];
    public array $calls = ['count' => 0, 'fetch' => 0, 'rows' => 0];
    /** @var callable|null */
    public $countTamper = null;

    public function addWo(string $no, string $afd, string $status, string $start, array $extra = []): void
    {
        $this->workorders[$no] = array_merge([
            'No' => $no, 'Task_Code' => 'CO', 'Task_Description' => 'Taak ' . $no, 'Status' => $status,
            'KVT_Document_Status' => '10-OPEN', 'Job_No' => 'PRJ' . $no, 'Job_Task_No' => $no, 'Contract_No' => '',
            'Start_Date' => $start, 'End_Date' => $start, 'Sub_Entity_Description' => '', 'Component_No' => '',
            'Bill_to_Customer_No' => 'C1', 'Bill_to_Name' => 'Klant 1', 'Sell_to_Customer_No' => 'C1', 'Sell_to_Name' => 'Klant 1',
            'Job_Dimension_1_Value' => $afd, 'Created_Date_Time' => '2026-01-01T08:00:00Z',
        ], $extra);
    }

    public function addPosting(string $wo, string $type, float $cost, float $amount, string $job = '', string $task = ''): int
    {
        $entryNo = count($this->postings) === 0 ? 1000 : max(array_column($this->postings, 'Entry_No')) + 1;
        $this->postings[] = ['Entry_No' => $entryNo, 'Job_No' => $job !== '' ? $job : 'PRJ' . $wo, 'Job_Task_No' => $task !== '' ? $task : $wo,
            'LVS_Work_Order_No' => $wo, 'Entry_Type' => $type, 'Total_Cost' => $cost, 'Line_Amount_LCY' => $amount];

        return $entryNo;
    }

    public static function splitTop(string $s, string $sep): array
    {
        $parts = [];
        $depth = 0;
        $buf = '';
        $inQ = false;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($c === "'") {
                $inQ = !$inQ;
            }
            if (!$inQ && $c === '(') {
                $depth++;
            }
            if (!$inQ && $c === ')') {
                $depth--;
            }
            if (!$inQ && $depth === 0 && substr($s, $i, strlen($sep)) === $sep) {
                $parts[] = $buf;
                $buf = '';
                $i += strlen($sep) - 1;
                continue;
            }
            $buf .= $c;
        }
        $parts[] = $buf;

        return $parts;
    }

    public static function match(array $row, string $filter): bool
    {
        $filter = trim($filter);
        if ($filter === '') {
            return true;
        }
        foreach (self::splitTop($filter, ' and ') as $part) {
            $part = trim($part);
            $ors = self::splitTop($part, ' or ');
            if (count($ors) === 1 && $part[0] === '(' && substr($part, -1) === ')') {
                if (!self::match($row, substr($part, 1, -1))) {
                    return false;
                }
                continue;
            }
            $any = false;
            foreach ($ors as $or) {
                $or = trim($or, " ()");
                if (!preg_match("/^(\w+) (eq|ne|gt|ge|lt|le) (.+)$/", $or, $m)) {
                    throw new RuntimeException('Fake BC kan filter niet lezen: ' . $or);
                }
                $val = $m[3];
                if ($val[0] === "'") {
                    $val = str_replace("''", "'", substr($val, 1, -1));
                }
                $actual = $row[$m[1]] ?? '';
                $cmp = is_numeric($actual) && is_numeric($val) ? ((float) $actual <=> (float) $val) : strcmp((string) $actual, (string) $val);
                $ok = ['eq' => $cmp === 0, 'ne' => $cmp !== 0, 'gt' => $cmp > 0, 'ge' => $cmp >= 0, 'lt' => $cmp < 0, 'le' => $cmp <= 0][$m[2]];
                if ($ok) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                return false;
            }
        }

        return true;
    }

    public function transport(): array
    {
        return [
            'count' => function (string $entity, string $filter): int {
                $this->calls['count']++;
                $n = 0;
                foreach ($entity === 'Werkorders' ? $this->workorders : $this->postings as $row) {
                    if (self::match($row, $filter)) {
                        $n++;
                    }
                }
                if ($this->countTamper !== null) {
                    $n = ($this->countTamper)($filter, $n);
                }

                return $n;
            },
            'fetch' => function (string $entity, array $query): array {
                $this->calls['fetch']++;
                $out = [];
                foreach ($entity === 'Werkorders' ? $this->workorders : $this->postings as $row) {
                    if (self::match($row, (string) ($query['$filter'] ?? ''))) {
                        $out[] = $row;
                    }
                }
                $this->calls['rows'] += count($out);

                return $out;
            },
        ];
    }
}
