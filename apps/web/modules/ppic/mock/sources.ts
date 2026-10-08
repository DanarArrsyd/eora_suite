/**
 * MOCK Control Tower sources (doc 22 §2). They simulate server QueryDefinitions: filtering, sorting, paging,
 * view counts, latency and failure modes run here, never in the page. Values are SAMPLE data.
 */
import { SourceError, type FilterValues, type ListQuery, type ListResult, type ListSource } from '@eora/features/data';
import { CT_RESOURCES, type ControlTowerSources, type ExceptionRow, type KpiDefinition, type OverviewResult, type OverviewSource } from '../contracts';
import * as W from './world';

export type SimulateMode = 'none' | 'slow' | 'empty' | 'error' | 'forbidden' | 'stale';
export const SIMULATE_MODES: SimulateMode[] = ['none', 'slow', 'empty', 'error', 'forbidden', 'stale'];

const wait = (ms: number, signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    const t = setTimeout(resolve, ms);
    signal?.addEventListener('abort', () => {
      clearTimeout(t);
      reject(new DOMException('Aborted', 'AbortError'));
    });
  });

async function respond(mode: SimulateMode, signal?: AbortSignal) {
  await wait(mode === 'slow' ? 2500 : 250, signal);
  if (mode === 'error') throw new SourceError('The read model did not respond in time.', 'server', 'ct-7c1e');
  if (mode === 'forbidden') throw new SourceError('Missing permission planning.control_tower.view for this widget.', 'forbidden', 'ct-perm');
}
const asOf = (mode: SimulateMode) => new Date(Date.now() - (mode === 'stale' ? 23 : 2) * 60_000).toISOString();

type Accessor<T> = (row: T) => string | number | boolean | null | undefined;
type ListSpec<T> = {
  resource: string;
  rows: () => T[];
  /** Filter key → row value accessor (equality / multi-value inclusion). */
  fields?: Record<string, Accessor<T>>;
  /** Filter key → ISO date accessor for `from..to` ranges. */
  dateFields?: Record<string, (row: T) => string>;
  search?: (row: T) => string;
  views?: Record<string, (row: T) => boolean>;
  sorts?: Record<string, Accessor<T>>;
};

function matches<T>(spec: ListSpec<T>, row: T, filters: FilterValues, search: string): boolean {
  for (const [key, value] of Object.entries(filters)) {
    if (value === undefined || value === '' || (Array.isArray(value) && value.length === 0)) continue;
    const date = spec.dateFields?.[key];
    if (date) {
      const [from = '', to = ''] = String(value).split('..');
      const d = date(row);
      if ((from && d < from) || (to && d > to)) return false;
      continue;
    }
    const acc = spec.fields?.[key];
    if (!acc) continue; // scope keys the resource does not carry (e.g. plant) are applied server-side upstream
    const v = String(acc(row));
    if (Array.isArray(value) ? !value.includes(v) : v !== value) return false;
  }
  return !search || (spec.search?.(row) ?? '').toLowerCase().includes(search.toLowerCase());
}

export function createMockListSource<T>(spec: ListSpec<T>, mode: SimulateMode): ListSource<T> {
  return {
    resource: spec.resource,
    isMock: true,
    async list(q: ListQuery, signal?: AbortSignal): Promise<ListResult<T>> {
      await respond(mode, signal);
      const viewFn = spec.views?.[q.view] ?? (() => true);
      let rows = mode === 'empty' ? [] : spec.rows().filter((r) => matches(spec, r, q.filters, q.search) && viewFn(r));
      const rule = q.sort[0];
      const acc = rule ? spec.sorts?.[rule.id] : undefined;
      if (rule && acc) {
        rows = [...rows].sort((a, b) => {
          const x = acc(a) ?? '';
          const y = acc(b) ?? '';
          return (x > y ? 1 : x < y ? -1 : 0) * (rule.desc ? -1 : 1);
        });
      }
      const start = (q.page - 1) * q.pageSize;
      return { rows: rows.slice(start, start + q.pageSize), meta: { total: rows.length, asOf: asOf(mode), timezone: 'Asia/Jakarta' } };
    },
    async viewCounts(q, signal) {
      await wait(150, signal);
      if (mode === 'error' || mode === 'forbidden') return {};
      const base = mode === 'empty' ? [] : spec.rows().filter((r) => matches(spec, r, q.filters, q.search));
      return Object.fromEntries(Object.entries(spec.views ?? { all: () => true }).map(([k, fn]) => [k, base.filter(fn).length]));
    },
  };
}

