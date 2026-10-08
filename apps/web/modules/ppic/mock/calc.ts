/**
 * Reference implementations of APPROVED formulas, used only by the mock adapters and their tests.
 * Production computes these on the server (Laravel); the UI never recomputes them.
 * Sources: doc 02, ADR-0005 (conversion + variance indicators), doc 15 BR-DEM-08/10/13/14 (demand).
 */
import type { ConversionInputs, ConversionStatus, IndicatorStatus } from '../contracts';

/* ------------------------------------------------------------------ conversion (doc 02, doc 19 CT-1) ------------------------------------------------------------------ */

export type ConversionResult = {
  status: ConversionStatus;
  missing: string[];
  lengthMm: number | null;
  strokes: number | null;
  upper: number | null;
  lower: number | null;
};

const NOT_CALC = (missing: string[]): ConversionResult => ({ status: 'NOT_CALCULABLE', missing, lengthMm: null, strokes: null, upper: null, lower: null });

/**
 * Coil: L_mm = kg × 10^6 / (ρ[g/cm³] × t[mm] × w[mm]); strokes = floor((L − head/tail) / pitch_mm); pcs = strokes × cavity;
 * lower bound = floor(pcs × SY). Unit-less pitch → NOT_CALCULABLE (doc 14 #5). Status PROVISIONAL when CP unvalidated or nominal inputs.
 */
export function coilEstimate(kg: number, i: ConversionInputs, opts: { cpValidated: boolean; nominal: boolean }): ConversionResult {
  const missing: string[] = [];
  if (i.pitch !== undefined && !i.pitchUnit) missing.push('pitch unit');
  if (i.pitch === undefined) missing.push('pitch');
  if (!i.thicknessMm) missing.push('thickness');
  if (!i.widthMm) missing.push('width');
  if (!i.densityGcm3) missing.push('density');
  if (!i.cavity) missing.push('cavity');
  if (i.headTailMm === undefined) missing.push('head/tail allowance');
  if (missing.length || kg <= 0) return NOT_CALC(missing.length ? missing : ['net usable mass']);
  const lengthMm = (kg * 1e6) / (i.densityGcm3! * i.thicknessMm! * i.widthMm!);
  const strokes = Math.max(0, Math.floor((lengthMm - i.headTailMm!) / i.pitch!));
  const upper = strokes * i.cavity!;
  const lower = i.standardYield !== undefined ? Math.floor(upper * i.standardYield) : null;
  return { status: opts.cpValidated && !opts.nominal ? 'CALCULATED' : 'PROVISIONAL', missing: [], lengthMm, strokes, upper, lower };
}

/** Sheet: pcs = floor(eligible_sheets × blanks_per_sheet × parts_per_blank); lower bound applies SY. */
export function sheetEstimate(sheets: number, i: ConversionInputs, opts: { cpValidated: boolean; nominal: boolean }): ConversionResult {
  const missing: string[] = [];
  if (!i.blanksPerSheet) missing.push('blanks per sheet');
  if (!i.partsPerBlank) missing.push('parts per blank');
  if (missing.length) return NOT_CALC(missing);
  const upper = Math.floor(sheets * i.blanksPerSheet! * i.partsPerBlank!);
  const lower = i.standardYield !== undefined ? Math.floor(upper * i.standardYield) : null;
  return { status: opts.cpValidated && !opts.nominal ? 'CALCULATED' : 'PROVISIONAL', missing: [], lengthMm: null, strokes: null, upper, lower };
}

/* ------------------------------------------------------------------ variance indicators (ADR-0005) ------------------------------------------------------------------ */

export type IndicatorInputs = {
  T: number | null; // theoretical gross pcs (floored) from measured C via validated CP
  G: number;
  NR: number;
  NJ: number;
  O: number;
  SY: number | null;
  NW: number | null; // kg/pc
  Ckg: number | null;
  S: number | null; // weighed scrap kg (null = not weighed and not confirmed zero)
  D?: number;
  measured: boolean; // every consume line independently measured (ADR-0003)
  cpValidated: boolean;
  reportingClosed: boolean;
};

