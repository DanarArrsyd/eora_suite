<?php

declare(strict_types=1);

namespace App\Platform\Demo;

use App\Platform\Import\ImportColumn;
use App\Platform\Import\ImportTemplates;
use App\Platform\Import\XlsxFile;
use InvalidArgumentException;

/**
 * Sample import files for UAT (doc 29 §1.6, D-081): one small stamping plant in plant P1 — two finished parts, one
 * Semi-Finished Material part, their materials, tooling, routings, BOMs and maps — in the order the templates depend on
 * each other. Rows are written by template header; the dates are the first day the versions may start. One more file,
 * `99-items-with-errors.xlsx`, holds rows that fail on purpose, to show the error rows and the error file.
 */
final class ImportSamples
{
    /**
     * @return list<array{0: string, 1: list<array<string, string>>}> [template code, rows by header] in import order
     */
    public static function files(string $from): array
    {
        $routing = static fn (string $item, string $op, string $process, string $wc, string $cycle, string $basis, array $more = []) => [
            'Plant code' => 'P1', 'Item code' => $item, 'Routing code' => 'R1', 'Routing kind' => 'PRIMARY', 'Effective from' => $from,
            'Standard yield %' => '98.5', 'Op seq' => $op, 'Process type' => $process, 'Work center' => $wc, 'Cycle seconds' => $cycle,
            'Cycle basis' => $basis, ...$more,
        ];
        $bom = static fn (string $item, string $line, string $component, string $qty, string $unit, array $more = []) => [
            'Item code' => $item, 'Effective from' => $from, 'Line no' => $line, 'Component code' => $component, 'Quantity per piece' => $qty,
            'Unit' => $unit, ...$more,
        ];
        $map = static fn (string $item, string $line, string $op) => [
            'Plant code' => 'P1', 'Item code' => $item, 'BOM version' => '1', 'Routing code' => 'R1', 'Routing version' => '1', 'Line no' => $line, 'Op seq' => $op,
        ];

        return [
            ['uoms', [
                ['Code' => 'M', 'Name' => 'Metre', 'Unit class' => 'LENGTH', 'Decimals' => '3'],
                ['Code' => 'L', 'Name' => 'Litre', 'Unit class' => 'VOLUME', 'Decimals' => '3'],
            ]],
            ['items', [
                ['Code' => 'COIL-SPCC-12', 'Name' => 'SPCC coil 1.2 mm x 120 mm', 'Item type' => 'RAW', 'Base unit' => 'KG', 'Incoming release required' => 'YES'],
                ['Code' => 'SHEET-SPCC-16', 'Name' => 'SPCC sheet 1.6 x 1219 x 2438 mm', 'Item type' => 'RAW', 'Base unit' => 'SHEET', 'Incoming release required' => 'YES'],
                ['Code' => 'NUT-M8', 'Name' => 'Weld nut M8', 'Item type' => 'RAW', 'Base unit' => 'PCS'],
                ['Code' => 'PLT-C300', 'Name' => 'Reinforcement plate C300', 'Item type' => 'SEMI_FINISHED', 'Base unit' => 'PCS'],
                ['Code' => 'BRK-A100', 'Name' => 'Bracket front A100', 'Item type' => 'FINISHED', 'Base unit' => 'PCS', 'Inspection required' => 'YES',
                    'Drawing no' => 'DWG-A100', 'Drawing rev' => 'B'],
                ['Code' => 'BRK-B200', 'Name' => 'Bracket rear B200', 'Item type' => 'FINISHED', 'Base unit' => 'PCS', 'Inspection required' => 'YES'],
                ['Code' => 'SCRAP-SPCC', 'Name' => 'SPCC process scrap', 'Item type' => 'SCRAP', 'Base unit' => 'KG', 'Lot controlled' => 'NO'],
                ['Code' => 'OIL-PRESS', 'Name' => 'Press oil', 'Item type' => 'CONSUMABLE', 'Base unit' => 'L', 'Lot controlled' => 'NO'],
            ]],
            ['material_specs', [
                ['Item code' => 'COIL-SPCC-12', 'Grade' => 'SPCC', 'Thickness mm' => '1.2', 'Width mm' => '120', 'Density g/cm3' => '7.85'],
                ['Item code' => 'SHEET-SPCC-16', 'Grade' => 'SPCC', 'Thickness mm' => '1.6', 'Width mm' => '1219', 'Density g/cm3' => '7.85'],
            ]],
            ['uom_conversions', [
                // 1.6 x 1219 x 2438 mm at 7.85 g/cm3
                ['Item code' => 'SHEET-SPCC-16', 'From unit' => 'SHEET', 'To unit' => 'KG', 'Factor' => '37.33', 'Effective from' => $from],
            ]],
            ['customers', [
                ['Code' => 'OEM-A', 'Name' => 'Sample OEM customer A'],
                ['Code' => 'OEM-B', 'Name' => 'Sample OEM customer B'],
                ['Code' => 'OEM-C', 'Name' => 'Sample OEM customer C'],
                ['Code' => 'TIER1-D', 'Name' => 'Sample tier-1 customer D'],
            ]],
            ['customer_items', [
                ['Customer code' => 'OEM-A', 'Customer part no' => 'A-77101-BK', 'Item code' => 'BRK-A100', 'Customer drawing rev' => 'C', 'Effective from' => $from],
                ['Customer code' => 'OEM-B', 'Customer part no' => 'B552-REAR', 'Item code' => 'BRK-B200', 'Effective from' => $from],
                ['Customer code' => 'TIER1-D', 'Customer part no' => 'D-BRK-A100', 'Item code' => 'BRK-A100', 'Effective from' => $from],
            ]],
            ['suppliers', [
                ['Code' => 'SUP-STEEL', 'Name' => 'Sample steel service centre'],
                ['Code' => 'SUP-NUT', 'Name' => 'Sample fastener supplier'],
            ]],
            ['warehouses', [
                ['Plant code' => 'P1', 'Code' => 'RM-WH', 'Name' => 'Raw material warehouse', 'Warehouse type' => 'RAW_MATERIAL'],
                ['Plant code' => 'P1', 'Code' => 'FG-WH', 'Name' => 'Finished goods warehouse', 'Warehouse type' => 'FINISHED_GOODS'],
            ]],
            ['locations', [
                ['Plant code' => 'P1', 'Warehouse code' => 'RM-WH', 'Code' => 'RM-A01', 'Name' => 'Coil rack A01', 'Location type' => 'RM_WAREHOUSE'],
                ['Plant code' => 'P1', 'Warehouse code' => 'RM-WH', 'Code' => 'QC-IN', 'Name' => 'Incoming inspection', 'Location type' => 'QC_AREA'],
                ['Plant code' => 'P1', 'Warehouse code' => 'RM-WH', 'Code' => 'NG-01', 'Name' => 'NG cage', 'Location type' => 'NG_AREA'],
                ['Plant code' => 'P1', 'Warehouse code' => 'RM-WH', 'Code' => 'SCRAP-01', 'Name' => 'Scrap bin area', 'Location type' => 'SCRAP_YARD'],
                ['Plant code' => 'P1', 'Warehouse code' => 'FG-WH', 'Code' => 'FG-A01', 'Name' => 'FG rack A01', 'Location type' => 'FG_WAREHOUSE'],
                ['Plant code' => 'P1', 'Warehouse code' => 'FG-WH', 'Code' => 'STG-01', 'Name' => 'Shipping staging', 'Location type' => 'STAGING'],
            ]],
            ['work_centers', [
                ['Plant code' => 'P1', 'Code' => 'PRS-200', 'Name' => 'Press line 200 t', 'Work center type' => 'PRESS', 'Available hours per shift' => '7.5'],
                ['Plant code' => 'P1', 'Code' => 'PRS-80', 'Name' => 'Press 80 t', 'Work center type' => 'PRESS', 'Available hours per shift' => '7.5'],
                ['Plant code' => 'P1', 'Code' => 'WLD-NUT', 'Name' => 'Nut projection welding', 'Work center type' => 'WELD', 'Available hours per shift' => '7.5'],
                ['Plant code' => 'P1', 'Code' => 'QC-FINAL', 'Name' => 'Final inspection', 'Work center type' => 'INSPECT', 'Available hours per shift' => '7.5'],
            ]],
            ['machines', [
                ['Plant code' => 'P1', 'Work center code' => 'PRS-200', 'Code' => 'M-PRS200', 'Name' => 'Mechanical press 200 t', 'Stroke counter' => 'YES'],
                ['Plant code' => 'P1', 'Work center code' => 'PRS-80', 'Code' => 'M-PRS80', 'Name' => 'Mechanical press 80 t', 'Stroke counter' => 'YES'],
                ['Plant code' => 'P1', 'Work center code' => 'WLD-NUT', 'Code' => 'M-WLD1', 'Name' => 'Projection welder 1'],
            ]],
            ['dies', [
                ['Plant code' => 'P1', 'Code' => 'D-A100-BL', 'Name' => 'Blanking die A100', 'Die type' => 'DIE', 'Cavities' => '1', 'Stroke life' => '500000'],
                ['Plant code' => 'P1', 'Code' => 'D-A100-FM', 'Name' => 'Forming die A100', 'Die type' => 'DIE', 'Cavities' => '1', 'Stroke life' => '500000'],
                ['Plant code' => 'P1', 'Code' => 'D-B200-PG', 'Name' => 'Progressive die B200', 'Die type' => 'DIE', 'Cavities' => '2', 'Stroke life' => '800000'],
                ['Plant code' => 'P1', 'Code' => 'J-A100-WLD', 'Name' => 'Weld jig A100', 'Die type' => 'JIG'],
            ]],
            ['die_items', [
                ['Die code' => 'D-A100-BL', 'Item code' => 'BRK-A100', 'Parts per stroke' => '1'],
                ['Die code' => 'D-A100-FM', 'Item code' => 'BRK-A100', 'Parts per stroke' => '1'],
                ['Die code' => 'D-B200-PG', 'Item code' => 'BRK-B200', 'Parts per stroke' => '2'],
                ['Die code' => 'J-A100-WLD', 'Item code' => 'BRK-A100'],
            ]],
            ['process_types', [
                ['Code' => 'BLANK', 'Name' => 'Blanking', 'Category' => 'TRANSFORMATION', 'Usual work center type' => 'PRESS'],
                ['Code' => 'FORM', 'Name' => 'Forming', 'Category' => 'TRANSFORMATION', 'Usual work center type' => 'PRESS'],
                ['Code' => 'PROG', 'Name' => 'Progressive stamping', 'Category' => 'TRANSFORMATION', 'Usual work center type' => 'PRESS'],
                ['Code' => 'NUTWELD', 'Name' => 'Nut welding', 'Category' => 'TRANSFORMATION', 'Usual work center type' => 'WELD'],
                ['Code' => 'FINSP', 'Name' => 'Final inspection', 'Category' => 'INSPECTION', 'Usual work center type' => 'INSPECT'],
            ]],
            ['routings', [
                $routing('BRK-A100', '10', 'BLANK', 'PRS-200', '4', 'PER_STROKE', ['Setup minutes' => '30', 'Count point' => 'NO', 'Machine' => 'M-PRS200', 'Die' => 'D-A100-BL', 'Cavities used' => '1']),
                $routing('BRK-A100', '20', 'FORM', 'PRS-200', '5', 'PER_STROKE', ['Setup minutes' => '30', 'Count point' => 'NO', 'Machine' => 'M-PRS200', 'Die' => 'D-A100-FM', 'Cavities used' => '1']),
                $routing('BRK-A100', '30', 'NUTWELD', 'WLD-NUT', '12', 'PER_PIECE', ['Count point' => 'NO', 'Machine' => 'M-WLD1', 'Die' => 'J-A100-WLD', 'Cavities used' => '1']),
                $routing('BRK-A100', '40', 'FINSP', 'QC-FINAL', '20', 'PER_PIECE', ['Inspection required' => 'YES']),
                $routing('BRK-B200', '10', 'PROG', 'PRS-80', '3', 'PER_STROKE', ['Setup minutes' => '45', 'Parts per cycle' => '2', 'Count point' => 'NO', 'Machine' => 'M-PRS80', 'Die' => 'D-B200-PG', 'Cavities used' => '2']),
                $routing('BRK-B200', '20', 'FINSP', 'QC-FINAL', '10', 'PER_PIECE', ['Inspection required' => 'YES']),
                $routing('PLT-C300', '10', 'BLANK', 'PRS-80', '3', 'PER_STROKE', ['Setup minutes' => '20', 'Machine' => 'M-PRS80']),
            ]],
            ['boms', [
                $bom('BRK-A100', '10', 'COIL-SPCC-12', '0.32', 'KG', ['Scrap allowance %' => '2']),
                $bom('BRK-A100', '20', 'NUT-M8', '2', 'PCS'),
                $bom('BRK-B200', '10', 'SHEET-SPCC-16', '0.45', 'KG', ['Scrap allowance %' => '3']),
                $bom('PLT-C300', '10', 'SHEET-SPCC-16', '0.02', 'SHEET'),
            ]],
            ['bom_routing_maps', [
                $map('BRK-A100', '10', '10'),
                $map('BRK-A100', '20', '30'),
                $map('BRK-B200', '10', '10'),
                $map('PLT-C300', '10', '10'),
            ]],
        ];
    }

