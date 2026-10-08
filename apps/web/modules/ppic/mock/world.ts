/**
 * SAMPLE scenario for the Control Tower mock adapters (doc 22 §2). Deterministic, plant "PA", business date 2026-10-09.
 * Contains the worked examples of doc 02 / doc 14 so the screens can be checked against the specification.
 * Never operational data; never exported or persisted.
 */
import type {
  BlanketOrderRow,
  ConversionInputs,
  ConversionRow,
  DailyDeliveryRow,
  DailyFgReceiptRow,
  DailyProductionRow,
  DeliveryRiskRow,
  DemandLineRow,
  FgAvailabilityRow,
  MaterialAtProductionRow,
  MaterialVarianceRow,
  PendingQcRow,
  RiskReason,
  RmLotRow,
  ScheduleStatus,
  WeeklyDemandRow,
  WoProgressRow,
} from '../contracts';
import { blanketSummary, bucketOf, coilEstimate, outstanding, projectLines, sheetEstimate, varianceIndicators, weeklyDemand, type ForecastLine } from './calc';

export const BUSINESS_DATE = '2026-10-09';
export const PLANT = 'PA';

export const CUSTOMERS: Record<string, string> = {
  'CUST-01': 'CUST-01 · Sample Automotive A',
  'CUST-02': 'CUST-02 · Sample Motorcycle B',
  'CUST-03': 'CUST-03 · Sample Electronics C',
};

type PartMaster = {
  name: string;
  material: string;
  form: 'COIL' | 'SHEET';
  inputs: ConversionInputs;
  cpVersion: string | null;
  cpValidated: boolean;
  bomRef: string;
  nw: number;
};
export const PARTS: Record<string, PartMaster> = {
  'PART-A': {
    name: 'Front bracket',
    material: 'SPCC-12-120',
    form: 'COIL',
    cpVersion: 'CP-A v3',
    cpValidated: true,
    bomRef: 'BOM-A v2',
    nw: 0.29,
    inputs: {
      basis: 'COIL_PITCH',
      densityGcm3: 7.85,
      thicknessMm: 1.2,
      widthMm: 120,
      pitch: 320,
      pitchUnit: 'mm',
      cavity: 1,
      headTailMm: 1500,
      standardYield: 0.97,
    },
  },
  'PART-F': {
    name: 'Clamp',
    material: 'SPCC-12-120',
    form: 'COIL',
    cpVersion: 'CP-F v1',
    cpValidated: false,
    bomRef: 'BOM-F v1',
    nw: 0.14,
    inputs: {
      basis: 'COIL_PITCH',
      densityGcm3: 7.85,
      thicknessMm: 1.2,
      widthMm: 120,
      pitch: 160,
      pitchUnit: 'mm',
      cavity: 1,
      headTailMm: 1500,
      standardYield: 0.96,
    },
  },
  'PART-B': {
    name: 'Reinforcement plate',
    material: 'SPCC-16-150',
    form: 'COIL',
    cpVersion: 'CP-B v2',
    cpValidated: true,
    bomRef: 'BOM-B v4',
    nw: 0.21,
    inputs: {
      basis: 'COIL_PITCH',
      densityGcm3: 7.85,
      thicknessMm: 1.6,
      widthMm: 150,
      pitch: 240,
      pitchUnit: 'mm',
      cavity: 2,
      headTailMm: 1800,
      standardYield: 0.95,
    },
  },
  'PART-C': {
    name: 'Mounting plate',
    material: 'SPHC-20-SHT',
    form: 'SHEET',
    cpVersion: 'CP-C v1',
    cpValidated: true,
    bomRef: 'BOM-C v1',
    nw: 0.38,
    inputs: { basis: 'SHEET_LAYOUT', blanksPerSheet: 24, partsPerBlank: 2, standardYield: 0.97 },
  },
  'PART-D': {
    name: 'Side bracket',
    material: 'SPHC-20-SHT',
    form: 'SHEET',
    cpVersion: 'CP-D v2',
    cpValidated: true,
    bomRef: 'BOM-D v3',
    nw: 1.1,
    inputs: { basis: 'SHEET_LAYOUT', blanksPerSheet: 10, partsPerBlank: 1, standardYield: 0.96 },
  },
  'PART-E': {
    name: 'Hinge base',
    material: 'SGCC-10-100',
    form: 'COIL',
    cpVersion: null,
    cpValidated: false,
    bomRef: 'BOM-E v1',
    nw: 0.12,
    inputs: {
      basis: 'COIL_PITCH',
      densityGcm3: 7.85,
      thicknessMm: 1.0,
      widthMm: 100,
      pitch: 0.32,
      pitchUnit: null,
      cavity: 1,
      headTailMm: 1500,
      standardYield: 0.95,
    },
  },
};
const PARTS_BY_MATERIAL: Record<string, string[]> = {
  'SPCC-12-120': ['PART-A', 'PART-F'],
  'SPCC-16-150': ['PART-B'],
  'SPHC-20-SHT': ['PART-C', 'PART-D'],
  'SGCC-10-100': ['PART-E'],
};

function rng(seed: number) {
  let s = seed;
  return () => {
    s = (s * 1103515245 + 12345) % 2147483648;
    return s / 2147483648;
  };
}
const r = rng(7);
const pick = <T>(xs: readonly T[]) => xs[Math.floor(r() * xs.length)]!;
const addDays = (iso: string, n: number) => new Date(Date.parse(`${iso}T00:00:00Z`) + n * 86_400_000).toISOString().slice(0, 10);
const k3 = (v: number) => Math.round(v * 1000) / 1000;

/* ------------------------------------------------------------------ demand ------------------------------------------------------------------ */

type LineSeed = Omit<DemandLineRow, 'id' | 'plant' | 'bucket' | 'outstanding' | 'status' | 'shipments' | 'delivered'> & {
  shipped?: { ref: string; date: string; qty: number; reversed?: boolean }[];
  superseded?: boolean;
  draft?: boolean;
};

