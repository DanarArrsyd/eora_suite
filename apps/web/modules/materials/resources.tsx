import { api, call } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DomainStatusBadge, STATUS_CATALOGUE, type FilterField } from '@eora/features/patterns';
import { queryAll, queryOptions } from '@/modules/master/source';
import type { MasterResource, MasterRow } from '@/modules/master/types';

/*
 * M3 step 3 (doc 32 §1.3, D-062, D-097): Materials → Receiving on the live API. Both screens post through the inventory
 * posting service; quantities are in the item's base unit (M3-D5) and every posting carries the form's idempotency key.
 */

export type ReceiptRow = MasterRow & {
  status: 'POSTED' | 'REVERSED';
  plantCode: string;
  effectiveDate: string;
  postedAt: string;
  postedByName: string | null;
  supplierCode: string | null;
  supplierName: string | null;
  itemCode: string;
  itemName: string;
  lotNo: string | null;
  supplierLotNo: string | null;
  locationCode: string;
  stockStatus: 'BLOCKED' | 'UNRESTRICTED';
  qty: string;
  uomCode: string;
  note: string | null;
};

export type StockRow = {
  id: string;
  code: string;
  version: number;
  plantId: string;
  plantCode: string;
  warehouseCode: string;
  locationId: string;
  locationCode: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  lotId: string | null;
  lotNo: string | null;
  supplierLotNo: string | null;
  stockStatus: 'BLOCKED';
  qty: string;
  uomCode: string;
  uomDecimals: number;
};

type Posted = { data: { documentId: string; docNo: string; lotNo: string | null; replayed: boolean } };

const RECEIPT = { view: 'materials.receipt.view', create: 'materials.receipt.post', label: 'raw-material receipts' } as const;
const RELEASE_VIEW = { view: 'materials.incoming_release.view', create: 'materials.incoming_release.post', label: 'incoming releases' } as const;

