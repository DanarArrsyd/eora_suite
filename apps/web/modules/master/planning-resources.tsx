import { api, call } from '@eora/features/auth';
import type { FilterField } from '@eora/features/patterns';
import { Badge } from '@eora/ui';
import { unitPrecisionError } from '@/modules/materials/resources';
import type { MasterGroup } from './master-screen';
import { PLANT_FILTER } from './resources';
import { queryAll, queryOptions } from './source';
import type { MasterResource, MasterRow } from './types';

/* M5 step 1 (doc 34 §1.1, D-121, G-MPS-2/4): Master Data → Planning Parameters on the live API. */

export type PlanParameterRow = MasterRow & {
  itemId: string;
  itemName: string;
  itemType: 'RAW' | 'CONSUMABLE' | 'SEMI_FINISHED' | 'FINISHED';
  plantId: string;
  plantCode: string;
  uomCode: string;
  uomDecimals: number;
  productionLeadTimeDays: number | null;
  purchaseLeadTimeDays: number | null;
  safetyStock: string | null;
  safetyStockConfigured: boolean;
  lotPolicy: 'LOT_FOR_LOT';
  updatedAt: string;
  updatedByName: string | null;
};

const MADE = ['FINISHED', 'SEMI_FINISHED'];
const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });
const days = (n: number | null) => (n === null ? '—' : `${n} ${n === 1 ? 'day' : 'days'}`);
const leadTime = (r: PlanParameterRow) => (MADE.includes(r.itemType) ? r.productionLeadTimeDays : r.purchaseLeadTimeDays);
const safety = (r: PlanParameterRow) => (r.safetyStockConfigured ? `${r.safetyStock} ${r.uomCode}` : <Badge tone="warning">Not configured</Badge>);

const ITEM_FILTER: FilterField = {
  key: 'itemId',
  type: 'entity',
  entity: 'part',
  label: 'Item',
  loadOptions: (_deps, signal) => queryOptions('core.items', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
};

export const PLAN_PARAMETERS: MasterResource<PlanParameterRow> = {
  id: 'planning-parameters',
  resource: 'planning.plan_parameters',
  permission: { view: 'planning.plan_parameter.view', create: 'planning.plan_parameter.create', label: 'planning parameters' },
  title: 'Planning Parameters',
  description:
    'Lead time and safety stock per item and plant, used by the next MPS and MRP run. Made items take a production lead time, bought items a purchase lead time, in working days. An empty safety stock counts as 0 and shows as Not configured.',
  itemLabel: 'parameter sets',
  singular: 'parameter set',
  searchPlaceholder: 'Search item…',
  rowLabel: (r) => `${r.code} at ${r.plantCode}`,
  defaultSort: [
    { id: 'code', desc: false },
    { id: 'plantCode', desc: false },
  ],
  filters: [
    PLANT_FILTER,
    ITEM_FILTER,
    {
      key: 'safetyStockConfigured',
      type: 'select',
      source: 'static',
      label: 'Safety stock',
      options: [
        { value: 'true', label: 'Configured' },
        { value: 'false', label: 'Not configured' },
      ],
    },
  ],
  columns: [
    { id: 'code', header: 'Item', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => r.itemName },
    { id: 'plantCode', header: 'Plant', sortable: true, cell: (r) => r.plantCode },
    {
      id: 'leadTime',
      header: 'Lead time',
      numeric: true,
      cell: (r) => days(leadTime(r)),
      sub: (r) => (MADE.includes(r.itemType) ? 'Production' : 'Purchase'),
    },
    { id: 'safetyStock', header: 'Safety stock', numeric: true, critical: true, cell: safety },
    { id: 'updatedAt', header: 'Changed by', sortable: true, cell: (r) => r.updatedByName ?? '—' },
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants', createOnly: true },
    {
      key: 'itemId',
      label: 'Item',
      kind: 'select',
      required: true,
      options: 'items',
      createOnly: true,
      hint: 'A made or a bought item; one set per item and plant.',
    },
    {
      key: 'leadTimeDays',
      label: 'Lead time (working days)',
      kind: 'number',
      decimals: 0,
      min: 0,
      max: 365,
      hint: 'Production lead time for a made item, purchase lead time for a bought item.',
    },
    { key: 'safetyStock', label: 'Safety stock', kind: 'number', decimals: 6, min: 0, hint: "In the item's base unit. Leave empty: Not configured." },
  ],
  blank: { plantId: '', itemId: '', leadTimeDays: '', safetyStock: '' },
  toForm: (r) => ({ leadTimeDays: leadTime(r) === null ? '' : String(leadTime(r)), safetyStock: r.safetyStock ?? '' }),
  facts: (r) => [
    { label: 'Item', value: `${r.code} · ${r.itemName}` },
    { label: 'Plant', value: r.plantCode },
    { label: 'Lot policy', value: 'Lot-for-lot' },
    { label: 'Safety stock', value: safety(r) },
  ],
  check: async (v, row): Promise<Record<string, string>> => {
    const [item] = await queryAll<{ baseUomCode: string; baseUomDecimals: number }>('core.items', { id: row ? row.itemId : v.itemId! });
    const problem = item && v.safetyStock ? unitPrecisionError(v.safetyStock, item.baseUomCode, item.baseUomDecimals) : undefined;
    const problems: Record<string, string> = {};
    if (problem) problems.safetyStock = problem;
    return problems;
  },
  create: async (v) => {
    const [item] = await queryAll<{ itemType: string }>('core.items', { id: v.itemId! });
    const lead = v.leadTimeDays === '' ? null : Number(v.leadTimeDays);
    return call(() =>
      api.POST('/planning/plan-parameters', {
        body: {
          plantId: v.plantId!,
          itemId: v.itemId!,
          ...(MADE.includes(item?.itemType ?? '') ? { productionLeadTimeDays: lead } : { purchaseLeadTimeDays: lead }),
          safetyStock: v.safetyStock || null,
        },
      }),
    );
  },
  update: (r, v) => {
    const lead = v.leadTimeDays === '' ? null : Number(v.leadTimeDays);
    const body: Record<string, unknown> = {};
    if (lead !== leadTime(r)) body[MADE.includes(r.itemType) ? 'productionLeadTimeDays' : 'purchaseLeadTimeDays'] = lead;
    if ((v.safetyStock || null) !== r.safetyStock) body.safetyStock = v.safetyStock || null;
    return call(() =>
      api.PATCH('/planning/plan-parameters/{parameter}', { params: { path: { parameter: r.id } }, headers: ifMatch(r.version), body: body as never }),
    );
  },
};

export const PLANNING_SCREENS: Record<string, MasterGroup> = {
  'planning-parameters': { title: 'Planning Parameters', parts: [PLAN_PARAMETERS as never] },
};