const LINE_SEEDS: LineSeed[] = [
  // doc 14 #28–#36: CPO-1001/10 → SO-2001/1, four weekly lines; DSL-3 revised 250 → 300 (rev 2)
  {
    scheduleRef: 'DSL-1',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-01',
    part: 'PART-A',
    soNo: 'SO-2001',
    soLine: 1,
    cpoNo: 'CPO-1001',
    cpoLine: 10,
    blanket: false,
    requirementDate: '2026-10-06',
    required: 250,
    allocated: 0,
    shipped: [{ ref: 'SHP-1', date: '2026-10-06', qty: 100 }],
  },
  {
    scheduleRef: 'DSL-2',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-01',
    part: 'PART-A',
    soNo: 'SO-2001',
    soLine: 1,
    cpoNo: 'CPO-1001',
    cpoLine: 10,
    blanket: false,
    requirementDate: '2026-10-13',
    required: 250,
    allocated: 120,
  },
  {
    scheduleRef: 'DSL-3',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-01',
    part: 'PART-A',
    soNo: 'SO-2001',
    soLine: 1,
    cpoNo: 'CPO-1001',
    cpoLine: 10,
    blanket: false,
    requirementDate: '2026-10-20',
    required: 250,
    allocated: 0,
    superseded: true,
  },
  {
    scheduleRef: 'DSL-3′',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 2,
    customer: 'CUST-01',
    part: 'PART-A',
    soNo: 'SO-2001',
    soLine: 1,
    cpoNo: 'CPO-1001',
    cpoLine: 10,
    blanket: false,
    requirementDate: '2026-10-20',
    required: 300,
    allocated: 0,
  },
  {
    scheduleRef: 'DSL-4',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-01',
    part: 'PART-A',
    soNo: 'SO-2001',
    soLine: 1,
    cpoNo: 'CPO-1001',
    cpoLine: 10,
    blanket: false,
    requirementDate: '2026-10-27',
    required: 250,
    allocated: 0,
  },
  // doc 14 #43: blanket SO-2002/1 10,000 — KB-77 500 delivered, KB-78 800 with 300 delivered
  {
    scheduleRef: 'KB-77',
    scheduleType: 'KANBAN_RELEASE',
    revision: 1,
    customer: 'CUST-02',
    part: 'PART-B',
    soNo: 'SO-2002',
    soLine: 1,
    cpoNo: 'CPO-1105',
    cpoLine: 1,
    blanket: true,
    requirementDate: '2026-10-07',
    required: 500,
    allocated: 0,
    shipped: [{ ref: 'SHP-7', date: '2026-10-07', qty: 500 }],
  },
  {
    scheduleRef: 'KB-78',
    scheduleType: 'KANBAN_RELEASE',
    revision: 1,
    customer: 'CUST-02',
    part: 'PART-B',
    soNo: 'SO-2002',
    soLine: 1,
    cpoNo: 'CPO-1105',
    cpoLine: 1,
    blanket: true,
    requirementDate: '2026-10-14',
    required: 800,
    allocated: 260,
    shipped: [{ ref: 'SHP-9', date: '2026-10-08', qty: 300 }],
  },
  // doc 14 #48: daily risk inside a covered week (PART-C)
  {
    scheduleRef: 'DSL-31',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-01',
    part: 'PART-C',
    soNo: 'SO-2003',
    soLine: 1,
    cpoNo: 'CPO-1120',
    cpoLine: 1,
    blanket: false,
    requirementDate: '2026-10-13',
    required: 150,
    allocated: 120,
  },
  {
    scheduleRef: 'DSL-32',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-01',
    part: 'PART-C',
    soNo: 'SO-2003',
    soLine: 1,
    cpoNo: 'CPO-1120',
    cpoLine: 1,
    blanket: false,
    requirementDate: '2026-10-16',
    required: 50,
    allocated: 0,
  },
  {
    scheduleRef: 'DSL-33',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-01',
    part: 'PART-C',
    soNo: 'SO-2003',
    soLine: 1,
    cpoNo: 'CPO-1120',
    cpoLine: 1,
    blanket: false,
    requirementDate: '2026-10-21',
    required: 200,
    allocated: 0,
  },
  // doc 14 #41: ambiguous revision mapping (PART-D)
  {
    scheduleRef: 'DSL-41',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-03',
    part: 'PART-D',
    soNo: 'SO-2004',
    soLine: 1,
    cpoNo: 'CPO-1201',
    cpoLine: 2,
    blanket: false,
    requirementDate: '2026-10-12',
    required: 300,
    allocated: 0,
  },
  {
    scheduleRef: 'DSL-42',
    scheduleType: 'CUSTOMER_SCHEDULE',
    revision: 1,
    customer: 'CUST-03',
    part: 'PART-D',
    soNo: 'SO-2004',
    soLine: 1,
    cpoNo: 'CPO-1201',
    cpoLine: 2,
    blanket: false,
    requirementDate: '2026-10-19',
    required: 300,
    allocated: 0,
    exception: 'REVISION_MAPPING_AMBIGUOUS',
  },
  // blanket kanban SO-2005/1 4,000 (PART-F)
  {
    scheduleRef: 'KB-90',
    scheduleType: 'KANBAN_RELEASE',
    revision: 1,
    customer: 'CUST-02',
    part: 'PART-F',
    soNo: 'SO-2005',
    soLine: 1,
    cpoNo: 'CPO-1108',
    cpoLine: 1,
    blanket: true,
    requirementDate: '2026-10-15',
    required: 600,
    allocated: 500,
  },
  {
    scheduleRef: 'KB-91',
    scheduleType: 'KANBAN_RELEASE',
    revision: 1,
    customer: 'CUST-02',
    part: 'PART-F',
    soNo: 'SO-2005',
    soLine: 1,
    cpoNo: 'CPO-1108',
    cpoLine: 1,
    blanket: true,
    requirementDate: '2026-10-22',
    required: 600,
    allocated: 0,
  },
  // PART-E: conversion not calculable, no WO
  {
    scheduleRef: 'DSL-61',
    scheduleType: 'MANUAL',
    revision: 1,
    customer: 'CUST-03',
    part: 'PART-E',
    soNo: 'SO-2006',
    soLine: 1,
    cpoNo: 'CPO-1210',
    cpoLine: 1,
    blanket: false,
    requirementDate: '2026-10-14',
    required: 400,
    allocated: 0,
  },
  {
    scheduleRef: 'DSL-62',
    scheduleType: 'MANUAL',
    revision: 1,
    customer: 'CUST-03',
    part: 'PART-E',
    soNo: 'SO-2006',
    soLine: 1,
    cpoNo: 'CPO-1210',
    cpoLine: 1,
    blanket: false,
    requirementDate: '2026-10-28',
    required: 400,
    allocated: 0,
    draft: true,
  },
];