const PLANT_FILTER: FilterField = {
  key: 'plantId',
  type: 'entity',
  entity: 'plant',
  label: 'Plant',
  loadOptions: (_deps, signal) => queryOptions('core.plants', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
};
const ITEM_FILTER: FilterField = {
  key: 'itemId',
  type: 'entity',
  entity: 'material',
  label: 'Item',
  loadOptions: (_deps, signal) => queryOptions('core.items', (r) => `${String(r.code)} · ${String(r.name)}`, { itemType: ['RAW', 'CONSUMABLE'] }, signal),
};

const idempotent = (key: string) => ({ header: { 'Idempotency-Key': key } });

/** The ledger keeps a quantity at its unit's decimals (BR-INV-10): PCS whole numbers, KG up to 3 … */
export function unitPrecisionError(qty: string, uom: string, decimals: number): string | undefined {
  const fraction = qty.split('.')[1]?.replace(/0+$/, '') ?? '';
  if (fraction.length <= decimals) return undefined;
  return decimals === 0 ? `Enter a whole number of ${uom}.` : `Enter ${uom} with at most ${decimals} decimals.`;
}
const posted = (verb: string) => (result: unknown) => {
  const d = (result as Posted).data;
  const lot = d.lotNo ? `, lot ${d.lotNo}` : '';
  return d.replayed ? `${d.docNo} was already ${verb}; nothing new was posted` : `${d.docNo} ${verb}${lot}`;
};

export const RM_RECEIPTS: MasterResource<ReceiptRow> = {
  id: 'rm-receipts',
  resource: 'inventory.receipts',
  permission: RECEIPT,
  appendOnly: 'A posted receipt is never edited. A wrong receipt is corrected by its reversal, approved by a second person.',
  title: 'RM Receipts',
  description:
    'Raw material and consumables received from suppliers. A lot-controlled item gets its own lot; an item that needs its incoming release stays Blocked until QC releases it.',
  itemLabel: 'receipts',
  singular: 'receipt',
  searchPlaceholder: 'Search receipt number or item…',
  rowLabel: (r) => r.code,
  defaultSort: [{ id: 'postedAt', desc: true }],
  filters: [
    PLANT_FILTER,
    ITEM_FILTER,
    {
      key: 'supplierId',
      type: 'select',
      source: 'api',
      label: 'Supplier',
      loadOptions: (_deps, signal) => queryOptions('core.suppliers', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
    },
  ],
  columns: [
    { id: 'code', header: 'Receipt', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => r.effectiveDate },
    { id: 'supplierCode', header: 'Supplier', sortable: true, cell: (r) => r.supplierCode ?? '—', sub: (r) => r.supplierName },
    { id: 'itemCode', header: 'Item', cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    {
      id: 'lotNo',
      header: 'Lot',
      cell: (r) => (r.lotNo ? <span className="font-mono">{r.lotNo}</span> : 'No lot'),
      sub: (r) => (r.supplierLotNo ? `Supplier ${r.supplierLotNo}` : undefined),
    },
    { id: 'locationCode', header: 'Location', cell: (r) => r.locationCode, sub: (r) => r.plantCode },
    { id: 'qty', header: 'Quantity', numeric: true, critical: true, cell: (r) => `${r.qty} ${r.uomCode}` },
    { id: 'stockStatus', header: 'Received as', cell: (r) => <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { id: 'postedAt', header: 'Posted by', sortable: true, cell: (r) => r.postedByName ?? '—' },
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants' },
    { key: 'supplierId', label: 'Supplier', kind: 'select', required: true, options: 'suppliers' },
    { key: 'itemId', label: 'Item', kind: 'select', required: true, options: 'receivedItems', hint: 'Raw material or consumables.' },
    { key: 'locationId', label: 'Put away at', kind: 'select', required: true, options: 'rmLocations', hint: 'A raw-material location of the plant.' },
    {
      key: 'qty',
      label: 'Quantity',
      kind: 'number',
      required: true,
      decimals: 6,
      positive: true,
      hint: "In the item's base unit, shown after the item.",
    },
    {
      key: 'supplierLotNo',
      label: 'Supplier lot or heat number',
      kind: 'text',
      maxLength: 60,
      hint: 'As on the mill certificate. Lot-tracked items only; optional.',
    },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500 },
  ],
  blank: { plantId: '', supplierId: '', itemId: '', locationId: '', qty: '', supplierLotNo: '', note: '' },
  toForm: () => ({}),
  facts: (r) => [
    { label: 'Received', value: `${r.effectiveDate} · ${r.plantCode}` },
    { label: 'Supplier', value: r.supplierCode ? `${r.supplierCode} · ${r.supplierName ?? ''}` : '—' },
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: r.lotNo ? `${r.lotNo}${r.supplierLotNo ? ` (supplier ${r.supplierLotNo})` : ''}` : 'No lot' },
    { label: 'Quantity', value: `${r.qty} ${r.uomCode} at ${r.locationCode}` },
    { label: 'Received as', value: <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { label: 'Posted by', value: r.postedByName ?? '—' },
    ...(r.note ? [{ label: 'Note', value: r.note }] : []),
  ],
  check: async (v): Promise<Record<string, string>> => {
    const [item] = await queryAll<{ baseUomCode: string; baseUomDecimals: number; lotControlled: boolean }>('core.items', { id: v.itemId! });
    if (!item) return {};
    const problems: Record<string, string> = {};
    const qty = v.qty ? unitPrecisionError(v.qty, item.baseUomCode, item.baseUomDecimals) : undefined;
    if (qty) problems.qty = qty;
    // a supplier lot is kept on the lot: an item without lots has nowhere to keep it
    if (!item.lotControlled && v.supplierLotNo) problems.supplierLotNo = 'This item is not tracked by lot. Leave the supplier lot or heat number empty.';
    return problems;
  },
  confirm: (v) => ({
    title: 'Post this receipt',
    description: v.qty
      ? `${v.qty} goes into stock now. A posted receipt is never edited; a wrong one is reversed with a second person's approval.`
      : 'The quantity goes into stock now.',
    confirmLabel: 'Post receipt',
  }),
  savedMessage: posted('posted'),
  create: (v, ctx) =>
    call(() =>
      api.POST('/inventory/receipts', {
        params: idempotent(ctx.idempotencyKey),
        body: {
          plantId: v.plantId!,
          supplierId: v.supplierId!,
          itemId: v.itemId!,
          locationId: v.locationId!,
          qty: v.qty!,
          supplierLotNo: v.supplierLotNo || null,
          note: v.note || null,
        },
      }),
    ),
  update: () => Promise.reject(new Error('Receipts are never edited.')),
};

export const INCOMING_RELEASE: MasterResource<StockRow> = {
  id: 'incoming-release',
  resource: 'inventory.stock',
  permission: RELEASE_VIEW,
  noCreate: true,
  rowAction: { label: 'Release', permission: 'materials.incoming_release.post', deniedReason: 'Your roles do not allow incoming releases.' },
  fixedFilters: { stockStatus: 'BLOCKED', locationType: 'RM_WAREHOUSE' },
  title: 'Incoming Release',
  description: 'Received material waiting for its incoming release. Releasing makes it Unrestricted where it lies, so production can use it; nothing moves.',
  itemLabel: 'blocked lines',
  singular: 'blocked line',
  searchPlaceholder: 'Search item…',
  rowLabel: (r) => `${r.itemCode} ${r.lotNo ?? 'no lot'} at ${r.locationCode}`,
  drawerTitle: (r) => `Release ${r.itemCode}${r.lotNo ? ` lot ${r.lotNo}` : ''}`,
  defaultSort: [
    { id: 'itemCode', desc: false },
    { id: 'lotNo', desc: false },
  ],
  filters: [PLANT_FILTER, ITEM_FILTER],
  columns: [
    { id: 'itemCode', header: 'Item', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    {
      id: 'lotNo',
      header: 'Lot',
      sortable: true,
      cell: (r) => (r.lotNo ? <span className="font-mono">{r.lotNo}</span> : 'No lot'),
      sub: (r) => (r.supplierLotNo ? `Supplier ${r.supplierLotNo}` : undefined),
    },
    { id: 'locationCode', header: 'Location', sortable: true, cell: (r) => r.locationCode, sub: (r) => `${r.plantCode} / ${r.warehouseCode}` },
    { id: 'qty', header: 'Blocked', numeric: true, critical: true, cell: (r) => `${r.qty} ${r.uomCode}` },
  ],
  fields: [
    {
      key: 'qty',
      label: 'Quantity to release',
      kind: 'number',
      required: true,
      decimals: 6,
      positive: true,
      hint: 'All of it, or part when the rest waits.',
    },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500, hint: 'E.g. the inspection report number.' },
  ],
  blank: { qty: '', note: '' },
  toForm: (r) => ({ qty: r.qty, note: '' }),
  facts: (r) => [
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: r.lotNo ? `${r.lotNo}${r.supplierLotNo ? ` (supplier ${r.supplierLotNo})` : ''}` : 'No lot' },
    { label: 'Blocked', value: `${r.qty} ${r.uomCode} at ${r.plantCode} / ${r.locationCode}` },
  ],
  check: async (v, r): Promise<Record<string, string>> => {
    const error = r && v.qty ? unitPrecisionError(v.qty, r.uomCode, r.uomDecimals) : undefined;
    return error ? { qty: error } : {};
  },
  confirm: (v, r) => ({
    title: 'Release this material',
    description: `${v.qty ?? ''} ${r ? r.uomCode : ''} becomes Unrestricted at ${r?.locationCode ?? 'its location'} and can be issued to production.`,
    confirmLabel: 'Release',
  }),
  savedMessage: posted('released'),
  create: () => Promise.reject(new Error('Releases start from a blocked line.')),
  update: (r, v, ctx) =>
    call(() =>
      api.POST('/inventory/incoming-releases', {
        params: idempotent(ctx.idempotencyKey),
        body: { plantId: r.plantId, itemId: r.itemId, lotId: r.lotId, lines: [{ locationId: r.locationId, qty: v.qty! }], note: v.note || null },
      }),
    ),
};

/*
 * M3 step 5 (doc 32 §1.5, doc 04 row 7, D-099): Materials → Lots → Material Lots. Lot-tracked stock per place and
 * status; Split takes part of a line into a child lot at the same place (`<lot>-01`, `-02` …) with its measured
 * quantity and optional dimensions. The API takes several children at once; the screen splits off one per posting.
 */

export type LotLineRow = {
  id: string;
  code: string;
  version: number;
  plantId: string;
  plantCode: string;
  warehouseCode: string | null;
  workCenterCode: string | null;
  locationId: string;
  locationCode: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  lotId: string;
  lotNo: string;
  supplierLotNo: string | null;
  lotOrigin: 'RECEIPT' | 'OUTPUT' | 'SPLIT';
  parentLotNo: string | null;
  stockStatus: keyof (typeof STATUS_CATALOGUE)['stock'];
  qty: string;
  availableQty: string;
  uomCode: string;
  uomDecimals: number;
};

type Split = { data: { docNo: string; replayed: boolean; children: { lotNo: string; qty: string }[] } };

const ORIGINS: Option[] = [
  { value: 'RECEIPT', label: 'Received' },
  { value: 'SPLIT', label: 'Split' },
  { value: 'OUTPUT', label: 'Production output' },
];
const mm = (key: 'widthMm' | 'lengthMm' | 'thicknessMm', label: string) =>
  ({ key, label, kind: 'number', decimals: 3, positive: true, hint: 'Millimetres, as measured. Optional.' }) as const;
const lotPlace = (r: LotLineRow) => `${r.plantCode} / ${r.warehouseCode ?? (r.workCenterCode ? `work center ${r.workCenterCode}` : '—')}`;
// unrestricted stock splits only what is not allocated (BR-INV-08); other statuses split what is there
const splittable = (r: LotLineRow) => (r.stockStatus === 'UNRESTRICTED' ? r.availableQty : r.qty);

export const MATERIAL_LOTS: MasterResource<LotLineRow> = {
  id: 'material-lots',
  resource: 'inventory.stock',
  permission: { view: 'production.lot_split.view', create: 'production.lot_split.post', label: 'material lots' },
  noCreate: true,
  rowAction: { label: 'Split', permission: 'production.lot_split.post', deniedReason: 'Your roles do not allow lot splits.' },
  fixedFilters: { lotTracked: 'true' },
  title: 'Material Lots',
  description:
    'Lot-tracked stock per place and status. Split takes part of a lot into a child lot where it lies, e.g. a remnant or a cut length; the location total stays the same.',
  itemLabel: 'lot lines',
  singular: 'lot line',
  searchPlaceholder: 'Search item…',
  rowLabel: (r) => `${r.itemCode} ${r.lotNo} at ${r.locationCode} ${STATUS_CATALOGUE.stock[r.stockStatus]?.label ?? r.stockStatus}`,
  drawerTitle: (r) => `Split lot ${r.lotNo}`,
  defaultSort: [
    { id: 'itemCode', desc: false },
    { id: 'lotNo', desc: false },
    { id: 'locationCode', desc: false },
  ],
  filters: [
    PLANT_FILTER,
    {
      ...ITEM_FILTER,
      loadOptions: (_deps, signal) => queryOptions('core.items', (r) => `${String(r.code)} · ${String(r.name)}`, { lotControlled: 'true' }, signal),
    },
    {
      key: 'stockStatus',
      type: 'multiselect',
      source: 'static',
      label: 'Status',
      options: Object.entries(STATUS_CATALOGUE.stock).map(([value, e]) => ({ value, label: e.label })),
    },
    { key: 'lotOrigin', type: 'multiselect', source: 'static', label: 'Origin', options: ORIGINS },
  ],
  filterPermissions: { plantId: 'master.org.view', itemId: 'master.part.view' },
  columns: [
    { id: 'itemCode', header: 'Item', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    {
      id: 'lotNo',
      header: 'Lot',
      sortable: true,
      cell: (r) => <span className="font-mono">{r.lotNo}</span>,
      sub: (r) =>
        [r.parentLotNo ? `Split from ${r.parentLotNo}` : null, r.supplierLotNo ? `Supplier ${r.supplierLotNo}` : null].filter(Boolean).join(' · ') || undefined,
    },
    { id: 'locationCode', header: 'Location', sortable: true, cell: (r) => r.locationCode, sub: lotPlace },
    { id: 'stockStatus', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { id: 'qty', header: 'On hand', numeric: true, critical: true, cell: (r) => `${r.qty} ${r.uomCode}` },
  ],
  fields: [
    { key: 'qty', label: 'Child lot quantity', kind: 'number', required: true, decimals: 6, positive: true, hint: 'Measured. The parent lot keeps the rest.' },
    mm('widthMm', 'Width (mm)'),
    mm('lengthMm', 'Length (mm)'),
    mm('thicknessMm', 'Thickness (mm)'),
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500 },
  ],
  blank: { qty: '', widthMm: '', lengthMm: '', thicknessMm: '', note: '' },
  toForm: () => ({ qty: '', widthMm: '', lengthMm: '', thicknessMm: '', note: '' }),
  facts: (r) => [
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: `${r.lotNo}${r.supplierLotNo ? ` (supplier ${r.supplierLotNo})` : ''}` },
    ...(r.parentLotNo ? [{ label: 'Split from', value: r.parentLotNo }] : []),
    { label: 'At', value: `${r.locationCode} · ${lotPlace(r)}` },
    { label: 'Status', value: <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { label: 'On hand', value: `${r.qty} ${r.uomCode}` },
  ],
  check: async (v, r): Promise<Record<string, string>> => {
    const error = r && v.qty ? unitPrecisionError(v.qty, r.uomCode, r.uomDecimals) : undefined;
    if (error) return { qty: error };
    if (r && v.qty && Number(v.qty) > Number(splittable(r))) {
      return {
        qty:
          r.stockStatus === 'UNRESTRICTED'
            ? `Only ${r.availableQty} ${r.uomCode} is available here; the rest is allocated.`
            : `Only ${r.qty} ${r.uomCode} is here.`,
      };
    }
    return {};
  },
  confirm: (v, r) => ({
    title: 'Split this lot',
    description: `${v.qty ?? ''} ${r?.uomCode ?? ''} of lot ${r?.lotNo ?? ''} becomes a new child lot at ${r?.locationCode ?? 'its location'}, with the same status. A posted split is corrected by its reversal.`,
    confirmLabel: 'Split',
  }),
  savedMessage: (result) => {
    const d = (result as Split).data;
    const children = d.children.map((c) => c.lotNo).join(', ');
    return d.replayed ? `${d.docNo} was already posted; no new lot was made` : `${d.docNo} split off lot ${children}`;
  },
  create: () => Promise.reject(new Error('Splits start from a lot line.')),
  update: (r, v, ctx) =>
    call(() =>
      api.POST('/inventory/lot-splits', {
        params: idempotent(ctx.idempotencyKey),
        body: {
          plantId: r.plantId,
          itemId: r.itemId,
          lotId: r.lotId,
          stockStatus: r.stockStatus,
          locationId: r.locationId,
          children: [{ qty: v.qty!, widthMm: v.widthMm || null, lengthMm: v.lengthMm || null, thicknessMm: v.thicknessMm || null }],
          note: v.note || null,
        },
      }),
    ),
};
