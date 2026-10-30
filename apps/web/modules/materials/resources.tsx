import { api, call } from '@eora/features/auth';
import { DomainStatusBadge, type FilterField } from '@eora/features/patterns';
import { queryOptions } from '@/modules/master/source';
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

const idempotent = (key: string) => ({ 'Idempotency-Key': key });
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
    { key: 'qty', label: 'Quantity', kind: 'number', required: true, decimals: 6, min: 0, hint: "In the item's base unit." },
    { key: 'supplierLotNo', label: 'Supplier lot or heat number', kind: 'text', maxLength: 60, hint: 'As on the mill certificate. Optional.' },
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
        headers: idempotent(ctx.idempotencyKey),
        body: {
          plantId: v.plantId!,
          supplierId: v.supplierId!,
          itemId: v.itemId!,
          locationId: v.locationId!,
          qty: v.qty!,
          supplierLotNo: v.supplierLotNo || null,
          note: v.note || null,
        },
      } as never),
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
    { key: 'qty', label: 'Quantity to release', kind: 'number', required: true, decimals: 6, min: 0, hint: 'All of it, or part when the rest waits.' },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500, hint: 'E.g. the inspection report number.' },
  ],
  blank: { qty: '', note: '' },
  toForm: (r) => ({ qty: r.qty, note: '' }),
  facts: (r) => [
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: r.lotNo ? `${r.lotNo}${r.supplierLotNo ? ` (supplier ${r.supplierLotNo})` : ''}` : 'No lot' },
    { label: 'Blocked', value: `${r.qty} ${r.uomCode} at ${r.plantCode} / ${r.locationCode}` },
  ],
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
        headers: idempotent(ctx.idempotencyKey),
        body: { plantId: r.plantId, itemId: r.itemId, lotId: r.lotId, lines: [{ locationId: r.locationId, qty: v.qty! }], note: v.note || null },
      } as never),
    ),
};