function statusOf(s: LineSeed, delivered: number): ScheduleStatus {
  if (s.superseded) return 'SUPERSEDED';
  if (s.draft) return 'DRAFT';
  if (delivered >= s.required) return 'FULFILLED';
  return delivered > 0 ? 'PARTIALLY_FULFILLED' : 'FIRM';
}

export const DEMAND_LINES: DemandLineRow[] = LINE_SEEDS.map((s, i) => {
  const shipments = (s.shipped ?? []).map((x) => ({ ...x, reversed: !!x.reversed }));
  const delivered = shipments.filter((x) => !x.reversed).reduce((a, x) => a + x.qty, 0);
  const status = statusOf(s, delivered);
  const counts = status === 'FIRM' || status === 'PARTIALLY_FULFILLED';
  const { shipped, superseded, draft, ...rest } = s;
  void shipped;
  void superseded;
  void draft;
  return {
    ...rest,
    id: `dl-${i + 1}`,
    plant: PLANT,
    bucket: bucketOf(s.requirementDate).bucket,
    status,
    delivered,
    outstanding: counts ? outstanding(s.required, delivered).outstanding : 0,
    shipments,
  };
});

export const BLANKET_ORDERS: BlanketOrderRow[] = [
  { so: 'SO-2002', line: 1, customer: 'CUST-02', part: 'PART-B', qty: 10_000, revision: 1 },
  { so: 'SO-2005', line: 1, customer: 'CUST-02', part: 'PART-F', qty: 4_000, revision: 2 },
].map((b, i) => {
  const releases = DEMAND_LINES.filter((l) => l.soNo === b.so && l.blanket).map((l) => ({
    required: l.required,
    delivered: l.delivered,
    confirmed: ['FIRM', 'PARTIALLY_FULFILLED', 'FULFILLED'].includes(l.status),
  }));
  return { id: `bo-${i + 1}`, customer: b.customer, part: b.part, soNo: b.so, soLine: b.line, revision: b.revision, ...blanketSummary(b.qty, releases) };
});

export const FORECASTS: ForecastLine[] = [
  { customer: 'CUST-01', part: 'PART-A', plant: PLANT, bucket: '2026-W41', qty: 300 },
  { customer: 'CUST-01', part: 'PART-A', plant: PLANT, bucket: '2026-W42', qty: 1_200 },
  { customer: 'CUST-02', part: 'PART-B', plant: PLANT, bucket: '2026-W42', qty: 600 },
  { customer: null, part: 'PART-C', plant: PLANT, bucket: '2026-W42', qty: 400 }, // doc 14 #46: no customer → needs review
  { customer: 'CUST-03', part: 'PART-D', plant: PLANT, bucket: '2026-W43', qty: 500 },
];

export const WEEKLY_DEMAND: WeeklyDemandRow[] = weeklyDemand(
  DEMAND_LINES.map((l) => ({
    customer: l.customer,
    part: l.part,
    plant: l.plant,
    bucket: l.bucket,
    required: l.required,
    outstanding: l.outstanding,
    status: l.status,
  })),
  FORECASTS,
)
  .map((g, i) => ({ id: `wd-${i + 1}`, ...g, bucketStart: weekStart(g.bucket) }))
  .sort((a, b) => a.bucket.localeCompare(b.bucket) || a.part.localeCompare(b.part) || (a.customer ?? '').localeCompare(b.customer ?? ''));

function weekStart(bucket: string): string {
  const line = DEMAND_LINES.find((l) => l.bucket === bucket);
  if (line) return bucketOf(line.requirementDate).bucketStart;
  const week = Number(bucket.slice(-2));
  return addDays('2026-10-05', (week - 41) * 7);
}

/* ------------------------------------------------------------------ supply & risk ------------------------------------------------------------------ */

export const SUPPLY: Record<
  string,
  { fgAvailable: number; qcReleased: number; pendingQc: number; plannedByDate: { date: string; qty: number }[]; hasWo: boolean; materialShort: boolean }
> = {
  'PART-A': {
    fgAvailable: 120,
    qcReleased: 0,
    pendingQc: 80,
    plannedByDate: [
      { date: '2026-10-12', qty: 300 },
      { date: '2026-10-19', qty: 400 },
    ],
    hasWo: true,
    materialShort: false,
  },
  'PART-B': { fgAvailable: 260, qcReleased: 100, pendingQc: 0, plannedByDate: [{ date: '2026-10-13', qty: 400 }], hasWo: true, materialShort: false },
  'PART-C': { fgAvailable: 120, qcReleased: 0, pendingQc: 0, plannedByDate: [{ date: '2026-10-15', qty: 200 }], hasWo: true, materialShort: true },
  'PART-D': { fgAvailable: 0, qcReleased: 300, pendingQc: 200, plannedByDate: [{ date: '2026-10-18', qty: 300 }], hasWo: true, materialShort: false },
  'PART-E': { fgAvailable: 0, qcReleased: 0, pendingQc: 0, plannedByDate: [], hasWo: false, materialShort: false },
  'PART-F': { fgAvailable: 500, qcReleased: 0, pendingQc: 0, plannedByDate: [{ date: '2026-10-20', qty: 600 }], hasWo: true, materialShort: false },
};

const ACTIVE = DEMAND_LINES.filter((l) => l.outstanding > 0);
const projection = projectLines(
  ACTIVE.map((l) => ({ id: l.id, part: l.part, dueDate: l.requirementDate, outstanding: l.outstanding })),
  SUPPLY,
);

