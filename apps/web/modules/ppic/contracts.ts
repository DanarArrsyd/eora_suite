/**
 * PPIC Control Tower — typed data contracts (Phase 3, doc 19, ADR-0008).
 *
 * Each list screen is one QueryDefinition resource: `GET /api/v1/query/{resource}` with `ListQuery` → rows + `ListMeta`.
 * The overview is the single-row resource `ct.overview` (same endpoint pattern, no new endpoint style).
 * Rows carry quantities with their own UOM; the UI never converts or sums across UOMs and never recomputes
 * server-side business values (outstanding, estimates, indicators) — it only formats them.
 */
import type { ListSource } from '@eora/features/data';

export type MaterialUom = 'kg' | 'sheet';
export type Uom = MaterialUom | 'pcs';

export const CT_RESOURCES = {
  overview: 'ct.overview',
  demandLines: 'ct.demand-lines',
  weeklyDemand: 'ct.weekly-demand',
  blanketOrders: 'ct.blanket-orders',
  dailyDelivery: 'ct.daily-delivery',
  deliveryRisk: 'ct.delivery-risk',
  rmLots: 'ct.rm-lots',
  conversion: 'ct.conversion',
  materialAtProduction: 'ct.material-at-production',
  woProgress: 'ct.wo-progress',
  dailyProduction: 'ct.daily-production',
  pendingQc: 'ct.pending-qc',
  dailyFgReceipt: 'ct.daily-fg-receipt',
  fgAvailability: 'ct.fg-availability',
  materialVariance: 'ct.material-variance',
} as const;

/* ------------------------------------------------------------------ demand (CT-9, CT-5, CT-8) ------------------------------------------------------------------ */

export type ScheduleStatus = 'DRAFT' | 'FIRM' | 'PARTIALLY_FULFILLED' | 'FULFILLED' | 'SUPERSEDED' | 'CANCELLED';
export type ScheduleType = 'CUSTOMER_SCHEDULE' | 'KANBAN_RELEASE' | 'MANUAL';
export type DemandExceptionType = 'REVISION_MAPPING_AMBIGUOUS' | 'FORECAST_MATCH_UNCERTAIN' | 'BLANKET_EXCEEDED';

/** Grain: delivery schedule line (D-034). Quantities in pcs. */
export type DemandLineRow = {
  id: string;
  scheduleRef: string;
  scheduleType: ScheduleType;
  revision: number;
  status: ScheduleStatus;
  customer: string;
  part: string;
  plant: string;
  soNo: string;
  soLine: number;
  /** Customer PO is the source document of the SO line; never netted on its own (BR-DEM-05). */
  cpoNo: string;
  cpoLine: number;
  blanket: boolean;
  requirementDate: string; // ISO date, plant calendar
  bucket: string; // e.g. 2026-W42 (D-041)
  required: number;
  delivered: number;
  outstanding: number; // BR-DEM-10, computed server-side
  allocated: number; // FG allocated to this line (not fulfilment, BR-DEM-09)
  shipments: { ref: string; date: string; qty: number; reversed: boolean }[];
  exception?: DemandExceptionType;
};

/** Grain: customer × part × plant × weekly bucket (BR-DEM-07/08/13). Read-only aggregation from the server. */
export type WeeklyDemandRow = {
  id: string;
  bucket: string;
  bucketStart: string;
  customer: string | null; // null = forecast without customer (cannot be matched, D-040)
  part: string;
  plant: string;
  firmRequired: number;
  firmOutstanding: number;
  forecast: number;
  netForecast: number;
  outstandingDemand: number;
  matchStatus: 'MATCHED' | 'NO_FORECAST' | 'FORECAST_ONLY' | 'NEEDS_REVIEW';
};