export type IndicatorResult = {
  gyv: number | null;
  gyvPct: number | null;
  gyvStatus: IndicatorStatus;
  pvs: number | null;
  pvsPct: number | null;
  pvsStatus: IndicatorStatus;
  umvKg: number | null;
  umvPct: number | null;
  umvStatus: IndicatorStatus;
};

const round = (v: number, d: number) => Math.round(v * 10 ** d) / 10 ** d;

/** GYV = T − (G+NR+NJ+O); PvS = G − floor(T × SY); UMV = Ckg − ((G+NR+NJ+O) × NW + S + D). Never labelled loss. */
export function varianceIndicators(x: IndicatorInputs): IndicatorResult {
  const accounted = x.G + x.NR + x.NJ + x.O;
  const gyvCalc = x.T !== null && x.cpValidated;
  const gyv = gyvCalc ? x.T! - accounted : null;
  const gyvFinal = gyvCalc && x.measured && x.reportingClosed;
  const pvsCalc = gyvCalc && x.SY !== null;
  const E = pvsCalc ? Math.floor(x.T! * x.SY!) : null;
  const pvs = pvsCalc ? x.G - E! : null;
  const umvCalc = x.NW !== null && x.Ckg !== null && x.S !== null;
  const umv = umvCalc ? x.Ckg! - (accounted * x.NW! + x.S! + (x.D ?? 0)) : null;
  return {
    gyv,
    gyvPct: gyv !== null && x.T ? round((gyv / x.T) * 100, 2) : null,
    gyvStatus: !gyvCalc ? 'NOT_CALCULABLE' : gyvFinal ? 'FINAL' : 'PROVISIONAL',
    pvs,
    pvsPct: pvs !== null && E ? round((pvs / E) * 100, 2) : null,
    pvsStatus: !pvsCalc ? 'NOT_CALCULABLE' : gyvFinal ? 'FINAL' : 'PROVISIONAL',
    umvKg: umv !== null ? round(umv, 3) : null,
    umvPct: umv !== null && x.Ckg ? round((umv / x.Ckg) * 100, 2) : null,
    umvStatus: !umvCalc ? 'NOT_CALCULABLE' : x.measured && x.reportingClosed ? 'FINAL' : 'PROVISIONAL',
  };
}

/* ------------------------------------------------------------------ demand (BR-DEM-08/10/13/14) ------------------------------------------------------------------ */

export function outstanding(required: number, delivered: number) {
  return { outstanding: Math.max(0, required - delivered), overDelivered: Math.max(0, delivered - required) };
}

/** BR-DEM-14. Released counts confirmed releases only; delivered never counted as remaining demand. */
export function blanketSummary(blanketQty: number, releases: { required: number; delivered: number; confirmed: boolean }[]) {
  const confirmed = releases.filter((r) => r.confirmed);
  const releasedQty = confirmed.reduce((a, r) => a + r.required, 0);
  const deliveredQty = confirmed.reduce((a, r) => a + r.delivered, 0);
  const remainingScheduledQty = confirmed.reduce((a, r) => a + outstanding(r.required, r.delivered).outstanding, 0);
  return { blanketQty, releasedQty, deliveredQty, remainingScheduledQty, unscheduledBalance: blanketQty - releasedQty };
}

/** BR-DEM-14 guard: a new release may not exceed the unscheduled blanket balance without an approved revision. */
export function canRelease(qty: number, unscheduledBalance: number): { ok: true } | { ok: false; message: string } {
  return qty <= unscheduledBalance ? { ok: true } : { ok: false, message: `exceeds unscheduled blanket balance ${unscheduledBalance.toLocaleString('en-US')}` };
}

/** BR-DEM-13 / D-041: weekly bucket from the plant calendar (week start configurable; default ISO Monday). Dates are plant-local ISO dates. */
export function bucketOf(isoDate: string, weekStartsOn: 1 | 0 = 1): { bucket: string; bucketStart: string } {
  const d = new Date(`${isoDate}T00:00:00Z`);
  const dow = d.getUTCDay();
  const offset = (dow - weekStartsOn + 7) % 7;
  const start = new Date(d.getTime() - offset * 86_400_000);
  // ISO week number of the bucket start (for Monday weeks).
  const thursday = new Date(start.getTime() + ((4 - (start.getUTCDay() || 7) + 7) % 7) * 86_400_000);
  const yearStart = new Date(Date.UTC(thursday.getUTCFullYear(), 0, 1));
  const week = Math.ceil(((thursday.getTime() - yearStart.getTime()) / 86_400_000 + 1) / 7);
  return { bucket: `${thursday.getUTCFullYear()}-W${String(week).padStart(2, '0')}`, bucketStart: start.toISOString().slice(0, 10) };
}