export const DELIVERY_RISK: DeliveryRiskRow[] = ACTIVE.map((l) => {
  const p = projection.get(l.id)!;
  const s = SUPPLY[l.part]!;
  const atRisk = p.gap > 0;
  const reasons: RiskReason[] = [];
  if (atRisk) {
    if (!s.hasWo) reasons.push('NO_WO');
    if (s.materialShort) reasons.push('MATERIAL_SHORT');
    if (s.pendingQc > 0) reasons.push('QC_BACKLOG');
    if (PARTS[l.part]!.cpVersion === null) reasons.push('NOT_CALCULABLE_CONVERSION');
  }
  return {
    id: `rk-${l.id}`,
    dueDate: l.requirementDate,
    customer: l.customer,
    part: l.part,
    scheduleRef: l.scheduleRef,
    outstanding: l.outstanding,
    fgAvailable: s.fgAvailable,
    qcReleased: s.qcReleased,
    pendingQc: s.pendingQc,
    plannedOutput: p.plannedOutput,
    earlierDemand: p.earlierDemand,
    projected: p.projected,
    gap: p.gap,
    atRisk,
    reasons,
  };
}).sort((a, b) => a.dueDate.localeCompare(b.dueDate));

export const DAILY_DELIVERY: DailyDeliveryRow[] = DEMAND_LINES.filter((l) => l.status !== 'SUPERSEDED' && l.status !== 'DRAFT' && l.status !== 'CANCELLED')
  .map((l) => {
    const s = SUPPLY[l.part]!;
    const shipped = l.delivered;
    const free = Math.max(0, s.fgAvailable - l.allocated);
    return {
      id: `dd-${l.id}`,
      dueDate: l.requirementDate,
      customer: l.customer,
      part: l.part,
      scheduleRef: l.scheduleRef,
      soNo: l.soNo,
      due: l.required,
      shipped,
      deliveredPod: l.shipments.filter((x) => x.date < BUSINESS_DATE).reduce((a, x) => a + x.qty, 0),
      outstanding: l.outstanding,
      fgAvailable: s.fgAvailable,
      allocated: l.allocated,
      gap: Math.max(0, l.outstanding - (l.allocated + free)),
      partial: shipped > 0 && shipped < l.required,
      risk: DELIVERY_RISK.some((x) => x.id === `rk-${l.id}` && x.atRisk),
      shipments: l.shipments,
    };
  })
  .sort((a, b) => a.dueDate.localeCompare(b.dueDate));

/* ------------------------------------------------------------------ material ------------------------------------------------------------------ */

type LotSeed = {
  lot: string;
  material: string;
  status: RmLotRow['status'];
  onHand: number;
  allocated: number;
  location: string;
  props: RmLotRow['propertiesSource'];
  wo?: string[];
  remnantOf?: string;
};
const LOT_SEEDS: LotSeed[] = [
  // doc 02 coil sample: 700 kg → est. 1,930 pcs (planning only)
  { lot: 'C-21', material: 'SPCC-12-120', status: 'UNRESTRICTED', onHand: 700, allocated: 0, location: 'RM-WH1-A02', props: 'MILL_CERT' },
  { lot: 'C-22', material: 'SPCC-12-120', status: 'UNRESTRICTED', onHand: 2450, allocated: 700, location: 'RM-WH1-A03', props: 'MILL_CERT', wo: ['WO-2214'] },
  { lot: 'C-23', material: 'SPCC-12-120', status: 'UNRESTRICTED', onHand: 2380.5, allocated: 0, location: 'RM-WH1-A04', props: 'NOMINAL' },
  { lot: 'C-24', material: 'SPCC-16-150', status: 'UNRESTRICTED', onHand: 3120, allocated: 1200, location: 'RM-WH1-B01', props: 'MEASURED', wo: ['WO-2216'] },
  { lot: 'C-25', material: 'SPCC-16-150', status: 'HOLD', onHand: 1890.2, allocated: 0, location: 'RM-WH1-QH1', props: 'MILL_CERT' },
  // doc 14 #5: pitch without unit → not calculable
  { lot: 'C-12', material: 'SGCC-10-100', status: 'BLOCKED', onHand: 702.4, allocated: 0, location: 'RM-WH1-RCV', props: 'NOMINAL' },
  { lot: 'C-13', material: 'SGCC-10-100', status: 'UNRESTRICTED', onHand: 1450, allocated: 0, location: 'RM-WH1-A07', props: 'MILL_CERT' },
  { lot: 'S-205', material: 'SPHC-20-SHT', status: 'HOLD', onHand: 35, allocated: 0, location: 'RM-WH1-QH1', props: 'MILL_CERT' },
  { lot: 'S-210', material: 'SPHC-20-SHT', status: 'UNRESTRICTED', onHand: 120, allocated: 80, location: 'RM-WH1-C02', props: 'MILL_CERT', wo: ['WO-2210'] },
  { lot: 'S-211', material: 'SPHC-20-SHT', status: 'UNRESTRICTED', onHand: 60, allocated: 0, location: 'RM-WH1-C03', props: 'MEASURED' },
  { lot: 'S-212', material: 'SPHC-20-SHT', status: 'UNRESTRICTED', onHand: 18, allocated: 18, location: 'RM-WH1-C04', props: 'MILL_CERT', wo: ['WO-2219'] },
];
for (let i = 0; i < 25; i++) {
  const material = pick(['SPCC-12-120', 'SPCC-16-150', 'SPHC-20-SHT', 'SPHC-20-SHT', 'SGCC-10-100'] as const);
  const sheet = material.endsWith('SHT');
  const onHand = sheet ? Math.round(20 + r() * 140) : k3(900 + r() * 2400);
  const status = pick(['UNRESTRICTED', 'UNRESTRICTED', 'UNRESTRICTED', 'UNRESTRICTED', 'HOLD', 'BLOCKED'] as const);
  const allocated = status === 'UNRESTRICTED' && r() < 0.3 ? (sheet ? Math.round(onHand * 0.4) : k3(onHand * 0.4)) : 0;
  LOT_SEEDS.push({
    lot: `${sheet ? 'S' : 'C'}-${300 + i}`,
    material,
    status,
    onHand,
    allocated,
    location: `RM-WH${r() < 0.7 ? 1 : 2}-${String.fromCharCode(65 + Math.floor(r() * 4))}${String(1 + Math.floor(r() * 9)).padStart(2, '0')}`,
    props: pick(['MILL_CERT', 'MILL_CERT', 'MEASURED', 'NOMINAL'] as const),
    wo: allocated ? [`WO-${2230 + i}`] : [],
  });
}

