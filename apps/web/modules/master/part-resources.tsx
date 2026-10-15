import { api, call } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DomainStatusBadge, type FilterField } from '@eora/features/patterns';
import type { MasterGroup } from './master-screen';
import { STATUS_OPTIONS } from './resources';
import { queryOptions } from './source';
import type { FormValues, MasterResource, MasterRow } from './types';

/*
 * Part and partner master (doc 29 §1.2, doc 26 §2.2, D-073, D-074): units, items with their material specification,
 * item unit conversions, customers, customer part mapping, suppliers, and die ↔ part links. Codes are fixed once created;
 * conversion versions and closed mappings never change.
 */

const PART = { view: 'master.part.view', create: 'master.part.create', label: 'part and partner master data' } as const;
const ORG = { view: 'master.org.view', create: 'master.org.create', label: 'organisation master data' } as const;

type Status = 'ACTIVE' | 'INACTIVE';
export type UomRow = MasterRow & { name: string; uomClass: string; decimals: number; status: Status };
export type ItemRow = MasterRow & {
  name: string;
  itemType: string;
  baseUomId: string;
  baseUomCode: string;
  lotControlled: boolean;
  inspectionRequired: boolean;
  incomingReleaseRequired: boolean;
  drawingNo: string | null;
  drawingRev: string | null;
  status: 'ACTIVE' | 'INACTIVE' | 'OBSOLETE';
  specId: string | null;
  specGrade: string | null;
  specThicknessMm: string | null;
  specWidthMm: string | null;
  specDensityGCm3: string | null;
  specVersion: number | null;
};
export type ConversionRow = MasterRow & {
  itemId: string;
  itemName: string;
  fromUomCode: string;
  toUomCode: string;
  factor: string;
  effectiveFrom: string;
  effectiveTo: string | null;
  current: boolean;
};
export type PartnerRow = MasterRow & { name: string; status: Status };
export type CustomerItemRow = MasterRow & {
  customerId: string;
  customerCode: string;
  customerDrawingRev: string | null;
  itemId: string;
  itemCode: string;
  itemName: string;
  effectiveFrom: string;
  effectiveTo: string | null;
  current: boolean;
  status: Status;
};
export type DieItemRow = MasterRow & {
  dieId: string;
  dieName: string;
  plantCode: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  partsPerStroke: number;
  status: Status;
};

const opts = (pairs: [string, string][]): Option[] => pairs.map(([value, label]) => ({ value, label }));
const label = (options: Option[], value: string | null | undefined) => options.find((o) => o.value === value)?.label ?? value ?? '—';
const YES_NO = opts([
  ['true', 'Yes'],
  ['false', 'No'],
]);
const UOM_CLASSES = opts([
  ['MASS', 'Mass'],
  ['COUNT', 'Count'],
  ['LENGTH', 'Length'],
  ['AREA', 'Area'],
  ['VOLUME', 'Volume'],
]);
/** Semi-Finished Material: an intermediate stage item with its own stock (D-059), never "WIP". */
const ITEM_TYPES = opts([
  ['RAW', 'Raw material'],
  ['SEMI_FINISHED', 'Semi-Finished Material'],
  ['FINISHED', 'Finished good'],
  ['SCRAP', 'Scrap'],
  ['CONSUMABLE', 'Consumable'],
]);
const ITEM_STATUS = [...STATUS_OPTIONS, { value: 'OBSOLETE', label: 'Obsolete' }];
const STATUS_FILTER: FilterField = { key: 'status', type: 'select', source: 'static', label: 'Status', options: STATUS_OPTIONS };

const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });
const yes = (v: string | undefined) => v === 'true';
/** Only the keys the form changed go to the server. */
function changed(before: FormValues, after: FormValues, keys: string[]): Record<string, string> {
  return Object.fromEntries(keys.filter((k) => (after[k] ?? '') !== (before[k] ?? '')).map((k) => [k, after[k] ?? '']));
}
const statusColumn = <T extends MasterRow & { status: string }>() => ({
  id: 'status',
  header: 'Status',
  critical: true,
  sortable: true,
  cell: (r: T) => <DomainStatusBadge domain="master" status={r.status as 'ACTIVE'} />,
});
const dayRange = (from: string, to: string | null) => (to ? `${from} – ${to}` : `From ${from}`);