const plus = (iso: string, days: number) => new Date(Date.parse(`${iso}T00:00:00Z`) + days * 86_400_000).toISOString().slice(0, 10);
const NEXT7 = plus(W.BUSINESS_DATE, 7);
const all = () => true;

export function createControlTowerSources(mode: SimulateMode = 'none'): ControlTowerSources {
  const m = <T>(spec: ListSpec<T>) => createMockListSource(spec, mode);
  return {
    overview: createOverviewSource(mode),
    demandLines: m({
      resource: CT_RESOURCES.demandLines,
      rows: () => W.DEMAND_LINES,
      fields: { customer: (r) => r.customer, part: (r) => r.part, bucket: (r) => r.bucket, scheduleType: (r) => r.scheduleType },
      dateFields: { due: (r) => r.requirementDate },
      search: (r) => `${r.scheduleRef} ${r.soNo} ${r.cpoNo} ${r.part}`,
      views: {
        all,
        open: (r) => r.outstanding > 0,
        due7: (r) => r.outstanding > 0 && r.requirementDate <= NEXT7,
        partial: (r) => r.status === 'PARTIALLY_FULFILLED',
        blanket: (r) => r.blanket,
        history: (r) => r.status === 'SUPERSEDED' || r.status === 'FULFILLED',
        exceptions: (r) => !!r.exception,
      },
      sorts: { requirementDate: (r) => r.requirementDate, outstanding: (r) => r.outstanding, part: (r) => r.part },
    }),
    weeklyDemand: m({
      resource: CT_RESOURCES.weeklyDemand,
      rows: () => W.WEEKLY_DEMAND,
      fields: { customer: (r) => r.customer ?? '', part: (r) => r.part, bucket: (r) => r.bucket },
      views: { all },
      sorts: { bucket: (r) => r.bucket },
    }),
    blanketOrders: m({
      resource: CT_RESOURCES.blanketOrders,
      rows: () => W.BLANKET_ORDERS,
      fields: { customer: (r) => r.customer, part: (r) => r.part },
      views: { all },
      sorts: { soNo: (r) => r.soNo },
    }),
    dailyDelivery: m({
      resource: CT_RESOURCES.dailyDelivery,
      rows: () => W.DAILY_DELIVERY,
      fields: { customer: (r) => r.customer, part: (r) => r.part },
      dateFields: { due: (r) => r.dueDate },
      search: (r) => `${r.scheduleRef} ${r.soNo} ${r.part}`,
      views: { all, outstanding: (r) => r.outstanding > 0, partial: (r) => r.partial, risk: (r) => r.risk, shipped: (r) => r.outstanding === 0 },
      sorts: { dueDate: (r) => r.dueDate, outstanding: (r) => r.outstanding, gap: (r) => r.gap },
    }),
    deliveryRisk: m({
      resource: CT_RESOURCES.deliveryRisk,
      rows: () => W.DELIVERY_RISK,
      fields: { customer: (r) => r.customer, part: (r) => r.part, reason: (r) => r.reasons.join(' ') },
      dateFields: { due: (r) => r.dueDate },
      search: (r) => `${r.scheduleRef} ${r.part}`,
      views: { all, atRisk: (r) => r.atRisk, next7: (r) => r.atRisk && r.dueDate <= NEXT7, covered: (r) => !r.atRisk },
      sorts: { dueDate: (r) => r.dueDate, gap: (r) => r.gap },
    }),
    rmLots: m({
      resource: CT_RESOURCES.rmLots,
      rows: () => W.RM_LOTS,
      fields: { form: (r) => r.form, grade: (r) => r.grade, warehouse: (r) => r.warehouse, conversionStatus: (r) => r.conversionStatus },
      search: (r) => `${r.lot} ${r.item} ${r.location}`,
      views: {
        all,
        unrestricted: (r) => r.status === 'UNRESTRICTED',
        allocated: (r) => r.allocated > 0,
        hold: (r) => r.status === 'HOLD',
        blocked: (r) => r.status === 'BLOCKED',
        notCalculable: (r) => r.conversionStatus === 'NOT_CALCULABLE',
      },
      sorts: { lot: (r) => r.lot, available: (r) => r.available, onHand: (r) => r.onHand },
    }),
    conversion: m({
      resource: CT_RESOURCES.conversion,
      rows: () => W.CONVERSIONS,
      fields: { form: (r) => r.form, part: (r) => r.part, status: (r) => r.status },
      search: (r) => `${r.lot} ${r.part}`,
      views: {
        all,
        calculated: (r) => r.status === 'CALCULATED',
        provisional: (r) => r.status === 'PROVISIONAL',
        notCalculable: (r) => r.status === 'NOT_CALCULABLE',
      },
      sorts: { lot: (r) => r.lot, estPcsUpper: (r) => r.estPcsUpper ?? -1 },
    }),
    materialAtProduction: m({
      resource: CT_RESOURCES.materialAtProduction,
      rows: () => W.MATERIAL_AT_PRODUCTION,
      fields: { workCenter: (r) => r.workCenter, custody: (r) => r.custody },
      search: (r) => `${r.lot} ${r.item} ${r.woAllocations.join(' ')}`,
      views: {
        all,
        idle: (r) => r.idleHours > 48,
        remnant: (r) => r.isRemnant,
        inTransit: (r) => r.custody === 'IN_TRANSIT',
        unmeasured: (r) => r.lastMeasurement.method === 'NONE',
      },
      sorts: { lot: (r) => r.lot, idleHours: (r) => r.idleHours, qty: (r) => r.qty },
    }),
    woProgress: m({
      resource: CT_RESOURCES.woProgress,
      rows: () => W.WO_PROGRESS,
      fields: { part: (r) => r.part, status: (r) => r.status },
      search: (r) => `${r.wo} ${r.part}`,
      views: {
        all,
        active: (r) => r.status !== 'CLOSED',
        materialShort: (r) => r.materialShort,
        unmeasured: (r) => r.good > 0 && !r.consumptionMeasured,
        closed: (r) => r.status === 'CLOSED',
      },
      sorts: { wo: (r) => r.wo, dueDate: (r) => r.dueDate },
    }),
    dailyProduction: m({
      resource: CT_RESOURCES.dailyProduction,
      rows: () => W.DAILY_PRODUCTION,
      fields: { part: (r) => r.part, shift: (r) => r.shift, workCenter: (r) => r.workCenter },
      dateFields: { date: (r) => r.productionDate },
      search: (r) => `${r.wo} ${r.run} ${r.part}`,
      views: { all, unmeasured: (r) => r.method === 'UNMEASURED_BY_DIFFERENCE' },
      sorts: { productionDate: (r) => `${r.productionDate}${r.shift}`, good: (r) => r.good },
    }),
    pendingQc: m({
      resource: CT_RESOURCES.pendingQc,
      rows: () => W.PENDING_QC,
      fields: { part: (r) => r.part, agingBucket: (r) => r.agingBucket },
      search: (r) => `${r.inspectionLot} ${r.outputLot} ${r.wo} ${r.part}`,
      views: {
        all,
        pending: (r) => r.pendingQc > 0,
        overSla: (r) => r.pendingQc > 0 && r.ageHours > 24,
        hold: (r) => r.hold > 0,
        releasedNotReceived: (r) => r.releasedNotReceived > 0,
      },
      sorts: { ageHours: (r) => r.ageHours, pendingQc: (r) => r.pendingQc },
    }),
    dailyFgReceipt: m({
      resource: CT_RESOURCES.dailyFgReceipt,
      rows: () => W.DAILY_FG_RECEIPT,
      fields: { part: (r) => r.part, shift: (r) => r.shift },
      dateFields: { date: (r) => r.date },
      views: { all, belowPlan: (r) => r.receivedVsPlan < 0, releasedNotReceived: (r) => r.releasedNotReceived > 0 },
      sorts: { date: (r) => `${r.date}${r.shift}`, fgReceived: (r) => r.fgReceived },
    }),
    fgAvailability: m({
      resource: CT_RESOURCES.fgAvailability,
      rows: () => W.FG_AVAILABILITY,
      fields: { part: (r) => r.part },
      search: (r) => `${r.part} ${r.lot} ${r.location}`,
      views: { all, available: (r) => r.available > 0, blocked: (r) => r.blocked > 0, incoming: (r) => r.incomingFromQc > 0, atRisk: (r) => r.atRiskDemand },
      sorts: { part: (r) => r.part, available: (r) => r.available },
    }),
    materialVariance: m({
      resource: CT_RESOURCES.materialVariance,
      rows: () => W.MATERIAL_VARIANCE,
      fields: { part: (r) => r.part, gyvStatus: (r) => r.gyvStatus, caseStatus: (r) => r.case?.status ?? 'NONE' },
      search: (r) => `${r.run} ${r.wo} ${r.inputLot} ${r.part} ${r.case?.id ?? ''}`,
      views: {
        all,
        breach: (r) => r.breach,
        provisional: (r) => r.gyvStatus === 'PROVISIONAL' || r.umvStatus === 'PROVISIONAL',
        notCalculable: (r) => r.gyvStatus === 'NOT_CALCULABLE',
        openCase: (r) => !!r.case && r.case.status !== 'CLOSED',
      },
      sorts: { run: (r) => r.run, gyv: (r) => r.gyv ?? -Infinity },
    }),
  };
}

