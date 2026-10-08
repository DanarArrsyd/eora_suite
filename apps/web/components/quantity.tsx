/** QuantityWithUom (business component, EORA-COMPONENT-STANDARDS §5): value + UOM together; kg 3 decimals, pcs/sheet 0; zero as "–". */
export function QuantityWithUom({ value, uom }: { value: number; uom: string }) {
  if (!value) {
    return (
      <span className="text-ink-2">
        <span aria-hidden="true">–</span>
        <span className="sr-only">zero</span>
      </span>
    );
  }
  const decimals = uom === 'kg' ? 3 : 0;
  return (
    <span className="tabular-nums">
      {value.toLocaleString('en-US', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}
      <span data-sub className="ml-[3px] text-xs text-ink-2">
        {uom}
      </span>
    </span>
  );
}

export function LocationCode({ code }: { code: string }) {
  return <code className="rounded-sm bg-stat-neutral-bg px-1.5 py-0.5 font-mono text-xs text-ink">{code}</code>;
}