export const UOMS: MasterResource<UomRow> = {
  id: 'uoms',
  resource: 'core.uoms',
  permission: PART,
  title: 'Units',
  description: 'Units of measure. Code, kind and decimals are fixed once created because stored quantities are rounded with them.',
  itemLabel: 'units',
  singular: 'unit',
  searchPlaceholder: 'Search code or name…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [{ id: 'code', desc: false }],
  filters: [{ key: 'uomClass', type: 'select', source: 'static', label: 'Kind', options: UOM_CLASSES }, STATUS_FILTER],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name },
    { id: 'uomClass', header: 'Kind', sortable: true, cell: (r) => label(UOM_CLASSES, r.uomClass) },
    { id: 'decimals', header: 'Decimals', numeric: true, sortable: true, cell: (r) => r.decimals },
    statusColumn<UomRow>(),
  ],
  fields: [
    {
      key: 'code',
      label: 'Code',
      kind: 'text',
      required: true,
      code: true,
      maxLength: 10,
      createOnly: true,
      codePattern: { pattern: /^[A-Z][A-Z0-9_]*$/, message: 'Use A-Z, 0-9 or "_", starting with a letter.' },
      hint: 'Fixed once created, e.g. KG, PCS, M.',
    },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 100 },
    { key: 'uomClass', label: 'Kind', kind: 'select', required: true, options: UOM_CLASSES, createOnly: true },
    { key: 'decimals', label: 'Decimals', kind: 'number', required: true, min: 0, max: 6, createOnly: true, hint: 'kg 3, pieces and sheets 0.' },
    { key: 'status', label: 'Status', kind: 'select', required: true, options: STATUS_OPTIONS, editOnly: true },
  ],
  blank: { code: '', name: '', uomClass: '', decimals: '0', status: 'ACTIVE' },
  toForm: (r) => ({ code: r.code, name: r.name, uomClass: r.uomClass, decimals: String(r.decimals), status: r.status }),
  facts: (r) => [
    { label: 'Kind', value: label(UOM_CLASSES, r.uomClass) },
    { label: 'Decimals', value: r.decimals },
  ],
  create: (v) => call(() => api.POST('/core/uoms', { body: { code: v.code!, name: v.name!, uomClass: v.uomClass as 'MASS', decimals: Number(v.decimals) } })),
  update: (r, v) =>
    call(() =>
      api.PATCH('/core/uoms/{uom}', {
        params: { path: { uom: r.id } },
        headers: ifMatch(r.version),
        body: changed(UOMS.toForm(r), v, ['name', 'status']) as { name?: string; status?: Status },
      }),
    ),
};

const ITEM_CODE = { pattern: /^[A-Z0-9][A-Z0-9._/-]*$/, message: 'Use A-Z, 0-9, ".", "_", "/" or "-", starting with a letter or digit.' };
const SPEC_KEYS = ['specGrade', 'specThicknessMm', 'specWidthMm', 'specDensityGCm3'] as const;
const num = (v: string | null) => (v === null ? '' : String(Number(v)));

async function saveSpec(itemId: string, v: FormValues, specVersion: number | null) {
  const body = {
    grade: v.specGrade || null,
    thicknessMm: v.specThicknessMm ? Number(v.specThicknessMm) : null,
    widthMm: v.specWidthMm ? Number(v.specWidthMm) : null,
    densityGCm3: v.specDensityGCm3 ? Number(v.specDensityGCm3) : null,
  };
  return call(() =>
    api.PUT('/core/items/{item}/material-spec', { params: { path: { item: itemId } }, headers: specVersion ? ifMatch(specVersion) : undefined, body }),
  );
}

