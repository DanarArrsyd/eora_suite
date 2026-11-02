import { api, call } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DomainStatusBadge, STATUS_CATALOGUE, type FilterField } from '@eora/features/patterns';
import { PHYSICAL_LOCATION_TYPES, PLANT_FILTER } from '@/modules/master/resources';
import { queryOptions } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { unitPrecisionError } from '@/modules/materials/resources';

/*
 * M3 step 4 (doc 32 §1.4, D-048, D-098): Inventory → Stock Overview on the live API. Every balance above zero from the
 * ledger; Transfer moves part or all of a line to another location of the same kind in the plant, status unchanged.
 */

type StockStatus = keyof (typeof STATUS_CATALOGUE)['stock'];

export type StockLineRow = {
  id: string;
  code: string;
  version: number;
  plantId: string;
  plantCode: string;
  warehouseCode: string | null;
  workCenterCode: string | null;
  locationId: string;
  locationCode: string;
  locationType: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  lotId: string | null;
  lotNo: string | null;
  supplierLotNo: string | null;
  stockStatus: StockStatus;
  qty: string;
  availableQty: string;
  uomCode: string;
  uomDecimals: number;
};

const STATUS_OPTIONS: Option[] = Object.entries(STATUS_CATALOGUE.stock).map(([value, e]) => ({ value, label: e.label }));
const typeLabel = (type: string) => PHYSICAL_LOCATION_TYPES.find((t) => t.value === type)?.label ?? type;
const place = (r: StockLineRow) => `${r.plantCode} / ${r.warehouseCode ?? (r.workCenterCode ? `work center ${r.workCenterCode}` : '—')}`;

