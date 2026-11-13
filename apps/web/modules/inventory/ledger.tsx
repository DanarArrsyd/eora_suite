import type { Option } from '@eora/features/data';
import { DomainStatusBadge, STATUS_CATALOGUE, type FilterField } from '@eora/features/patterns';
import { DOCUMENT_TYPES } from '@/modules/master/document-types';
import { PLANT_FILTER } from '@/modules/master/resources';
import { queryOptions } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';

/*
 * M3 step 9 (doc 32 §1.9, BR-INV-02, D-084): Inventory → Ledger → Movement Ledger on the live API. Every posted leg at
 * a stock location, newest first: what entered or left stock, by which document. The ledger is append-only, so rows are
 * viewed, never edited; a wrong document is corrected by its reversal.
 */

type StockStatus = keyof (typeof STATUS_CATALOGUE)['stock'];

export type MovementRow = {
  id: string;
  code: string;
  version: number;
  documentId: string;
  docType: string;
  documentStatus: 'POSTED' | 'REVERSED';
  legNo: number;
  movementCode: string;
  plantCode: string;
  locationCode: string;
  locationType: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  lotNo: string | null;
  stockStatus: StockStatus;
  qty: string;
  uomCode: string;
  reversesMovementId: string | null;
  effectiveDate: string;
  postedAt: string;
  postedByName: string | null;
};

/** D-084 movement codes posted so far (M3, M4 step 3), by their screen names. */
export const MOVEMENT_CODES: Record<string, string> = {
  RCPT_SUPPLIER: 'Supplier receipt',
  INCOMING_RELEASE: 'Incoming release',
  TRANSFER_LOC: 'Transfer',
  LOT_SPLIT_OUT: 'Split out',
  LOT_SPLIT_IN: 'Split in',
  ADJ_PLUS: 'Adjustment plus',
  ADJ_MINUS: 'Adjustment minus',
  ISSUE_TO_PROD: 'Issue to production',
  RETURN_FROM_PROD: 'Return from production',
};
const options = (labels: Record<string, string>): Option[] => Object.entries(labels).map(([value, label]) => ({ value, label }));
const movement = (r: MovementRow) => `${MOVEMENT_CODES[r.movementCode] ?? r.movementCode}${r.reversesMovementId ? ' (reversal)' : ''}`;
const signed = (r: MovementRow) => `${r.qty.startsWith('-') ? `−${r.qty.slice(1)}` : `+${r.qty}`} ${r.uomCode}`;

const FILTERS: FilterField[] = [
  PLANT_FILTER,
  {
    key: 'itemId',
    type: 'entity',
    entity: 'material',
    label: 'Item',
    loadOptions: (_deps, signal) => queryOptions('core.items', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
  },
  { key: 'docType', type: 'multiselect', source: 'static', label: 'Document type', options: options(DOCUMENT_TYPES) },
  { key: 'movementCode', type: 'multiselect', source: 'static', label: 'Movement', options: options(MOVEMENT_CODES) },
  {
    key: 'stockStatus',
    type: 'multiselect',
    source: 'static',
    label: 'Status',
    options: Object.entries(STATUS_CATALOGUE.stock).map(([value, e]) => ({ value, label: e.label })),
  },
];

export const MOVEMENT_LEDGER: MasterResource<MovementRow> = {
  id: 'movements',
  resource: 'inventory.movements',
  permission: { view: 'inventory.stock.view', create: 'inventory.stock.view', label: 'the movement ledger' },
  noCreate: true,
  // the stock side of every posting: the legs at physical locations (the supplier and adjustment counter-legs are left out)
  fixedFilters: { stockSide: 'true' },
  title: 'Movement Ledger',
  description:
    'Every movement of stock, newest first: what entered or left a location, by which document. The ledger is never edited; a wrong document is corrected by its reversal.',
  itemLabel: 'movements',
  singular: 'movement',
  searchPlaceholder: 'Search document or item…',
  rowLabel: (r) => `${r.code} leg ${r.legNo} ${r.itemCode}`,
  drawerTitle: (r) => `${r.code} · leg ${r.legNo}`,
  defaultSort: [{ id: 'sequence', desc: true }],
  filters: FILTERS,
  filterPermissions: { plantId: 'master.org.view', itemId: 'master.part.view' },
  columns: [
    { id: 'sequence', header: 'Posted', lead: true, sortable: true, cell: (r) => when(r.postedAt), sub: (r) => r.postedByName ?? undefined },
    { id: 'code', header: 'Document', cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => DOCUMENT_TYPES[r.docType] ?? r.docType },
    { id: 'movementCode', header: 'Movement', cell: movement },
    { id: 'itemCode', header: 'Item', cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.lotNo ?? 'No lot' },
    { id: 'locationCode', header: 'Location', cell: (r) => r.locationCode, sub: (r) => r.plantCode },
    { id: 'stockStatus', header: 'Status', cell: (r) => <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { id: 'qty', header: 'Quantity', numeric: true, critical: true, cell: signed },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  readOnly: () => 'The ledger is never edited. A wrong document is corrected by its reversal (Inventory → Reversals).',
  facts: (r) => [
    { label: 'Document', value: `${r.code} · ${DOCUMENT_TYPES[r.docType] ?? r.docType}${r.documentStatus === 'REVERSED' ? ' (reversed)' : ''}` },
    { label: 'Movement', value: movement(r) },
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: r.lotNo ?? 'No lot' },
    { label: 'Location', value: `${r.locationCode} · ${r.plantCode}` },
    { label: 'Status', value: <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { label: 'Quantity', value: signed(r) },
    { label: 'Day', value: r.effectiveDate },
    { label: 'Posted', value: `${when(r.postedAt)}${r.postedByName ? ` by ${r.postedByName}` : ''}` },
  ],
  create: () => Promise.reject(new Error('The ledger is written by postings only.')),
  update: () => Promise.reject(new Error('The ledger is never edited.')),
};