export const ITEMS: MasterResource<ItemRow> = {
  id: 'items',
  resource: 'core.items',
  permission: PART,
  title: 'Items',
  description: 'Parts and materials. Code and base unit are fixed once created; obsolete items stay for history.',
  itemLabel: 'items',
  singular: 'item',
  searchPlaceholder: 'Search code, name or drawing…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [{ id: 'code', desc: false }],
  filters: [
    { key: 'itemType', type: 'multiselect', source: 'static', label: 'Type', options: ITEM_TYPES },
    { key: 'status', type: 'select', source: 'static', label: 'Status', options: ITEM_STATUS },
    { key: 'inspectionRequired', type: 'select', source: 'static', label: 'Inspection', options: YES_NO },
  ],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span> },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name, title: (r) => r.name },
    { id: 'itemType', header: 'Type', sortable: true, cell: (r) => label(ITEM_TYPES, r.itemType) },
    { id: 'baseUomCode', header: 'Base unit', sortable: true, cell: (r) => r.baseUomCode },
    { id: 'drawing', header: 'Drawing', cell: (r) => (r.drawingNo ? (r.drawingRev ? `${r.drawingNo} rev ${r.drawingRev}` : r.drawingNo) : '—') },
    {
      id: 'flags',
      header: 'Controls',
      cell: (r) =>
        [r.lotControlled ? 'Lot' : null, r.inspectionRequired ? 'Inspection' : null, r.incomingReleaseRequired ? 'Incoming release' : null]
          .filter(Boolean)
          .join(', ') || 'None',
    },
    {
      id: 'status',
      header: 'Status',
      critical: true,
      sortable: true,
      cell: (r) => <DomainStatusBadge domain="master" status={r.status === 'OBSOLETE' ? 'INACTIVE' : r.status} />,
      sub: (r) => (r.status === 'OBSOLETE' ? 'Obsolete' : undefined),
    },
  ],
  fields: [
    {
      key: 'code',
      label: 'Code',
      kind: 'text',
      required: true,
      code: true,
      maxLength: 40,
      createOnly: true,
      codePattern: ITEM_CODE,
      hint: 'Your part number. Fixed once created.',
    },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 200 },
    { key: 'itemType', label: 'Type', kind: 'select', required: true, options: ITEM_TYPES },
    {
      key: 'baseUomId',
      label: 'Base unit',
      kind: 'select',
      required: true,
      options: 'uoms',
      createOnly: true,
      hint: 'Stock is kept in this unit. Fixed once created.',
    },
    { key: 'lotControlled', label: 'Lot controlled', kind: 'select', required: true, options: YES_NO },
    { key: 'inspectionRequired', label: 'Inspection required', kind: 'select', required: true, options: YES_NO },
    {
      key: 'incomingReleaseRequired',
      label: 'Incoming release required',
      kind: 'select',
      required: true,
      options: YES_NO,
      hint: 'Receipts arrive blocked until someone releases them.',
    },
    { key: 'drawingNo', label: 'Drawing number', kind: 'text', maxLength: 60 },
    { key: 'drawingRev', label: 'Drawing revision', kind: 'text', maxLength: 20 },
    { key: 'status', label: 'Status', kind: 'select', required: true, options: ITEM_STATUS, editOnly: true },
    {
      key: 'specGrade',
      label: 'Material grade',
      kind: 'text',
      maxLength: 60,
      hint: 'Material specification (optional): the measured properties conversions use.',
    },
    { key: 'specThicknessMm', label: 'Thickness (mm)', kind: 'number', decimals: 3, min: 0.001 },
    { key: 'specWidthMm', label: 'Width (mm)', kind: 'number', decimals: 3, min: 0.001 },
    { key: 'specDensityGCm3', label: 'Density (g/cm³)', kind: 'number', decimals: 4, min: 0.0001 },
  ],
  blank: {
    code: '',
    name: '',
    itemType: '',
    baseUomId: '',
    lotControlled: 'true',
    inspectionRequired: 'false',
    incomingReleaseRequired: 'false',
    drawingNo: '',
    drawingRev: '',
    status: 'ACTIVE',
    specGrade: '',
    specThicknessMm: '',
    specWidthMm: '',
    specDensityGCm3: '',
  },
  toForm: (r) => ({
    code: r.code,
    name: r.name,
    itemType: r.itemType,
    baseUomId: r.baseUomId,
    lotControlled: String(r.lotControlled),
    inspectionRequired: String(r.inspectionRequired),
    incomingReleaseRequired: String(r.incomingReleaseRequired),
    drawingNo: r.drawingNo ?? '',
    drawingRev: r.drawingRev ?? '',
    status: r.status,
    specGrade: r.specGrade ?? '',
    specThicknessMm: num(r.specThicknessMm),
    specWidthMm: num(r.specWidthMm),
    specDensityGCm3: num(r.specDensityGCm3),
  }),
  facts: (r) => [{ label: 'Base unit', value: r.baseUomCode }],
  create: async (v) => {
    const created = await call(() =>
      api.POST('/core/items', {
        body: {
          code: v.code!,
          name: v.name!,
          itemType: v.itemType as 'RAW',
          baseUomId: v.baseUomId!,
          lotControlled: yes(v.lotControlled),
          inspectionRequired: yes(v.inspectionRequired),
          incomingReleaseRequired: yes(v.incomingReleaseRequired),
          drawingNo: v.drawingNo || null,
          drawingRev: v.drawingRev || null,
        },
      }),
    );
    if (SPEC_KEYS.some((k) => v[k])) await saveSpec((created as { data: { id: string } }).data.id, v, null);
    return created;
  },
  update: async (r, v) => {
    const before = ITEMS.toForm(r);
    const diff = changed(before, v, [
      'name',
      'itemType',
      'lotControlled',
      'inspectionRequired',
      'incomingReleaseRequired',
      'drawingNo',
      'drawingRev',
      'status',
    ]);
    if (Object.keys(diff).length) {
      const body: Record<string, unknown> = { ...diff };
      for (const k of ['lotControlled', 'inspectionRequired', 'incomingReleaseRequired']) if (k in diff) body[k] = yes(diff[k]);
      for (const k of ['drawingNo', 'drawingRev']) if (k in diff) body[k] = diff[k] || null;
      await call(() => api.PATCH('/core/items/{item}', { params: { path: { item: r.id } }, headers: ifMatch(r.version), body: body as never }));
    }
    if (SPEC_KEYS.some((k) => (v[k] ?? '') !== (before[k] ?? ''))) await saveSpec(r.id, v, r.specVersion);
  },
};

