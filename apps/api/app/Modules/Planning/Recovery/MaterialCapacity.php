<?php

declare(strict_types=1);

namespace App\Modules\Planning\Recovery;

use App\Modules\Planning\Mps\MpsItemInput;
use App\Modules\Planning\Mrp\MrpPlanner;
use App\Platform\Database\EoraPostgresConnection;

/** Estimates whole FG PCS from approved BOMs and one shared, uncommitted component-stock pool. */
final class MaterialCapacity
{
    /** @var array<string,?array<int,array{item:string,type:string,per:string,denominator:?string,decimals:int}>> */
    private array $recipes = [];

    /** @var array<string,array{other:string,yield:numeric-string}> */
    private array $pairedOutputs = [];

    /** @var array<string,true> */
    public array $warnings = [];

    public function __construct(private readonly MrpPlanner $conversions) {}

    /**
     * @param  array<string,numeric-string>  $pool
     * @return ?numeric-string
     */
    public function estimate(EoraPostgresConnection $db, string $plant, string $item, string $date, array $pool): ?string
    {
        $this->warnings = [];
        if ($this->recipe($db, $plant, $item, $date) === null) {
            return null;
        }
        $low = 0;
        $high = 1000000000;
        while ($low < $high) {
            $middle = intdiv($low + $high + 1, 2);
            $copy = $pool;
            if ($this->consume($db, $plant, $item, (string) $middle, $date, $copy, [])) {
                $low = $middle;
            } else {
                $high = $middle - 1;
            }
        }

        return (string) $low;
    }

    /**
     * @param  array<string,numeric-string>  $pool
     * @param  list<string>  $path
     */
    public function consume(EoraPostgresConnection $db, string $plant, string $item, string $qty, string $date, array &$pool, array $path, bool $reserveShortages = false): bool
    {
        $qty = MpsItemInput::num($qty);
        if (in_array($item, $path, true) || count($path) >= 20) {
            $this->warnings['BOM_CYCLE_OR_DEPTH'] = true;

            return false;
        }
        $recipe = $this->recipe($db, $plant, $item, $date);
        if ($recipe === null) {
            return false;
        }
        $covered = true;
        foreach ($recipe as $line) {
            $need = $line['denominator'] === null ? bcmul($qty, MpsItemInput::num($line['per']), 6) : bcdiv($qty, MpsItemInput::num($line['denominator']), 6);
            $scale = bcpow('10', (string) $line['decimals'], 0);
            $scaled = bcmul($need, $scale, 6);
            $rounded = bcdiv($scaled, '1', 0);
            if (bccomp($scaled, $rounded, 6) > 0) {
                $rounded = bcadd($rounded, '1', 0);
            }
            $need = bcdiv($rounded, $scale, 6);
            $available = $pool[$line['item']] ?? '0';
            $take = bccomp($need, $available, 6) < 0 ? $need : $available;
            $pool[$line['item']] = bcsub($available, $take, 6);
            $remaining = bcsub($need, $take, 6);
            if (bccomp($remaining, '0', 6) > 0 && ($line['type'] !== 'SEMI_FINISHED'
                || ! $this->consume($db, $plant, $line['item'], $remaining, $date, $pool, [...$path, $item], $reserveShortages))) {
                if (! $reserveShortages) {
                    return false;
                }
                // A draft still needs the other components when this one is short.
                $covered = false;
            }
        }
        if (! $covered) {
            return false;
        }

        $pair = $this->pairedOutputs[$plant.'|'.$item.'|'.$date] ?? null;
        if ($pair !== null) {
            $sheets = bcdiv($qty, $pair['yield'], 0);
            if (bccomp(bcmul($sheets, $pair['yield'], 0), $qty, 6) < 0) {
                $sheets = bcadd($sheets, '1', 0);
            }
            $produced = bcmul($sheets, $pair['yield'], 0);
            $pool[$item] = bcadd($pool[$item] ?? '0', bcsub($produced, $qty, 6), 6);
            $pool[$pair['other']] = bcadd($pool[$pair['other']] ?? '0', $produced, 6);
        }

        return true;
    }

    /** @return ?array<int,array{item:string,type:string,per:string,denominator:?string,decimals:int}> */
    private function recipe(EoraPostgresConnection $db, string $plant, string $item, string $date): ?array
    {
        $key = $plant.'|'.$item.'|'.$date;
        if (array_key_exists($key, $this->recipes)) {
            return $this->recipes[$key];
        }
        $pair = $db->selectOne("select r.item_id, v.paired_item_id, v.pairs_per_sheet from routings r join routing_versions v on v.routing_id = r.id
            where r.plant_id = ? and r.routing_kind = 'PRIMARY' and r.status = 'ACTIVE'
              and ?::uuid in (r.item_id,v.paired_item_id) and v.paired_item_id is not null
              and v.status in ('APPROVED','SUPERSEDED') and v.effective_from <= ?::date and (v.effective_to is null or v.effective_to >= ?::date)
            order by v.effective_from desc limit 1", [$plant, $item, $date, $date]);
        if ($pair !== null) {
            $this->pairedOutputs[$key] = ['other' => (string) ($pair->item_id === $item ? $pair->paired_item_id : $pair->item_id), 'yield' => MpsItemInput::num($pair->pairs_per_sheet)];
        }
        $bom = $db->selectOne("select id from bom_versions where item_id = ? and status in ('APPROVED','SUPERSEDED')
            and effective_from <= ?::date and (effective_to is null or effective_to >= ?::date) order by effective_from desc limit 1", [$pair->item_id ?? $item, $date, $date]);
        if ($bom === null) {
            $this->warnings['NO_APPROVED_BOM'] = true;

            return $this->recipes[$key] = null;
        }
        $lines = [];
        foreach ($db->select("select l.component_item_id, l.gross_qty_per::text as per, l.uom_id, i.base_uom_id, i.item_type, u.decimals, u.code as base_uom
             from bom_lines l join items i on i.id = l.component_item_id join uoms u on u.id = i.base_uom_id
             where l.bom_version_id = ? and i.item_type <> 'SCRAP' order by l.line_no", [$bom->id]) as $line) {
            if ($pair !== null && ($line->item_type !== 'RAW' || $line->base_uom !== 'SHEET' || $lines !== [])) {
                $this->warnings['PAIRED_BOM_INVALID'] = true;

                return $this->recipes[$key] = null;
            }
            $per = $this->conversions->toBase($db, (string) $line->component_item_id, (string) $line->uom_id, (string) $line->base_uom_id, MpsItemInput::num($line->per), $date);
            if ($per === null) {
                $this->warnings['UOM_CONVERSION_MISSING'] = true;

                return $this->recipes[$key] = null;
            }
            $lines[] = ['item' => (string) $line->component_item_id, 'type' => (string) $line->item_type, 'per' => $per,
                'denominator' => $pair === null ? null : (string) $pair->pairs_per_sheet, 'decimals' => (int) $line->decimals];
        }
        if ($lines === []) {
            $this->warnings['NO_APPROVED_BOM'] = true;
        }

        return $this->recipes[$key] = ($lines === [] ? null : $lines);
    }
}