/** Grain: blanket SO line (BR-DEM-14, D-039). */
export type BlanketOrderRow = {
  id: string;
  customer: string;
  part: string;
  soNo: string;
  soLine: number;
  revision: number;
  blanketQty: number;
  releasedQty: number;
  deliveredQty: number;
  remainingScheduledQty: number;
  unscheduledBalance: number;
};

/** Grain: due date × customer × part × schedule line (CT-5). */
export type DailyDeliveryRow = {
  id: string;
  dueDate: string;
  customer: string;
  part: string;
  scheduleRef: string;
  soNo: string;
  due: number;
  shipped: number;
  deliveredPod: number;
  outstanding: number;
  fgAvailable: number;
  allocated: number;
  gap: number; // outstanding − (allocated + free FG), ≥ 0
  partial: boolean;
  risk: boolean;
  shipments: { ref: string; date: string; qty: number; reversed: boolean }[];
};

export type RiskReason = 'NO_WO' | 'MATERIAL_SHORT' | 'QC_BACKLOG' | 'CAPACITY' | 'NOT_CALCULABLE_CONVERSION';

/** Grain: demand line by due date (CT-8). Each projection contribution is its own column. */
export type DeliveryRiskRow = {
  id: string;
  dueDate: string;
  customer: string;
  part: string;
  scheduleRef: string;
  outstanding: number;
  fgAvailable: number;
  qcReleased: number;
  /** Shown separately; not in the projection until an expected-acceptance policy is approved. */
  pendingQc: number;
  plannedOutput: number; // released WOs finishing before due date
  earlierDemand: number;
  projected: number; // fgAvailable + qcReleased + plannedOutput − earlierDemand
  gap: number; // max(0, outstanding − projected)
  atRisk: boolean;
  reasons: RiskReason[];
};

/* ------------------------------------------------------------------ material (CT-1, conversion, CT-2) ------------------------------------------------------------------ */

export type StockStatus = 'UNRESTRICTED' | 'PENDING_QC' | 'QC_RELEASED' | 'HOLD' | 'REWORK' | 'REJECTED' | 'BLOCKED';
export type ConversionStatus = 'CALCULATED' | 'PROVISIONAL' | 'NOT_CALCULABLE';

/** Grain: lot × location × status (CT-1). Material UOM only. */
export type RmLotRow = {
  id: string;
  lot: string;
  item: string;
  grade: string;
  form: 'COIL' | 'SHEET';
  location: string;
  warehouse: string;
  status: StockStatus;
  uom: MaterialUom;
  onHand: number;
  allocated: number;
  available: number;
  propertiesSource: 'MILL_CERT' | 'MEASURED' | 'NOMINAL';
  woAllocations: string[];
  /** Primary-part estimate (planning only, never stock). Alternative parts are listed in the conversion view, never summed. */
  primaryPart: string;
  conversionStatus: ConversionStatus;
  estPcsLower: number | null;
  estPcsUpper: number | null;
  missingInputs: string[];
  isRemnant: boolean;
  parentLot?: string;
};

export type ConversionInputs = {
  basis: 'COIL_PITCH' | 'SHEET_LAYOUT';
  densityGcm3?: number;
  thicknessMm?: number;
  widthMm?: number;
  pitch?: number;
  pitchUnit?: 'mm' | null;
  cavity?: number;
  headTailMm?: number;
  blanksPerSheet?: number;
  partsPerBlank?: number;
  standardYield?: number;
};

/** Grain: lot × part (alternative parts per lot are separate rows and never summed, doc 19 CT-1). */
export type ConversionRow = {
  id: string;
  lot: string;
  form: 'COIL' | 'SHEET';
  part: string;
  uom: MaterialUom;
  availableQty: number;
  inputs: ConversionInputs;
  cpVersion: string | null;
  cpValidated: boolean;
  /** BOM / routing version supplying cavity or parts per blank. */
  bomRef: string | null;
  propertiesSource: 'MILL_CERT' | 'MEASURED' | 'NOMINAL';
  status: ConversionStatus;
  missingInputs: string[];
  lengthMm: number | null;
  strokes: number | null;
  estPcsUpper: number | null; // gross theoretical
  estPcsLower: number | null; // applies SY
};