function estimate(material: string, part: string, qty: number, props: RmLotRow['propertiesSource']) {
  const pm = PARTS[part]!;
  const opts = { cpValidated: pm.cpValidated && pm.cpVersion !== null, nominal: props === 'NOMINAL' };
  return pm.form === 'COIL' ? coilEstimate(qty, pm.inputs, opts) : sheetEstimate(qty, pm.inputs, opts);
}

export const RM_LOTS: RmLotRow[] = LOT_SEEDS.map((s, i) => {
  const sheet = s.material.endsWith('SHT');
  const available = s.status === 'UNRESTRICTED' ? k3(s.onHand - s.allocated) : 0;
  const primaryPart = PARTS_BY_MATERIAL[s.material]![0]!;
  const est = estimate(s.material, primaryPart, s.status === 'UNRESTRICTED' ? available : s.onHand, s.props);
  return {
    id: `rm-${i + 1}`,
    lot: s.lot,
    item: `RM-${s.material}`,
    grade: s.material.split('-')[0]!,
    form: sheet ? 'SHEET' : 'COIL',
    location: s.location,
    warehouse: s.location.slice(0, 6),
    status: s.status,
    uom: sheet ? 'sheet' : 'kg',
    onHand: s.onHand,
    allocated: s.allocated,
    available,
    propertiesSource: s.props,
    woAllocations: s.wo ?? [],
    primaryPart,
    conversionStatus: est.status,
    estPcsLower: est.lower,
    estPcsUpper: est.upper,
    missingInputs: est.missing,
    isRemnant: false,
  };
});

export const CONVERSIONS: ConversionRow[] = RM_LOTS.filter((l) => l.status === 'UNRESTRICTED' || l.missingInputs.length).flatMap((l) =>
  PARTS_BY_MATERIAL[l.item.slice(3)]!.map((part, j) => {
    const pm = PARTS[part]!;
    const qty = l.status === 'UNRESTRICTED' ? l.available : l.onHand;
    const est = estimate(l.item.slice(3), part, qty, l.propertiesSource);
    return {
      id: `cv-${l.id}-${j}`,
      lot: l.lot,
      form: l.form,
      part,
      uom: l.uom,
      availableQty: qty,
      inputs: pm.inputs,
      cpVersion: pm.cpVersion,
      cpValidated: pm.cpValidated,
      bomRef: pm.bomRef,
      propertiesSource: l.propertiesSource,
      status: est.status,
      missingInputs: est.missing,
      lengthMm: est.lengthMm !== null ? Math.round(est.lengthMm * 10) / 10 : null,
      strokes: est.strokes,
      estPcsUpper: est.upper,
      estPcsLower: est.lower,
    };
  }),
);

export const MATERIAL_AT_PRODUCTION: MaterialAtProductionRow[] = [
  // doc 14 #19/#22: C-07 consumed 612.400 kg, remaining 87.600 kg, 40.000 kg split to remnant C-07-R1
  {
    id: 'mp-1',
    workCenter: 'WC-PRESS1',
    lot: 'C-07',
    item: 'RM-SPCC-12-120',
    uom: 'kg',
    qty: 47.6,
    custody: 'ACCEPTED',
    woAllocations: ['WO-2214'],
    lastMovement: '2026-10-08T07:40:00Z',
    idleHours: 26,
    lastMeasurement: { method: 'WEIGHED_REMAINING', at: '2026-10-08T07:35:00Z' },
    isRemnant: false,
    conservation: { issued: 700, consumed: 612.4, atProduction: 47.6, returned: 0, splitToRemnant: 40 },
  },
  {
    id: 'mp-2',
    workCenter: 'WC-PRESS1',
    lot: 'C-07-R1',
    item: 'RM-SPCC-12-120',
    uom: 'kg',
    qty: 40,
    custody: 'ACCEPTED',
    woAllocations: [],
    lastMovement: '2026-10-08T07:45:00Z',
    idleHours: 26,
    lastMeasurement: { method: 'WEIGHED_REMAINING', at: '2026-10-08T07:45:00Z' },
    isRemnant: true,
    parentLot: 'C-07',
    conservation: { issued: 0, consumed: 0, atProduction: 40, returned: 0, splitToRemnant: 0 },
  },
  {
    id: 'mp-3',
    workCenter: 'WC-PRESS3',
    lot: 'S-207',
    item: 'RM-SPHC-20-SHT',
    uom: 'sheet',
    qty: 22,
    custody: 'ACCEPTED',
    woAllocations: ['WO-2219'],
    lastMovement: '2026-10-06T02:10:00Z',
    idleHours: 74,
    lastMeasurement: { method: 'COUNTED_REMAINING', at: '2026-10-06T02:00:00Z' },
    isRemnant: false,
    conservation: { issued: 40, consumed: 18, atProduction: 22, returned: 0, splitToRemnant: 0 },
  },
  {
    id: 'mp-4',
    workCenter: 'WC-PRESS3',
    lot: 'S-210',
    item: 'RM-SPHC-20-SHT',
    uom: 'sheet',
    qty: 0,
    custody: 'ACCEPTED',
    woAllocations: ['WO-2210'],
    lastMovement: '2026-10-08T09:05:00Z',
    idleHours: 0,
    lastMeasurement: { method: 'COUNTED_REMAINING', at: '2026-10-08T09:00:00Z' },
    isRemnant: false,
    conservation: { issued: 80, consumed: 79, atProduction: 0, returned: 1, splitToRemnant: 0 },
  },
  {
    id: 'mp-5',
    workCenter: 'WC-PRESS2',
    lot: 'C-24',
    item: 'RM-SPCC-16-150',
    uom: 'kg',
    qty: 512.8,
    custody: 'IN_TRANSIT',
    woAllocations: ['WO-2216'],
    lastMovement: '2026-10-09T01:20:00Z',
    idleHours: 2,
    lastMeasurement: { method: 'NONE', at: null },
    isRemnant: false,
    conservation: { issued: 512.8, consumed: 0, atProduction: 512.8, returned: 0, splitToRemnant: 0 },
  },
  {
    id: 'mp-6',
    workCenter: 'WC-PRESS2',
    lot: 'C-26',
    item: 'RM-SPCC-16-150',
    uom: 'kg',
    qty: 233.4,
    custody: 'ACCEPTED',
    woAllocations: ['WO-2216'],
    lastMovement: '2026-10-08T23:50:00Z',
    idleHours: 3,
    lastMeasurement: { method: 'NONE', at: null },
    isRemnant: false,
    conservation: { issued: 900, consumed: 666.6, atProduction: 233.4, returned: 0, splitToRemnant: 0 },
  },
].filter((x) => x.qty > 0 || x.lot === 'S-210') as MaterialAtProductionRow[];

