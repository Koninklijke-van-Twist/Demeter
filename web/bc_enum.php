<?php
/**
 * Gedeelde helpers voor BC-enumwaarden. Mímir levert Nederlandse captions
 * ('Gebruik', 'Verkoop', 'Afgesloten'), directe BC-OData levert Engelse
 * enumnamen ('Usage', 'Sale', 'Closed'). Alle vergelijkingen lopen hierlangs.
 */

if (!function_exists('demeter_enum_normalize')) {
    function demeter_enum_normalize(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['_x0020_', '_', '-'], ' ', $normalized);

        return (string) preg_replace('/\s+/', ' ', $normalized);
    }
}

if (!function_exists('demeter_entry_type_canonical')) {
    /**
     * Canonieke Entry_Type: 'usage' | 'sale' | genormaliseerde waarde.
     */
    function demeter_entry_type_canonical(string $value): string
    {
        $normalized = demeter_enum_normalize($value);
        $aliases = [
            'gebruik' => 'usage',
            'usage' => 'usage',
            'verkoop' => 'sale',
            'sale' => 'sale',
            'sales' => 'sale',
        ];

        return $aliases[$normalized] ?? $normalized;
    }
}

if (!function_exists('demeter_enum_values_equal')) {
    /**
     * Vergelijkt twee enumwaarden NL/EN-tolerant (Entry_Type-aliassen, case-insensitive).
     */
    function demeter_enum_values_equal(string $actual, string $expected): bool
    {
        return demeter_entry_type_canonical($actual) === demeter_entry_type_canonical($expected);
    }
}

if (!function_exists('demeter_status_is_closed')) {
    /**
     * Eén gedeelde lijst van afgesloten statussen (NL-caption + EN-enum).
     */
    function demeter_status_is_closed(string $status): bool
    {
        $normalized = demeter_enum_normalize($status);

        return in_array($normalized, [
            'closed', 'afgesloten',
            'completed', 'uitgevoerd', 'gereed',
            'cancelled', 'canceled', 'geannuleerd', 'gecancelled',
            'invoiced', 'gefactureerd',
        ], true);
    }
}

if (!function_exists('demeter_sales_document_type_is_credit')) {
    /**
     * Creditnota / retourorder (NL+EN) → bedrag telt negatief.
     */
    function demeter_sales_document_type_is_credit(string $documentType): bool
    {
        $normalized = str_replace(' ', '', demeter_enum_normalize($documentType));

        return in_array($normalized, ['creditmemo', 'creditnota', 'returnorder', 'retourorder'], true);
    }
}