/** Grain: work center × lot at MAT_AT_PROD (CT-2). */
export type MaterialAtProductionRow = {
  id: string;
  workCenter: string;
  lot: string;
  item: string;
  uom: MaterialUom;
  qty: number;
  custody: 'ACCEPTED' | 'IN_TRANSIT';
  woAllocations: string[];
  lastMovement: string; // ISO datetime
  idleHours: number;
  lastMeasurement: { method: 'WEIGHED_REMAINING' | 'COUNTED_REMAINING' | 'MEASURED_LENGTH' | 'NONE'; at: string | null };
  isRemnant: boolean;
  parentLot?: string;
  /** ConservationLine for the period: issued = consumed + atProduction + returned + splitToRemnant. */
  conservation: { issued: number; consumed: number; atProduction: number; returned: number; splitToRemnant: number };
};

/* ------------------------------------------------------------------ production (CT-10) ------------------------------------------------------------------ */

export type MeasurementMethod = 'WEIGHED_REMAINING' | 'WEIGHED_CONSUMED' | 'COUNTED_REMAINING' | 'MEASURED_LENGTH' | 'UNMEASURED_BY_DIFFERENCE';

export type WoProgressRow = {
  id: string;
  wo: string;
  part: string;
  status: 'RELEASED' | 'IN_PROGRESS' | 'CLOSED';
  plannedQty: number;
  good: number;
  ngRework: number;
  ngRejected: number;
  scrapKg: number;
  consumed: number;
  consumedUom: MaterialUom;
  consumptionMeasured: boolean;
  /** Server flag: allocated + available RM < WO requirement in material UOM (doc 19 exception rule). */
  materialShort: boolean;
  dueDate: string;
};

export type DailyProductionRow = {
  id: string;
  productionDate: string;
  shift: 'S1' | 'S2' | 'S3';
  wo: string;
  run: string;
  part: string;
  workCenter: string;
  good: number;
  ngRework: number;
  ngRejected: number;
  scrapKg: number;
  consumed: number;
  consumedUom: MaterialUom;
  method: MeasurementMethod;
};

/* ------------------------------------------------------------------ quality & FG (CT-3, CT-4, CT-7) ------------------------------------------------------------------ */

export type PendingQcRow = {
  id: string;
  inspectionLot: string;
  part: string;
  outputLot: string;
  wo: string;
  run: string;
  productionDate: string;
  shift: 'S1' | 'S2' | 'S3';
  pendingQc: number;
  accepted: number; // QC_RELEASED from this lot
  releasedNotReceived: number;
  hold: number;
  rework: number;
  rejected: number;
  ageHours: number;
  agingBucket: '<8h' | '8-24h' | '24-72h' | '>72h';
  inspector: string | null;
  dueBy: string;
};

export type DailyFgReceiptRow = {
  id: string;
  date: string;
  shift: 'S1' | 'S2' | 'S3';
  part: string;
  plannedOutput: number;
  goodPosted: number;
  qcReleased: number;
  fgReceived: number;
  receivedVsPlan: number; // fgReceived − plannedOutput
  releasedNotReceived: number;
};

export type FgAvailabilityRow = {
  id: string;
  part: string;
  location: string;
  lot: string;
  onHand: number; // UNRESTRICTED
  allocated: number;
  available: number;
  blocked: number;
  incomingFromQc: number; // QC_RELEASED not yet received — not available
  atRiskDemand: boolean; // part has CT-8 risk lines (server flag)
};

/* ------------------------------------------------------------------ reconciliation (CT-6) ------------------------------------------------------------------ */

export type IndicatorStatus = 'NOT_CALCULABLE' | 'PROVISIONAL' | 'FINAL';
export type CaseStatus = 'OPEN' | 'INVESTIGATING' | 'REVIEW' | 'CLOSED';