export type ForecastLine = { customer: string | null; part: string; plant: string; bucket: string; qty: number };
export type FirmLine = { customer: string; part: string; plant: string; bucket: string; required: number; outstanding: number; status: string };

/**
 * BR-DEM-08 / D-040: consumption within the same bucket by customer + part + plant only. A forecast without customer
 * cannot be matched: it stays unconsumed and the bucket is flagged NEEDS_REVIEW (FORECAST_MATCH_UNCERTAIN).
 */
export function weeklyDemand(firm: FirmLine[], forecasts: ForecastLine[]) {
  const active = firm.filter((f) => !['DRAFT', 'SUPERSEDED', 'CANCELLED'].includes(f.status));
  const key = (c: string | null, p: string, pl: string, b: string) => `${c ?? '∅'}|${p}|${pl}|${b}`;
  const groups = new Map<
    string,
    { customer: string | null; part: string; plant: string; bucket: string; firmRequired: number; firmOutstanding: number; forecast: number }
  >();
  const get = (c: string | null, p: string, pl: string, b: string) => {
    const k = key(c, p, pl, b);
    if (!groups.has(k)) groups.set(k, { customer: c, part: p, plant: pl, bucket: b, firmRequired: 0, firmOutstanding: 0, forecast: 0 });
    return groups.get(k)!;
  };
  for (const f of active) {
    const g = get(f.customer, f.part, f.plant, f.bucket);
    g.firmRequired += f.required;
    if (f.status === 'FIRM' || f.status === 'PARTIALLY_FULFILLED') g.firmOutstanding += f.outstanding;
  }
  for (const fc of forecasts) get(fc.customer, fc.part, fc.plant, fc.bucket).forecast += fc.qty;
  return [...groups.values()].map((g) => {
    const uncertain = g.customer === null && g.forecast > 0;
    const netForecast = uncertain ? g.forecast : Math.max(0, g.forecast - g.firmRequired);
    return {
      ...g,
      netForecast,
      // Uncertain forecast is not netted (stays visible, flagged for review) and is not added to planning demand.
      outstandingDemand: uncertain ? 0 : g.firmOutstanding + netForecast,
      matchStatus: uncertain
        ? ('NEEDS_REVIEW' as const)
        : g.forecast === 0
          ? ('NO_FORECAST' as const)
          : g.firmRequired === 0
            ? ('FORECAST_ONLY' as const)
            : ('MATCHED' as const),
    };
  });
}

/**
 * CT-8 projection per demand line (doc 19): projected = FG available + QC released + planned output before due − earlier demand.
 * Pending QC is reported separately (expected-acceptance policy not yet defined, so it is not projected).
 */
export function projectLines(
  lines: { id: string; part: string; dueDate: string; outstanding: number }[],
  supply: Record<string, { fgAvailable: number; qcReleased: number; plannedByDate: { date: string; qty: number }[] }>,
) {
  const byPart = new Map<string, typeof lines>();
  for (const l of [...lines].sort((a, b) => a.dueDate.localeCompare(b.dueDate))) {
    if (!byPart.has(l.part)) byPart.set(l.part, []);
    byPart.get(l.part)!.push(l);
  }
  const out = new Map<string, { earlierDemand: number; plannedOutput: number; projected: number; gap: number }>();
  for (const [part, ls] of byPart) {
    const s = supply[part] ?? { fgAvailable: 0, qcReleased: 0, plannedByDate: [] };
    let earlier = 0;
    for (const l of ls) {
      const planned = s.plannedByDate.filter((p) => p.date <= l.dueDate).reduce((a, p) => a + p.qty, 0);
      const projected = s.fgAvailable + s.qcReleased + planned - earlier;
      out.set(l.id, { earlierDemand: earlier, plannedOutput: planned, projected, gap: Math.max(0, l.outstanding - projected) });
      earlier += l.outstanding;
    }
  }
  return out;
}