const FILTERS: FilterField[] = [
  PLANT_FILTER,
  {
    key: 'itemId',
    type: 'entity',
    entity: 'material',
    label: 'Item',
    loadOptions: (_deps, signal) => queryOptions('core.items', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
  },
  { key: 'stockStatus', type: 'multiselect', source: 'static', label: 'Status', options: STATUS_OPTIONS },
  { key: 'locationType', type: 'multiselect', source: 'static', label: 'Location kind', options: PHYSICAL_LOCATION_TYPES },
];

type Posted = { data: { docNo: string; replayed: boolean } };

export const STOCK_OVERVIEW: MasterResource<StockLineRow> = {
  id: 'stock-overview',
  resource: 'inventory.stock',
  permission: { view: 'inventory.stock.view', create: 'materials.transfer.post', label: 'stock' },
  noCreate: true,
  rowAction: {
    label: 'Transfer',
    permission: 'materials.transfer.post',
    deniedReason: 'Your roles do not allow transfers.',
    // the destination comes from the locations list (organisation master data)
    needs: [{ permission: 'master.org.view', reason: 'Transfers choose a destination from the locations list, which your roles cannot read.' }],
  },
  title: 'Stock Overview',
  description:
    'Stock on hand from the ledger, per location, lot and status. Available is unrestricted stock not yet allocated; blocked, pending QC and held stock is never available.',
  itemLabel: 'stock lines',
  singular: 'stock line',
  searchPlaceholder: 'Search item…',
  rowLabel: (r) => `${r.itemCode} ${r.lotNo ?? 'no lot'} at ${r.locationCode} ${STATUS_CATALOGUE.stock[r.stockStatus]?.label ?? r.stockStatus}`,
  drawerTitle: (r) => `Transfer ${r.itemCode}${r.lotNo ? ` lot ${r.lotNo}` : ''}`,
  defaultSort: [
    { id: 'itemCode', desc: false },
    { id: 'lotNo', desc: false },
    { id: 'locationCode', desc: false },
  ],
  filters: FILTERS,
  // stock is for every role; plant and item options come from master data, which some roles (operators) do not read
  filterPermissions: { plantId: 'master.org.view', itemId: 'master.part.view' },
  columns: [
    { id: 'itemCode', header: 'Item', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    {
      id: 'lotNo',
      header: 'Lot',
      sortable: true,
      cell: (r) => (r.lotNo ? <span className="font-mono">{r.lotNo}</span> : 'No lot'),
      sub: (r) => (r.supplierLotNo ? `Supplier ${r.supplierLotNo}` : undefined),
    },
    { id: 'locationCode', header: 'Location', sortable: true, cell: (r) => r.locationCode, sub: (r) => `${place(r)} · ${typeLabel(r.locationType)}` },
    { id: 'stockStatus', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { id: 'qty', header: 'On hand', numeric: true, critical: true, cell: (r) => `${r.qty} ${r.uomCode}` },
    { id: 'availableQty', header: 'Available', numeric: true, cell: (r) => `${r.availableQty} ${r.uomCode}` },
  ],
  fields: [
    {
      key: 'toLocationId',
      label: 'To location',
      kind: 'select',
      required: true,
      options: 'locations',
      // D-098: another location of the same kind in the plant
      optionFilter: (row) => {
        const r = row as StockLineRow;
        return { plantId: r.plantId, locationType: [r.locationType] };
      },
      hint: 'Same plant and same kind of location. Issue, return and QC have their own documents.',
    },
    {
      key: 'qty',
      label: 'Quantity',
      kind: 'number',
      required: true,
      decimals: 6,
      positive: true,
      hint: 'Up to what is available: allocated stock stays where it is.',
    },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500 },
  ],
  blank: { toLocationId: '', qty: '', note: '' },
  // unrestricted stock moves only what is not allocated (BR-INV-08)
  toForm: (r) => ({ toLocationId: '', qty: r.stockStatus === 'UNRESTRICTED' ? r.availableQty : r.qty, note: '' }),
  facts: (r) => [
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'Lot', value: r.lotNo ? `${r.lotNo}${r.supplierLotNo ? ` (supplier ${r.supplierLotNo})` : ''}` : 'No lot' },
    { label: 'From', value: `${r.locationCode} · ${place(r)}` },
    { label: 'Status', value: <DomainStatusBadge domain="stock" status={r.stockStatus} /> },
    { label: 'On hand', value: `${r.qty} ${r.uomCode}` },
    { label: 'Available', value: `${r.availableQty} ${r.uomCode}` },
  ],
  check: async (v, r): Promise<Record<string, string>> => {
    const problems: Record<string, string> = {};
    if (r && v.toLocationId === r.locationId) problems.toLocationId = 'Choose another location than the one the stock is in.';
    const qty = r && v.qty ? unitPrecisionError(v.qty, r.uomCode, r.uomDecimals) : undefined;
    if (qty) problems.qty = qty;
    else if (r?.stockStatus === 'UNRESTRICTED' && v.qty && Number(v.qty) > Number(r.availableQty)) {
      problems.qty = `Only ${r.availableQty} ${r.uomCode} is available here; the rest is allocated.`;
    }
    return problems;
  },
  confirm: (v, r) => ({
    title: 'Transfer this stock',
    description: `${v.qty ?? ''} ${r?.uomCode ?? ''} moves from ${r?.locationCode ?? 'its location'} now, with its status unchanged. A posted transfer is corrected by its reversal.`,
    confirmLabel: 'Transfer',
  }),
  savedMessage: (result) => {
    const d = (result as Posted).data;
    return d.replayed ? `${d.docNo} was already posted; nothing new was moved` : `${d.docNo} transferred`;
  },
  create: () => Promise.reject(new Error('Transfers start from a stock line.')),
  update: (r, v, ctx) =>
    call(() =>
      api.POST('/inventory/transfers', {
        params: { header: { 'Idempotency-Key': ctx.idempotencyKey } },
        body: {
          plantId: r.plantId,
          itemId: r.itemId,
          lotId: r.lotId,
          stockStatus: r.stockStatus,
          fromLocationId: r.locationId,
          toLocationId: v.toLocationId!,
          qty: v.qty!,
          note: v.note || null,
        },
      }),
    ),
};