export type MaterialVarianceRow = {
  id: string;
  run: string;
  wo: string;
  part: string;
  inputLot: string;
  issued: number;
  returned: number;
  remaining: number;
  consumed: number;
  consumedUom: MaterialUom;
  method: MeasurementMethod;
  cpVersion: string | null;
  theoretical: number | null;
  good: number;
  ngRework: number;
  ngRejected: number;
  other: number;
  scrapKg: number | null;
  gyv: number | null;
  gyvPct: number | null;
  gyvStatus: IndicatorStatus;
  pvs: number | null;
  pvsPct: number | null;
  pvsStatus: IndicatorStatus;
  umvKg: number | null;
  umvPct: number | null;
  umvStatus: IndicatorStatus;
  missingInputs: string[];
  toleranceRule: string;
  breach: boolean;
  case: { id: string; status: CaseStatus; owner: string; ageDays: number } | null;
  /** Only from resolved cases / approved counts (D-024); never derived from the indicators. */
  confirmedLossKg: number | null;
  confirmedDiscrepancyKg: number | null;
};

/* ------------------------------------------------------------------ overview (CT-0) ------------------------------------------------------------------ */

export type KpiDefinition = {
  id: string;
  label: string;
  value: number;
  uom: Uom | 'lines' | 'lots' | 'cases' | 'WOs';
  /** Secondary quantity with its own UOM (never summed with `value`). */
  secondary?: { value: number; uom: Uom };
  definition: string;
  sourceDomain: string;
  basis: string;
  meaning: string;
  href: string;
  tone: 'danger' | 'warning' | 'neutral';
};

export type FlowStage = { id: string; label: string; quantities: { value: number; uom: Uom }[]; href: string; note?: string };

export type ExceptionRow = {
  id: string;
  type: 'DELIVERY_AT_RISK' | 'MATERIAL_SHORTAGE' | 'CONVERSION_NOT_CALCULABLE' | 'PENDING_QC_AGING' | 'VARIANCE_CASE' | 'MATERIAL_IDLE' | DemandExceptionType;
  title: string;
  detail: string;
  part: string | null;
  dueDate: string | null;
  qty: number | null;
  uom: Uom | null;
  href: string;
  impactRank: number;
};

export type DueVsSupplyPoint = { date: string; due: number; projected: number };

export type OverviewResult = {
  businessDate: string;
  kpis: KpiDefinition[];
  flow: { material: FlowStage[]; output: FlowStage[] };
  exceptions: ExceptionRow[];
  dueVsSupply: { part: string; points: DueVsSupplyPoint[] }[];
  meta: { asOf: string; timezone: string };
};

export interface OverviewSource {
  readonly resource: typeof CT_RESOURCES.overview;
  readonly isMock: boolean;
  get(filters: { customer?: string; part?: string }, signal?: AbortSignal): Promise<OverviewResult>;
}

export type ControlTowerSources = {
  overview: OverviewSource;
  demandLines: ListSource<DemandLineRow>;
  weeklyDemand: ListSource<WeeklyDemandRow>;
  blanketOrders: ListSource<BlanketOrderRow>;
  dailyDelivery: ListSource<DailyDeliveryRow>;
  deliveryRisk: ListSource<DeliveryRiskRow>;
  rmLots: ListSource<RmLotRow>;
  conversion: ListSource<ConversionRow>;
  materialAtProduction: ListSource<MaterialAtProductionRow>;
  woProgress: ListSource<WoProgressRow>;
  dailyProduction: ListSource<DailyProductionRow>;
  pendingQc: ListSource<PendingQcRow>;
  dailyFgReceipt: ListSource<DailyFgReceiptRow>;
  fgAvailability: ListSource<FgAvailabilityRow>;
  materialVariance: ListSource<MaterialVarianceRow>;
};
