<?php

declare(strict_types=1);

namespace App\Modules\Planning\Mps;

use LogicException;

/** The inputs of one FG item of an MPS calculation (MpsInputs), quantities as decimal strings. */
final class MpsItemInput
{
    /** @var numeric-string */
    public string $onHand = '0';

    /** @var numeric-string */
    public string $outOfScope = '0';

    /** @var ?numeric-string */
    public ?string $safetyStock = null;

    /** @var numeric-string */
    public string $pendingQc = '0';

    public ?int $leadTimeDays = null;

    /** @var list<array{key: string, kind: 'FIRM'|'FORECAST', date: string, qty: numeric-string}> */
    public array $demands = [];

    /** @var list<array{key: string, kind: string, date: string, qty: numeric-string}> */
    public array $supplies = [];

    public function __construct(public readonly string $itemId, public readonly string $uomId) {}

    /** @return numeric-string */
    public static function num(mixed $value): string
    {
        $text = is_scalar($value) ? (string) $value : '';

        return is_numeric($text) ? $text : throw new LogicException("Not a quantity: {$text}");
    }

    /**
     * @return array{onHand: numeric-string, outOfScope: numeric-string, safetyStock: ?numeric-string, leadTimeDays: ?int,
     *               demands: list<array{key: string, kind: 'FIRM'|'FORECAST', date: string, qty: numeric-string}>,
     *               supplies: list<array{key: string, kind: string, date: string, qty: numeric-string}>}
     */
    public function forEngine(): array
    {
        return ['onHand' => $this->onHand, 'outOfScope' => $this->outOfScope, 'safetyStock' => $this->safetyStock, 'leadTimeDays' => $this->leadTimeDays,
            'demands' => $this->demands, 'supplies' => $this->supplies];
    }

    /** @return array<string, mixed> everything, demand and supply in a stable order (the fingerprint) */
    public function snapshot(): array
    {
        $order = static fn (array $a, array $b): int => [$a['kind'], $a['key']] <=> [$b['kind'], $b['key']];
        $demands = $this->demands;
        $supplies = $this->supplies;
        usort($demands, $order);
        usort($supplies, $order);

        return ['itemId' => $this->itemId, 'uomId' => $this->uomId, 'onHand' => $this->onHand, 'outOfScope' => $this->outOfScope, 'safetyStock' => $this->safetyStock,
            'pendingQc' => $this->pendingQc, 'leadTimeDays' => $this->leadTimeDays, 'demands' => $demands, 'supplies' => $supplies];
    }
}