/* ------------------------------------------------------------------ production & quality ------------------------------------------------------------------ */

export const WO_PROGRESS: WoProgressRow[] = [
  {
    id: 'wo-1',
    wo: 'WO-2210',
    part: 'PART-D',
    status: 'IN_PROGRESS',
    plannedQty: 800,
    good: 760,
    ngRework: 3,
    ngRejected: 2,
    scrapKg: 41.2,
    consumed: 79,
    consumedUom: 'sheet',
    consumptionMeasured: true,
    materialShort: false,
    dueDate: '2026-10-10',
  },
  {
    id: 'wo-2',
    wo: 'WO-2214',
    part: 'PART-A',
    status: 'IN_PROGRESS',
    plannedQty: 2000,
    good: 1620,
    ngRework: 12,
    ngRejected: 9,
    scrapKg: 128.9,
    consumed: 612.4,
    consumedUom: 'kg',
    consumptionMeasured: true,
    materialShort: false,
    dueDate: '2026-10-12',
  },
  {
    id: 'wo-3',
    wo: 'WO-2216',
    part: 'PART-B',
    status: 'RELEASED',
    plannedQty: 1200,
    good: 0,
    ngRework: 0,
    ngRejected: 0,
    scrapKg: 0,
    consumed: 0,
    consumedUom: 'kg',
    consumptionMeasured: false,
    materialShort: false,
    dueDate: '2026-10-13',
  },
  {
    id: 'wo-4',
    wo: 'WO-2219',
    part: 'PART-C',
    status: 'IN_PROGRESS',
    plannedQty: 900,
    good: 640,
    ngRework: 8,
    ngRejected: 4,
    scrapKg: 22.6,
    consumed: 18,
    consumedUom: 'sheet',
    consumptionMeasured: false,
    materialShort: true,
    dueDate: '2026-10-15',
  },
  {
    id: 'wo-5',
    wo: 'WO-2221',
    part: 'PART-F',
    status: 'RELEASED',
    plannedQty: 600,
    good: 0,
    ngRework: 0,
    ngRejected: 0,
    scrapKg: 0,
    consumed: 0,
    consumedUom: 'kg',
    consumptionMeasured: false,
    materialShort: false,
    dueDate: '2026-10-20',
  },
  {
    id: 'wo-6',
    wo: 'WO-2222',
    part: 'PART-D',
    status: 'RELEASED',
    plannedQty: 300,
    good: 0,
    ngRework: 0,
    ngRejected: 0,
    scrapKg: 0,
    consumed: 0,
    consumedUom: 'sheet',
    consumptionMeasured: false,
    materialShort: false,
    dueDate: '2026-10-18',
  },
  {
    id: 'wo-7',
    wo: 'WO-2205',
    part: 'PART-A',
    status: 'CLOSED',
    plannedQty: 1500,
    good: 1488,
    ngRework: 6,
    ngRejected: 3,
    scrapKg: 110.4,
    consumed: 548.2,
    consumedUom: 'kg',
    consumptionMeasured: true,
    materialShort: false,
    dueDate: '2026-10-05',
  },
  {
    id: 'wo-8',
    wo: 'WO-2207',
    part: 'PART-B',
    status: 'CLOSED',
    plannedQty: 1000,
    good: 991,
    ngRework: 4,
    ngRejected: 2,
    scrapKg: 96.1,
    consumed: 701.5,
    consumedUom: 'kg',
    consumptionMeasured: true,
    materialShort: false,
    dueDate: '2026-10-06',
  },
];

const SHIFTS = ['S1', 'S2', 'S3'] as const;
export const DAILY_PRODUCTION: DailyProductionRow[] = [];
['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'].forEach((date, di) => {
  for (const shift of SHIFTS) {
    if (r() < 0.25) continue;
    const wo = pick(WO_PROGRESS.filter((w) => w.status !== 'RELEASED'));
    const coil = wo.consumedUom === 'kg';
    const good = Math.round(150 + r() * 250);
    const method = r() < 0.12 ? 'UNMEASURED_BY_DIFFERENCE' : coil ? 'WEIGHED_REMAINING' : 'COUNTED_REMAINING';
    DAILY_PRODUCTION.push({
      id: `dp-${DAILY_PRODUCTION.length + 1}`,
      productionDate: date,
      shift,
      wo: wo.wo,
      run: `RUN-${4300 + di * 10 + DAILY_PRODUCTION.length}`,
      part: wo.part,
      workCenter: coil ? pick(['WC-PRESS1', 'WC-PRESS2']) : 'WC-PRESS3',
      good,
      ngRework: Math.round(r() * 6),
      ngRejected: Math.round(r() * 4),
      scrapKg: k3(8 + r() * 30),
      consumed: coil ? k3(good * PARTS[wo.part]!.nw * 1.12) : Math.ceil(good / 10),
      consumedUom: coil ? 'kg' : 'sheet',
      method,
    });
  }
});

