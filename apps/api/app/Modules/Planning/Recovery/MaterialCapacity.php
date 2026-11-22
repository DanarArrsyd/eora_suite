<?php

declare(strict_types=1);

namespace App\Modules\Planning\Recovery;

use App\Modules\Planning\Mps\MpsItemInput;
use App\Modules\Planning\Mrp\MrpPlanner;
use App\Platform\Database\EoraPostgresConnection;

/**
 * Estimates whole FG PCS from approved BOMs and the uncommitted component stock, kept per owner (D-141, doc 39): the pool is
 * keyed `owner|item`, owner `COMPANY` or a customer id. A customer's part uses that customer's own material first (D-1);
 * company material serves it when the customer never supplied that material or is marked *company may top up* (D-3).
 * Customer material never serves anyone else.
 */
final class MaterialCapacity
{
    public const string COMPANY = 'COMPANY';

    /** @var array<string,array<string,true>> customer => materials it supplies (owns a lot of) */
    private array $supplied = [];

    /** @var array<string,bool> customer => company may top up */
    private array $topUp = [];

    /** @var array<string,?array<int,array{item:string,type:string,per:string,denominator:?string,decimals:int}>> */
    private array $recipes = [];

    /** @var array<string,array{other:string,yield:numeric-string}> */
    private array $pairedOutputs = [];

    /** @var array<string,true> */
    public array $warnings = [];

    public function __construct(private readonly MrpPlanner $conversions) {}

    /** Who supplies which material and who may be topped up, read once per calculation. */
    public function loadOwners(EoraPostgresConnection $db): void
    {
        $this->supplied = [];
        foreach ($db->select('select distinct owner_customer_id, item_id from lots where owner_customer_id is not null') as $row) {
            $this->supplied[(string) $row->owner_customer_id][(string) $row->item_id] = true;
        }
        $this->topUp = [];
        foreach ($db->select('select id, company_material_topup from customers where company_material_topup') as $row) {
            $this->topUp[(string) $row->id] = true;
        }
    }

    /** Whether company material may serve this customer's need of the item (an unattributed need: always). */
    public function companyServes(?string $customer, string $item): bool
    {
        return $customer === null || ! isset($this->supplied[$customer][$item]) || ($this->topUp[$customer] ?? false);
    }

    /** Takes up to `$need` of the item for the customer: its own material first, then company material where allowed.
     * @param  array<string,numeric-string>  $pool
     * @param  numeric-string  $need
     * @return array{taken: numeric-string, own: bool}
     */
    public function take(array &$pool, ?string $customer, string $item, string $need): array
    {
        $taken = '0';
        $own = false;
        foreach ($customer === null ? [self::COMPANY] : [$customer, self::COMPANY] as $owner) {
            if ($owner === self::COMPANY && ! $this->companyServes($customer, $item)) {
                break;
            }
            $left = bcsub($need, $taken, 6);
            if (bccomp($left, '0', 6) <= 0) {
                break;
            }
            $available = $pool[$owner.'|'.$item] ?? '0';
            $part = bccomp($left, $available, 6) < 0 ? $left : $available;
            if (bccomp($part, '0', 6) > 0) {
                $pool[$owner.'|'.$item] = bcsub($available, $part, 6);
                $taken = bcadd($taken, $part, 6);
                $own = $own || $owner !== self::COMPANY;
            }
        }

        return ['taken' => $taken, 'own' => $own];
    }

    /**
     * @param  array<string,numeric-string>  $pool
     * @return ?numeric-string
     */
    public function estimate(EoraPostgresConnection $db, string $plant, string $item, string $date, array $pool, ?string $customer = null): ?string
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
            if ($this->consume($db, $plant, $item, (string) $middle, $date, $copy, [], false, $customer)) {
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
    public function consume(EoraPostgresConnection $db, string $plant, string $item, string $qty, string $date, array &$pool, array $path, bool $reserveShortages = false, ?string $customer = null): bool
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
        // a sheet of customer material makes customer-owned output, its paired surplus included (one owner per posting, D-135)
        $ownUsed = false;
        foreach ($recipe as $line) {
            $need = $line['denominator'] === null ? bcmul($qty, MpsItemInput::num($line['per']), 6) : bcdiv($qty, MpsItemInput::num($line['denominator']), 6);
            $scale = bcpow('10', (string) $line['decimals'], 0);
            $scaled = bcmul($need, $scale, 6);
            $rounded = bcdiv($scaled, '1', 0);
            if (bccomp($scaled, $rounded, 6) > 0) {
                $rounded = bcadd($rounded, '1', 0);
            }
            $need = bcdiv($rounded, $scale, 6);
            $took = $this->take($pool, $customer, $line['item'], $need);
            $ownUsed = $ownUsed || $took['own'];
            $remaining = bcsub($need, $took['taken'], 6);
            if (bccomp($remaining, '0', 6) > 0 && ($line['type'] !== 'SEMI_FINISHED'
                || ! $this->consume($db, $plant, $line['item'], $remaining, $date, $pool, [...$path, $item], $reserveShortages, $customer))) {
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
            $owner = $ownUsed && $customer !== null ? $customer : self::COMPANY;
            $pool[$owner.'|'.$item] = bcadd($pool[$owner.'|'.$item] ?? '0', bcsub($produced, $qty, 6), 6);
            $pool[$owner.'|'.$pair['other']] = bcadd($pool[$owner.'|'.$pair['other']] ?? '0', $produced, 6);
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