export const UOM_CONVERSIONS: MasterResource<ConversionRow> = {
  id: 'conversions',
  resource: 'core.uom_conversions',
  permission: PART,
  appendOnly: 'Conversion versions never change. To use another factor, add a new version from a later day; this one then ends the day before.',
  title: 'Unit Conversions',
  description: 'Item-specific factors between units, by version. A new version starts today or later and closes the current one the day before.',
  itemLabel: 'conversion versions',
  singular: 'conversion version',
  searchPlaceholder: 'Search item code or name…',
  rowLabel: (r) => `${r.code} ${r.fromUomCode} to ${r.toUomCode} from ${r.effectiveFrom}`,
  defaultSort: [
    { id: 'code', desc: false },
    { id: 'effectiveFrom', desc: true },
  ],
  filters: [
    {
      key: 'current',
      type: 'select',
      source: 'static',
      label: 'In effect',
      options: opts([
        ['true', 'In effect today'],
        ['false', 'Past or future'],
      ]),
    },
  ],
  columns: [
    { id: 'code', header: 'Item', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => r.itemName },
    {
      id: 'factor',
      header: 'Conversion',
      cell: (r) => `1 ${r.fromUomCode} = ${Number(r.factor).toLocaleString('en-US', { maximumFractionDigits: 10 })} ${r.toUomCode}`,
    },
    { id: 'effectiveFrom', header: 'In effect', sortable: true, cell: (r) => dayRange(r.effectiveFrom, r.effectiveTo) },
    { id: 'current', header: 'Today', critical: true, cell: (r) => (r.current ? 'In effect' : '—') },
  ],
  fields: [
    { key: 'itemId', label: 'Item', kind: 'select', required: true, options: 'items' },
    { key: 'fromUomId', label: 'From unit', kind: 'select', required: true, options: 'uoms' },
    { key: 'toUomId', label: 'To unit', kind: 'select', required: true, options: 'uoms' },
    {
      key: 'factor',
      label: 'Factor',
      kind: 'number',
      required: true,
      decimals: 10,
      min: 0.0000000001,
      hint: '1 from-unit equals this many to-units, e.g. 1 SHEET = 12.5 KG.',
    },
    {
      key: 'effectiveFrom',
      label: 'In effect from',
      kind: 'date',
      required: true,
      notBeforeToday: true,
      hint: 'Today or later. Past days keep the factor they had.',
    },
  ],
  blank: { itemId: '', fromUomId: '', toUomId: '', factor: '', effectiveFrom: '' },
  toForm: () => ({}),
  facts: (r) => [
    { label: 'Item', value: `${r.code} · ${r.itemName}` },
    { label: 'Conversion', value: `1 ${r.fromUomCode} = ${Number(r.factor).toLocaleString('en-US', { maximumFractionDigits: 10 })} ${r.toUomCode}` },
    { label: 'In effect', value: dayRange(r.effectiveFrom, r.effectiveTo) },
  ],
  create: (v) =>
    call(() =>
      api.POST('/core/uom-conversions', {
        body: { itemId: v.itemId!, fromUomId: v.fromUomId!, toUomId: v.toUomId!, factor: Number(v.factor), effectiveFrom: v.effectiveFrom! },
      }),
    ),
  update: async () => undefined,
};