export const PENDING_QC: PendingQcRow[] = [
  // doc 14 #6 / #50: 500 good, 300 accepted (not received), 200 pending
  {
    id: 'qc-1',
    inspectionLot: 'IL-0412',
    part: 'PART-D',
    outputLot: 'OL-0412',
    wo: 'WO-2210',
    run: 'RUN-0418',
    productionDate: '2026-10-08',
    shift: 'S1',
    pendingQc: 200,
    accepted: 300,
    releasedNotReceived: 300,
    hold: 0,
    rework: 0,
    rejected: 0,
    ageHours: 27,
    agingBucket: '24-72h',
    inspector: 'QC · D. Lestari',
    dueBy: '2026-10-09T03:00:00Z',
  },
  {
    id: 'qc-2',
    inspectionLot: 'IL-0415',
    part: 'PART-A',
    outputLot: 'OL-0415',
    wo: 'WO-2214',
    run: 'RUN-0420',
    productionDate: '2026-10-09',
    shift: 'S1',
    pendingQc: 80,
    accepted: 0,
    releasedNotReceived: 0,
    hold: 0,
    rework: 0,
    rejected: 0,
    ageHours: 3,
    agingBucket: '<8h',
    inspector: null,
    dueBy: '2026-10-10T03:00:00Z',
  },
];
for (let i = 0; i < 12; i++) {
  const part = pick(Object.keys(PARTS).filter((p) => p !== 'PART-E'));
  const age = Math.round(r() * 96);
  const pending = r() < 0.6 ? Math.round(40 + r() * 200) : 0;
  PENDING_QC.push({
    id: `qc-${i + 3}`,
    inspectionLot: `IL-${380 + i}`.replace('IL-', 'IL-0'),
    part,
    outputLot: `OL-0${380 + i}`,
    wo: pick(WO_PROGRESS).wo,
    run: `RUN-0${380 + i}`,
    productionDate: addDays(BUSINESS_DATE, -Math.ceil(age / 24)),
    shift: pick(SHIFTS),
    pendingQc: pending,
    accepted: Math.round(r() * 300),
    releasedNotReceived: 0,
    hold: r() < 0.25 ? Math.round(10 + r() * 60) : 0,
    rework: r() < 0.2 ? Math.round(5 + r() * 20) : 0,
    rejected: r() < 0.15 ? Math.round(2 + r() * 10) : 0,
    ageHours: age,
    agingBucket: age < 8 ? '<8h' : age < 24 ? '8-24h' : age <= 72 ? '24-72h' : '>72h',
    inspector: r() < 0.7 ? pick(['QC · D. Lestari', 'QC · A. Nugroho', 'QC · S. Wibowo']) : null,
    dueBy: new Date(Date.parse(`${BUSINESS_DATE}T03:00:00Z`) + (24 - age) * 3_600_000).toISOString(),
  });
}

export const DAILY_FG_RECEIPT: DailyFgReceiptRow[] = [];
['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09'].forEach((date) => {
  for (const part of ['PART-A', 'PART-B', 'PART-C', 'PART-D']) {
    if (r() < 0.35) continue;
    const planned = Math.round(200 + r() * 300);
    const good = Math.round(planned * (0.8 + r() * 0.25));
    const released = Math.round(good * (0.6 + r() * 0.4));
    const received = date === BUSINESS_DATE ? 0 : Math.round(released * (0.7 + r() * 0.3));
    DAILY_FG_RECEIPT.push({
      id: `fg-${DAILY_FG_RECEIPT.length + 1}`,
      date,
      shift: pick(SHIFTS),
      part,
      plannedOutput: planned,
      goodPosted: good,
      qcReleased: released,
      fgReceived: received,
      receivedVsPlan: received - planned,
      releasedNotReceived: released - received,
    });
  }
});
// doc 14 #7 / CT-A3: 300 QC released, 0 FG received
DAILY_FG_RECEIPT.push({
  id: 'fg-ctA3',
  date: '2026-10-08',
  shift: 'S1',
  part: 'PART-D',
  plannedOutput: 500,
  goodPosted: 500,
  qcReleased: 300,
  fgReceived: 0,
  receivedVsPlan: -500,
  releasedNotReceived: 300,
});

const riskParts = new Set(DELIVERY_RISK.filter((x) => x.atRisk).map((x) => x.part));
export const FG_AVAILABILITY: FgAvailabilityRow[] = Object.entries(SUPPLY).flatMap(([part, s], i) => {
  const allocated = DEMAND_LINES.filter((l) => l.part === part).reduce((a, l) => a + l.allocated, 0);
  const rows: FgAvailabilityRow[] = [];
  if (s.fgAvailable + allocated > 0 || s.qcReleased > 0) {
    rows.push({
      id: `fa-${i}-1`,
      part,
      location: 'FG-WH1-A01',
      lot: `FG-${part.slice(-1)}-1008`,
      onHand: s.fgAvailable + allocated,
      allocated,
      available: s.fgAvailable,
      blocked: 0,
      incomingFromQc: s.qcReleased,
      atRiskDemand: riskParts.has(part),
    });
  }
  if (part === 'PART-B')
    rows.push({
      id: `fa-${i}-2`,
      part,
      location: 'FG-WH1-Q01',
      lot: 'FG-B-0930',
      onHand: 0,
      allocated: 0,
      available: 0,
      blocked: 45,
      incomingFromQc: 0,
      atRiskDemand: riskParts.has(part),
    });
  if (!rows.length)
    rows.push({
      id: `fa-${i}-0`,
      part,
      location: '—',
      lot: '—',
      onHand: 0,
      allocated: 0,
      available: 0,
      blocked: 0,
      incomingFromQc: 0,
      atRiskDemand: riskParts.has(part),
    });
  return rows;
});

/* ------------------------------------------------------------------ reconciliation ------------------------------------------------------------------ */