/* ------------------------------------------------------------------ overview (CT-0) ------------------------------------------------------------------ */

function createOverviewSource(mode: SimulateMode): OverviewSource {
  return {
    resource: CT_RESOURCES.overview,
    isMock: true,
    async get(filters, signal) {
      await respond(mode, signal);
      return buildOverview(filters, mode);
    },
  };
}

const sum = <T>(xs: T[], f: (x: T) => number) => xs.reduce((a, x) => a + f(x), 0);

export function buildOverview(filters: { customer?: string; part?: string }, mode: SimulateMode = 'none'): OverviewResult {
  const byPart = (p: string) => !filters.part || p === filters.part;
  const byCust = (c: string) => !filters.customer || c === filters.customer;
  const empty = mode === 'empty';
  const risk = empty ? [] : W.DELIVERY_RISK.filter((r) => byPart(r.part) && byCust(r.customer));
  const risk7 = risk.filter((r) => r.atRisk && r.dueDate <= NEXT7);
  const shortWo = empty ? [] : W.WO_PROGRESS.filter((w) => w.materialShort && w.status !== 'CLOSED' && byPart(w.part));
  const qcLate = empty ? [] : W.PENDING_QC.filter((q) => q.pendingQc > 0 && q.ageHours > 24 && byPart(q.part));
  const cases = empty ? [] : W.MATERIAL_VARIANCE.filter((v) => v.case && v.case.status !== 'CLOSED' && byPart(v.part));
  const finalBreach = cases.filter((c) => c.breach).length;

  const kpis: KpiDefinition[] = [
    {
      id: 'delivery-risk',
      label: 'Demand lines at delivery risk · next 7 days',
      value: risk7.length,
      uom: 'lines',
      secondary: { value: sum(risk7, (r) => r.gap), uom: 'pcs' },
      definition: 'Schedule lines due within 7 days whose projected FG supply is below outstanding quantity.',
      sourceDomain: 'Demand · Inventory · Planning (CT-8)',
      basis: 'Projected = FG available + QC released + planned output before due − earlier demand. Pending QC is not projected.',
      meaning: 'Pieces still uncovered (gap). Not a material shortage and not a delivery failure yet.',
      href: '/ppic/shortage?view=next7',
      tone: risk7.length ? 'danger' : 'neutral',
    },
    {
      id: 'material-short',
      label: 'Released WOs short of material',
      value: shortWo.length,
      uom: 'WOs',
      definition: 'Released or in-progress work orders where allocated + available raw material < WO requirement.',
      sourceDomain: 'Planning · Materials (CT-1)',
      basis: 'Compared in material UOM (kg or sheet), never in estimated pieces.',
      meaning: 'Material must be allocated, received or substituted before the WO can finish.',
      href: '/ppic/wo-progress?view=materialShort',
      tone: shortWo.length ? 'warning' : 'neutral',
    },
    {
      id: 'qc-aging',
      label: 'Pending QC over 24 h SLA',
      value: qcLate.length,
      uom: 'lots',
      secondary: { value: sum(qcLate, (q) => q.pendingQc), uom: 'pcs' },
      definition: 'Inspection lots with PENDING_QC quantity older than the tenant SLA (default 24 h).',
      sourceDomain: 'Quality (CT-3)',
      basis: 'Age from output posting to now; partial dispositions excluded from the pending quantity.',
      meaning: 'Output that exists but is not FG available until QC releases and FG receipt is posted.',
      href: '/ppic/pending-quality?view=overSla',
      tone: qcLate.length ? 'warning' : 'neutral',
    },
    {
      id: 'variance-cases',
      label: 'Open material variance cases',
      value: cases.length,
      uom: 'cases',
      definition: `Investigation cases not closed: ${finalBreach} from FINAL tolerance breach, ${cases.length - finalBreach} on provisional or unmeasured results.`,
      sourceDomain: 'Reconciliation (CT-6)',
      basis: 'Opened per ADR-0005 tolerance on FINAL values or unmeasured consumption.',
      meaning: 'Unexplained variance under investigation — not confirmed material loss.',
      href: '/ppic/material-variance?view=openCase',
      tone: cases.length ? 'warning' : 'neutral',
    },
  ];

  const lots = empty ? [] : W.RM_LOTS;
  const mat = empty ? [] : W.MATERIAL_AT_PRODUCTION;
  const qc = empty ? [] : W.PENDING_QC.filter((q) => byPart(q.part));
  const fg = empty ? [] : W.FG_AVAILABILITY.filter((f) => byPart(f.part));
  const shipped = empty
    ? []
    : W.DEMAND_LINES.filter((l) => byPart(l.part) && byCust(l.customer)).flatMap((l) => l.shipments.filter((s) => !s.reversed && s.date >= '2026-10-05'));
  const unres = lots.filter((l) => l.status === 'UNRESTRICTED');

  const flow = {
    material: [
      {
        id: 'rm',
        label: 'RM unrestricted',
        href: '/ppic/rm-availability?view=unrestricted',
        quantities: [
          {
            value: sum(
              unres.filter((l) => l.uom === 'kg'),
              (l) => l.onHand,
            ),
            uom: 'kg' as const,
          },
          {
            value: sum(
              unres.filter((l) => l.uom === 'sheet'),
              (l) => l.onHand,
            ),
            uom: 'sheet' as const,
          },
        ],
      },
      {
        id: 'atprod',
        label: 'Material at Production',
        href: '/ppic/material-at-production',
        quantities: [
          {
            value: sum(
              mat.filter((l) => l.uom === 'kg'),
              (l) => l.qty,
            ),
            uom: 'kg' as const,
          },
          {
            value: sum(
              mat.filter((l) => l.uom === 'sheet'),
              (l) => l.qty,
            ),
            uom: 'sheet' as const,
          },
        ],
      },
    ],
    output: [
      {
        id: 'pending',
        label: 'Pending QC',
        href: '/ppic/pending-quality?view=pending',
        quantities: [{ value: sum(qc, (q) => q.pendingQc), uom: 'pcs' as const }],
      },
      {
        id: 'released',
        label: 'QC released · not received',
        href: '/ppic/pending-quality?view=releasedNotReceived',
        quantities: [{ value: sum(qc, (q) => q.releasedNotReceived), uom: 'pcs' as const }],
        note: 'Not FG available until FG receipt',
      },
      {
        id: 'fg',
        label: 'FG available',
        href: '/ppic/fg-availability?view=available',
        quantities: [{ value: sum(fg, (f) => f.available), uom: 'pcs' as const }],
      },
      {
        id: 'shipped',
        label: 'Shipped · this week',
        href: '/ppic/daily-delivery?view=shipped',
        quantities: [{ value: sum(shipped, (s) => s.qty), uom: 'pcs' as const }],
      },
    ],
  };

  const ex: ExceptionRow[] = [];
  for (const r of risk.filter((x) => x.atRisk))
    ex.push({
      id: `x-${r.id}`,
      type: 'DELIVERY_AT_RISK',
      title: `${r.part} · ${r.scheduleRef} (${r.customer})`,
      detail: `Gap ${r.gap.toLocaleString('en-US')} pcs · ${r.reasons.join(', ') || 'supply timing'}`,
      part: r.part,
      dueDate: r.dueDate,
      qty: r.gap,
      uom: 'pcs',
      href: `/ppic/shortage?f.part=${r.part}`,
      impactRank: 0,
    });
  for (const w of shortWo)
    ex.push({
      id: `x-${w.id}`,
      type: 'MATERIAL_SHORTAGE',
      title: `${w.wo} · ${w.part} short of material`,
      detail: 'Allocated + available RM below requirement (material UOM)',
      part: w.part,
      dueDate: w.dueDate,
      qty: null,
      uom: null,
      href: '/ppic/wo-progress?view=materialShort',
      impactRank: 0,
    });
  if (!empty) {
    for (const l of W.RM_LOTS.filter((x) => x.conversionStatus === 'NOT_CALCULABLE' && byPart(x.primaryPart)).slice(0, 2))
      ex.push({
        id: `x-${l.id}`,
        type: 'CONVERSION_NOT_CALCULABLE',
        title: `Lot ${l.lot} · conversion not calculable`,
        detail: `Missing: ${l.missingInputs.join(', ')}`,
        part: l.primaryPart,
        dueDate: null,
        qty: l.onHand,
        uom: l.uom,
        href: '/ppic/conversion?view=notCalculable',
        impactRank: 0,
      });
    for (const l of W.DEMAND_LINES.filter((x) => x.exception && byPart(x.part) && byCust(x.customer)))
      ex.push({
        id: `x-${l.id}`,
        type: l.exception!,
        title: `${l.scheduleRef} · revision mapping needs confirmation`,
        detail: `${l.soNo} rev ${l.revision}: outstanding not re-allocated automatically (D-038)`,
        part: l.part,
        dueDate: l.requirementDate,
        qty: l.outstanding,
        uom: 'pcs',
        href: '/ppic/demand-fulfillment?view=exceptions',
        impactRank: 0,
      });
    for (const w of W.WEEKLY_DEMAND.filter((x) => x.matchStatus === 'NEEDS_REVIEW' && byPart(x.part)))
      ex.push({
        id: `x-${w.id}`,
        type: 'FORECAST_MATCH_UNCERTAIN',
        title: `${w.part} · forecast ${w.bucket} without customer`,
        detail: 'Not consumed or netted until PPIC confirms the match (D-040)',
        part: w.part,
        dueDate: w.bucketStart,
        qty: w.forecast,
        uom: 'pcs',
        href: `/ppic/demand-fulfillment?f.part=${w.part}`,
        impactRank: 0,
      });
    for (const m2 of W.MATERIAL_AT_PRODUCTION.filter((x) => x.idleHours > 48))
      ex.push({
        id: `x-${m2.id}`,
        type: 'MATERIAL_IDLE',
        title: `${m2.lot} idle at ${m2.workCenter}`,
        detail: `${m2.idleHours} h without movement`,
        part: null,
        dueDate: null,
        qty: m2.qty,
        uom: m2.uom,
        href: '/ppic/material-at-production?view=idle',
        impactRank: 0,
      });
  }
  for (const q of qcLate)
    ex.push({
      id: `x-${q.id}`,
      type: 'PENDING_QC_AGING',
      title: `${q.inspectionLot} · ${q.part} pending ${q.ageHours} h`,
      detail: `${q.pendingQc} pcs waiting for disposition`,
      part: q.part,
      dueDate: null,
      qty: q.pendingQc,
      uom: 'pcs',
      href: '/ppic/pending-quality?view=overSla',
      impactRank: 0,
    });
  for (const c of cases)
    ex.push({
      id: `x-${c.id}`,
      type: 'VARIANCE_CASE',
      title: `${c.case!.id} · ${c.run} (${c.part})`,
      detail: c.breach ? 'FINAL tolerance breach — investigation, not confirmed loss' : 'Provisional or unmeasured — investigation open',
      part: c.part,
      dueDate: null,
      qty: null,
      uom: null,
      href: '/ppic/material-variance?view=openCase',
      impactRank: 0,
    });
  // Impact: due-date proximity first, then type order of doc 19.
  const order = [
    'DELIVERY_AT_RISK',
    'MATERIAL_SHORTAGE',
    'CONVERSION_NOT_CALCULABLE',
    'REVISION_MAPPING_AMBIGUOUS',
    'FORECAST_MATCH_UNCERTAIN',
    'PENDING_QC_AGING',
    'VARIANCE_CASE',
    'MATERIAL_IDLE',
  ];
  ex.sort((a, b) => (a.dueDate ?? '9999').localeCompare(b.dueDate ?? '9999') || order.indexOf(a.type) - order.indexOf(b.type));
  ex.forEach((e, i) => (e.impactRank = i + 1));

  const parts = [...new Set(risk.map((r) => r.part))].sort();
  const dueVsSupply = parts.map((part) => {
    const s = W.SUPPLY[part]!;
    const lines = risk.filter((r) => r.part === part);
    const points = Array.from({ length: 10 }, (_, i) => {
      const date = plus(W.BUSINESS_DATE, i + 1);
      const due = sum(
        lines.filter((l) => l.dueDate === date),
        (l) => l.outstanding,
      );
      const planned = sum(
        s.plannedByDate.filter((p) => p.date <= date),
        (p) => p.qty,
      );
      const earlier = sum(
        lines.filter((l) => l.dueDate < date),
        (l) => l.outstanding,
      );
      return { date, due, projected: s.fgAvailable + s.qcReleased + planned - earlier };
    });
    return { part, points };
  });

  return { businessDate: W.BUSINESS_DATE, kpis, flow, exceptions: ex.slice(0, 10), dueVsSupply, meta: { asOf: asOf(mode), timezone: 'Asia/Jakarta' } };
}