    /** A first effective date as the sample commands take it: YYYY-MM-DD, a real day. */
    public static function isDate(string $from): bool
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $from);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) === 1 && $date !== false && $date->format('Y-m-d') === $from;
    }

    /**
     * Item rows that fail on purpose (doc 29 §2 "one file with deliberate errors"): an unknown unit, a code used twice in
     * the file, an unknown item type, a drawing revision without a drawing number, an item the sample set already
     * created — and one valid row.
     *
     * @return array{0: string, 1: list<array<string, string>>}
     */
    public static function withErrors(): array
    {
        return ['items', [
            ['Code' => 'BRK-Z900', 'Name' => 'Bracket with an unknown unit', 'Item type' => 'FINISHED', 'Base unit' => 'BOX'],
            ['Code' => 'NUT-M10', 'Name' => 'Weld nut M10', 'Item type' => 'RAW', 'Base unit' => 'PCS'],
            ['Code' => 'NUT-M10', 'Name' => 'Weld nut M10 again', 'Item type' => 'RAW', 'Base unit' => 'PCS'],
            ['Code' => 'PLT-X1', 'Name' => 'Plate of an unknown type', 'Item type' => 'WIP', 'Base unit' => 'PCS'],
            ['Code' => 'BRK-Z901', 'Name' => 'Bracket with a revision only', 'Item type' => 'FINISHED', 'Base unit' => 'PCS', 'Drawing rev' => 'A'],
            ['Code' => 'BRK-A100', 'Name' => 'Bracket front A100 again', 'Item type' => 'FINISHED', 'Base unit' => 'PCS'],
        ]];
    }

    /**
     * Writes the sample set into $dir as filled templates of the current versions, numbered in import order, plus the
     * file with deliberate errors. A sample row naming a header the template does not have is refused, so the samples
     * cannot drift from the templates unnoticed.
     *
     * @return list<array{template: string, path: string, rows: int}> in import order; the error file last
     */
    public static function write(ImportTemplates $templates, string $dir, string $from): array
    {
        $files = [];
        $sets = ImportSamples::files($from);
        $sets[] = self::withErrors();
        foreach ($sets as $i => [$code, $rows]) {
            $headers = array_map(static fn (ImportColumn $c) => $c->header, $templates->get($code)->columns());
            foreach ($rows as $row) {
                $unknown = array_diff(array_keys($row), $headers);
                if ($unknown !== []) {
                    throw new InvalidArgumentException(sprintf('Sample %s names headers the template does not have: %s.', $code, implode(', ', $unknown)));
                }
            }
            $name = $i === count($sets) - 1 ? '99-items-with-errors' : sprintf('%02d-%s', $i + 1, str_replace('_', '-', $code));
            $path = "{$dir}/{$name}.xlsx";
            XlsxFile::write($path, $headers, array_map(static fn (array $row) => array_map(static fn (string $h) => $row[$h] ?? '', $headers), $rows));
            $files[] = ['template' => $code, 'path' => $path, 'rows' => count($rows)];
        }

        return $files;
    }
}