type RunSeed = {
  run: string;
  wo: string;
  part: string;
  lot: string;
  issued: number;
  returned: number;
  remaining: number;
  consumed: number;
  uom: 'kg' | 'sheet';
  method: MaterialVarianceRow['method'];
  G: number;
  NR: number;
  NJ: number;
  O: number;
  S: number | null;
  closed: boolean;
  caseRef?: MaterialVarianceRow['case'];
};
const TOL = { gyvAbs: 20, gyvPct: 2, umvAbs: 2, umvPct: 1 };
const RUN_SEEDS: RunSeed[] = [
  // doc 02 coil sample: GYV 47 / PvS −17 / UMV 7.610 kg, FINAL → breach → case
  {
    run: 'RUN-0412',
    wo: 'WO-2214',
    part: 'PART-A',
    lot: 'C-07',
    issued: 700,
    returned: 0,
    remaining: 87.6,
    consumed: 612.4,
    uom: 'kg',
    method: 'WEIGHED_REMAINING',
    G: 1620,
    NR: 12,
    NJ: 9,
    O: 0,
    S: 128.9,
    closed: true,
    caseRef: { id: 'RC-0034', status: 'INVESTIGATING', owner: 'PPIC · R. Pratama', ageDays: 1 },
  },
  // doc 02 sheet B: 80 issued, 1 returned, 79 consumed → T 790, accounted 765 → GYV 25 provisional
  {
    run: 'RUN-0418',
    wo: 'WO-2210',
    part: 'PART-D',
    lot: 'S-210',
    issued: 80,
    returned: 1,
    remaining: 0,
    consumed: 79,
    uom: 'sheet',
    method: 'COUNTED_REMAINING',
    G: 760,
    NR: 3,
    NJ: 2,
    O: 0,
    S: 41.2,
    closed: false,
    caseRef: { id: 'RC-0031', status: 'OPEN', owner: 'Supervisor · H. Saputra', ageDays: 1 },
  },
  // doc 14 #24: by-difference consumption → provisional + case
  {
    run: 'RUN-0422',
    wo: 'WO-2219',
    part: 'PART-C',
    lot: 'S-207',
    issued: 40,
    returned: 0,
    remaining: 22,
    consumed: 18,
    uom: 'sheet',
    method: 'UNMEASURED_BY_DIFFERENCE',
    G: 640,
    NR: 8,
    NJ: 4,
    O: 0,
    S: 22.6,
    closed: false,
    caseRef: { id: 'RC-0036', status: 'OPEN', owner: 'PPIC · R. Pratama', ageDays: 0 },
  },
  // doc 14 #5: conversion not calculable
  {
    run: 'RUN-0399',
    wo: 'WO-2190',
    part: 'PART-E',
    lot: 'C-11',
    issued: 650,
    returned: 0,
    remaining: 40.5,
    consumed: 609.5,
    uom: 'kg',
    method: 'WEIGHED_REMAINING',
    G: 4200,
    NR: 20,
    NJ: 11,
    O: 0,
    S: 71.3,
    closed: true,
  },
  {
    run: 'RUN-0401',
    wo: 'WO-2205',
    part: 'PART-A',
    lot: 'C-05',
    issued: 600,
    returned: 51.8,
    remaining: 0,
    consumed: 548.2,
    uom: 'kg',
    method: 'WEIGHED_REMAINING',
    G: 1488,
    NR: 6,
    NJ: 3,
    O: 2,
    S: 110.4,
    closed: true,
  },
  {
    run: 'RUN-0405',
    wo: 'WO-2207',
    part: 'PART-B',
    lot: 'C-03',
    issued: 760,
    returned: 58.5,
    remaining: 0,
    consumed: 701.5,
    uom: 'kg',
    method: 'WEIGHED_CONSUMED',
    G: 2950,
    NR: 10,
    NJ: 6,
    O: 0,
    S: 61.8,
    closed: true,
  },
  {
    run: 'RUN-0409',
    wo: 'WO-2209',
    part: 'PART-C',
    lot: 'S-198',
    issued: 30,
    returned: 0,
    remaining: 0,
    consumed: 30,
    uom: 'sheet',
    method: 'COUNTED_REMAINING',
    G: 1390,
    NR: 5,
    NJ: 2,
    O: 0,
    S: null,
    closed: true,
  },
];

function sheetWeightKg(part: string) {
  return part === 'PART-C' || part === 'PART-D' ? 46.7 : 0; // SPHC 2.0 × 1219 × 2438 ≈ 46.7 kg (sample engineering value)
}

export const MATERIAL_VARIANCE: MaterialVarianceRow[] = RUN_SEEDS.map((s, i) => {
  const pm = PARTS[s.part]!;
  const conv =
    pm.form === 'COIL'
      ? coilEstimate(s.consumed, pm.inputs, { cpValidated: pm.cpValidated, nominal: false })
      : sheetEstimate(s.consumed, pm.inputs, { cpValidated: pm.cpValidated, nominal: false });
  const T = conv.status === 'NOT_CALCULABLE' ? null : conv.upper;
  const Ckg = s.uom === 'kg' ? s.consumed : s.consumed * sheetWeightKg(s.part);
  const ind = varianceIndicators({
    T,
    G: s.G,
    NR: s.NR,
    NJ: s.NJ,
    O: s.O,
    SY: pm.inputs.standardYield ?? null,
    NW: pm.nw,
    Ckg,
    S: s.S,
    measured: s.method !== 'UNMEASURED_BY_DIFFERENCE',
    cpValidated: pm.cpValidated && pm.cpVersion !== null,
    reportingClosed: s.closed,
  });
  const breach =
    (ind.gyvStatus === 'FINAL' && Math.abs(ind.gyv!) > TOL.gyvAbs && Math.abs(ind.gyvPct!) > TOL.gyvPct) ||
    (ind.umvStatus === 'FINAL' && Math.abs(ind.umvKg!) > TOL.umvAbs && Math.abs(ind.umvPct!) > TOL.umvPct);
  return {
    id: `mv-${i + 1}`,
    run: s.run,
    wo: s.wo,
    part: s.part,
    inputLot: s.lot,
    issued: s.issued,
    returned: s.returned,
    remaining: s.remaining,
    consumed: s.consumed,
    consumedUom: s.uom,
    method: s.method,
    cpVersion: pm.cpVersion,
    theoretical: T,
    good: s.G,
    ngRework: s.NR,
    ngRejected: s.NJ,
    other: s.O,
    scrapKg: s.S,
    ...ind,
    missingInputs: conv.missing.length ? conv.missing : s.S === null ? ['weighed scrap or zero-scrap confirmation'] : [],
    toleranceRule: `Plant A · GYV > ${TOL.gyvAbs} pcs and > ${TOL.gyvPct} % · UMV > ${TOL.umvAbs} kg and > ${TOL.umvPct} %`,
    breach,
    case: s.caseRef ?? null,
    confirmedLossKg: null,
    confirmedDiscrepancyKg: null,
  };
});