function partner(kind: 'customers' | 'suppliers'): MasterResource<PartnerRow> {
  const singular = kind === 'customers' ? 'customer' : 'supplier';
  const config: MasterResource<PartnerRow> = {
    id: kind,
    resource: `core.${kind}`,
    permission: PART,
    title: kind === 'customers' ? 'Customers' : 'Suppliers',
    description:
      kind === 'customers'
        ? 'Companies you deliver to. Their part numbers map to your items in Customer Part Mapping.'
        : 'Companies you buy material from. Kept apart from customers: a supplier PO is never a customer PO.',
    itemLabel: kind,
    singular,
    searchPlaceholder: 'Search code or name…',
    rowLabel: (r) => `${r.code} ${r.name}`,
    defaultSort: [{ id: 'code', desc: false }],
    filters: [STATUS_FILTER],
    columns: [
      { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
      { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name, title: (r) => r.name },
      statusColumn<PartnerRow>(),
    ],
    fields: [
      {
        key: 'code',
        label: 'Code',
        kind: 'text',
        required: true,
        code: true,
        maxLength: 20,
        createOnly: true,
        codePattern: { pattern: /^[A-Z0-9][A-Z0-9._-]*$/, message: 'Use A-Z, 0-9, ".", "_" or "-", starting with a letter or digit.' },
        hint: 'Fixed once created.',
      },
      { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 200 },
      { key: 'status', label: 'Status', kind: 'select', required: true, options: STATUS_OPTIONS, editOnly: true },
    ],
    blank: { code: '', name: '', status: 'ACTIVE' },
    toForm: (r) => ({ code: r.code, name: r.name, status: r.status }),
    create: (v) =>
      kind === 'customers'
        ? call(() => api.POST('/core/customers', { body: { code: v.code!, name: v.name! } }))
        : call(() => api.POST('/core/suppliers', { body: { code: v.code!, name: v.name! } })),
    update: (r, v) => {
      const body = changed(config.toForm(r), v, ['name', 'status']) as { name?: string; status?: Status };
      return kind === 'customers'
        ? call(() => api.PATCH('/core/customers/{customer}', { params: { path: { customer: r.id } }, headers: ifMatch(r.version), body }))
        : call(() => api.PATCH('/core/suppliers/{supplier}', { params: { path: { supplier: r.id } }, headers: ifMatch(r.version), body }));
    },
  };
  return config;
}
export const CUSTOMERS = partner('customers');
export const SUPPLIERS = partner('suppliers');

const CUSTOMER_FILTER: FilterField = {
  key: 'customerId',
  type: 'entity',
  entity: 'customer',
  label: 'Customer',
  loadOptions: (_deps, signal) => queryOptions('core.customers', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
};

export const CUSTOMER_ITEMS: MasterResource<CustomerItemRow> = {
  id: 'customer-parts',
  resource: 'core.customer_items',
  permission: PART,
  title: 'Customer Part Mapping',
  description:
    'Which of your items a customer part number means. A part number points to one item on any day; an engineering change is a new mapping from a later day.',
  itemLabel: 'mappings',
  singular: 'mapping',
  searchPlaceholder: 'Search customer part number or item…',
  rowLabel: (r) => `${r.customerCode} ${r.code} to ${r.itemCode}`,
  defaultSort: [
    { id: 'customerCode', desc: false },
    { id: 'code', desc: false },
    { id: 'effectiveFrom', desc: true },
  ],
  filters: [
    CUSTOMER_FILTER,
    {
      key: 'current',
      type: 'select',
      source: 'static',
      label: 'In effect',
      options: opts([
        ['true', 'In effect today'],
        ['false', 'Past or future'],
      ]),
    },
    STATUS_FILTER,
  ],
  columns: [
    {
      id: 'code',
      header: 'Customer part',
      lead: true,
      sortable: true,
      cell: (r) => <span className="font-mono">{r.code}</span>,
      sub: (r) => (r.customerDrawingRev ? `Rev ${r.customerDrawingRev}` : undefined),
    },
    { id: 'customerCode', header: 'Customer', sortable: true, cell: (r) => r.customerCode },
    { id: 'itemCode', header: 'Item', sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    { id: 'effectiveFrom', header: 'In effect', sortable: true, cell: (r) => dayRange(r.effectiveFrom, r.effectiveTo) },
    statusColumn<CustomerItemRow>(),
  ],
  fields: [
    { key: 'customerId', label: 'Customer', kind: 'select', required: true, options: 'customers', createOnly: true },
    {
      key: 'customerPartNo',
      label: 'Customer part number',
      kind: 'text',
      required: true,
      maxLength: 60,
      createOnly: true,
      hint: "Exactly as on the customer's orders.",
    },
    { key: 'itemId', label: 'Item', kind: 'select', required: true, options: 'items', createOnly: true },
    {
      key: 'effectiveFrom',
      label: 'In effect from',
      kind: 'date',
      required: true,
      createOnly: true,
      hint: 'An open mapping of the same part number ends the day before.',
    },
    { key: 'customerDrawingRev', label: 'Customer drawing revision', kind: 'text', maxLength: 20 },
    { key: 'status', label: 'Status', kind: 'select', required: true, options: STATUS_OPTIONS, editOnly: true },
  ],
  blank: { customerId: '', customerPartNo: '', itemId: '', effectiveFrom: '', customerDrawingRev: '', status: 'ACTIVE' },
  toForm: (r) => ({ customerDrawingRev: r.customerDrawingRev ?? '', status: r.status }),
  facts: (r) => [
    { label: 'Customer', value: r.customerCode },
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
    { label: 'In effect', value: dayRange(r.effectiveFrom, r.effectiveTo) },
  ],
  create: (v) =>
    call(() =>
      api.POST('/core/customer-items', {
        body: {
          customerId: v.customerId!,
          itemId: v.itemId!,
          customerPartNo: v.customerPartNo!,
          customerDrawingRev: v.customerDrawingRev || null,
          effectiveFrom: v.effectiveFrom!,
        },
      }),
    ),
  update: (r, v) => {
    const diff = changed(CUSTOMER_ITEMS.toForm(r), v, ['customerDrawingRev', 'status']);
    const body: Record<string, unknown> = { ...diff };
    if ('customerDrawingRev' in diff) body.customerDrawingRev = diff.customerDrawingRev || null;
    return call(() =>
      api.PATCH('/core/customer-items/{customerItem}', { params: { path: { customerItem: r.id } }, headers: ifMatch(r.version), body: body as never }),
    );
  },
};

export const DIE_ITEMS: MasterResource<DieItemRow> = {
  id: 'die-parts',
  resource: 'core.die_items',
  permission: ORG,
  title: 'Die parts',
  description: 'Which parts each die makes and how many per stroke. Family dies make several parts; a part may have alternative dies.',
  itemLabel: 'die parts',
  singular: 'die part',
  searchPlaceholder: 'Search die or item…',
  rowLabel: (r) => `${r.code} makes ${r.itemCode}`,
  defaultSort: [
    { id: 'code', desc: false },
    { id: 'itemCode', desc: false },
  ],
  filters: [STATUS_FILTER],
  columns: [
    { id: 'code', header: 'Die', lead: true, sortable: true, cell: (r) => r.code, sub: (r) => r.dieName },
    { id: 'plantCode', header: 'Plant', sortable: true, cell: (r) => r.plantCode },
    { id: 'itemCode', header: 'Item', sortable: true, cell: (r) => <span className="font-mono">{r.itemCode}</span>, sub: (r) => r.itemName },
    { id: 'partsPerStroke', header: 'Parts per stroke', numeric: true, sortable: true, cell: (r) => r.partsPerStroke },
    statusColumn<DieItemRow>(),
  ],
  fields: [
    { key: 'dieId', label: 'Die', kind: 'select', required: true, options: 'dies', createOnly: true },
    { key: 'itemId', label: 'Item', kind: 'select', required: true, options: 'items', createOnly: true },
    { key: 'partsPerStroke', label: 'Parts per stroke', kind: 'number', required: true, min: 1, max: 1000 },
    { key: 'status', label: 'Status', kind: 'select', required: true, options: STATUS_OPTIONS, editOnly: true },
  ],
  blank: { dieId: '', itemId: '', partsPerStroke: '1', status: 'ACTIVE' },
  toForm: (r) => ({ partsPerStroke: String(r.partsPerStroke), status: r.status }),
  facts: (r) => [
    { label: 'Die', value: `${r.plantCode} / ${r.code} · ${r.dieName}` },
    { label: 'Item', value: `${r.itemCode} · ${r.itemName}` },
  ],
  create: (v) => call(() => api.POST('/core/die-items', { body: { dieId: v.dieId!, itemId: v.itemId!, partsPerStroke: Number(v.partsPerStroke) } })),
  update: (r, v) => {
    const diff = changed(DIE_ITEMS.toForm(r), v, ['partsPerStroke', 'status']);
    const body: Record<string, unknown> = { ...diff };
    if ('partsPerStroke' in diff) body.partsPerStroke = Number(diff.partsPerStroke);
    return call(() => api.PATCH('/core/die-items/{dieItem}', { params: { path: { dieItem: r.id } }, headers: ifMatch(r.version), body: body as never }));
  },
};

/** Master Data module (D-074): navigation item id → the resources it shows. */
export const PART_SCREENS: Record<string, MasterGroup> = {
  items: { title: 'Items', parts: [ITEMS as never] },
  units: { title: 'Units & Conversions', parts: [UOMS, UOM_CONVERSIONS] as never },
  customers: { title: 'Customers', parts: [CUSTOMERS as never] },
  'customer-parts': { title: 'Customer Part Mapping', parts: [CUSTOMER_ITEMS as never] },
  suppliers: { title: 'Suppliers', parts: [SUPPLIERS as never] },
};
