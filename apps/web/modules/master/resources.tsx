import { api, call } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DomainStatusBadge, type FilterField } from '@eora/features/patterns';
import { queryOptions } from './source';
import type { MasterGroup } from './master-screen';
import type { FormValues, MasterResource, MasterRow } from './types';

/*
 * Organisation master (doc 29 §1.1, doc 26 §2.1, doc 24 §5.2): one config per query resource. Labels follow the
 * business vocabulary (Material at Production, never WIP); codes are fixed once created.
 */

type Status = 'ACTIVE' | 'INACTIVE';
export type CompanyRow = MasterRow & { legalName: string; updatedAt: string };
export type PlantRow = MasterRow & { name: string; timezone: string; companyId: string; companyCode: string; companyName: string; updatedAt: string };
export type WarehouseRow = MasterRow & {
  name: string;
  warehouseType: string;
  status: Status;
  plantId: string;
  plantCode: string;
  locationCount: number;
  updatedAt: string;
};
export type LocationRow = MasterRow & {
  name: string;
  locationType: string;
  virtual: boolean;
  status: Status;
  plantId: string;
  plantCode: string;
  warehouseId: string | null;
  warehouseCode: string | null;
  workCenterId: string | null;
  workCenterCode: string | null;
  updatedAt: string;
};
export type WorkCenterRow = MasterRow & {
  name: string;
  workCenterType: string;
  availableHoursPerShift: string | null;
  status: Status;
  plantId: string;
  plantCode: string;
  matAtProdLocationCode: string | null;
  machineCount: number;
  updatedAt: string;
};
export type MachineRow = MasterRow & {
  name: string;
  strokeCounter: boolean;
  status: Status;
  workCenterId: string;
  workCenterCode: string;
  plantId: string;
  plantCode: string;
  updatedAt: string;
};
export type DieRow = MasterRow & {
  name: string;
  dieType: string;
  cavities: number;
  strokeLife: string | null;
  currentStrokes: string;
  status: 'ACTIVE' | 'INACTIVE' | 'MAINTENANCE';
  plantId: string;
  plantCode: string;
  updatedAt: string;
};

const opts = (pairs: [string, string][]): Option[] => pairs.map(([value, label]) => ({ value, label }));
export const STATUS_OPTIONS = opts([
  ['ACTIVE', 'Active'],
  ['INACTIVE', 'Inactive'],
]);
const WAREHOUSE_TYPES = opts([
  ['RAW_MATERIAL', 'Raw material'],
  ['FINISHED_GOODS', 'Finished goods'],
  ['GENERAL', 'General'],
]);
/** ADR-0002 §3. Users create physical locations inside a warehouse; Material at Production comes with its work center. */
const LOCATION_TYPES = opts([
  ['RM_WAREHOUSE', 'Raw material storage'],
  ['FG_WAREHOUSE', 'Finished goods storage'],
  ['QC_AREA', 'QC area'],
  ['NG_AREA', 'NG area'],
  ['SCRAP_YARD', 'Scrap yard'],
  ['STAGING', 'Staging'],
  ['CUSTODY_TRANSIT', 'Custody transit'],
]);
const ALL_LOCATION_TYPES = [
  ...LOCATION_TYPES,
  ...opts([
    ['MAT_AT_PROD', 'Material at Production'],
    ['V_SUPPLIER', 'Supplier (virtual)'],
    ['V_CUSTOMER', 'Customer (virtual)'],
    ['V_CONSUMED', 'Consumed (virtual)'],
    ['V_PRODUCED', 'Produced (virtual)'],
    ['V_ADJUSTMENT', 'Adjustment (virtual)'],
    ['V_OPENING', 'Opening balance (virtual)'],
  ]),
];
const DIE_TYPES = opts([
  ['DIE', 'Die'],
  ['JIG', 'Jig'],
  ['FIXTURE', 'Fixture'],
]);
/** IANA zones known to the browser (the server checks them against its own list). */
const TIME_ZONES: Option[] = (typeof Intl.supportedValuesOf === 'function' ? Intl.supportedValuesOf('timeZone') : ['Asia/Jakarta']).map((z) => ({
  value: z,
  label: z.replaceAll('_', ' '),
}));
const DIE_STATUS = [...STATUS_OPTIONS, { value: 'MAINTENANCE', label: 'In maintenance' }];
const label = (options: Option[], value: string | null | undefined) => options.find((o) => o.value === value)?.label ?? value ?? '—';

export const PLANT_FILTER: FilterField = {
  key: 'plantId',
  type: 'entity',
  entity: 'plant',
  label: 'Plant',
  loadOptions: (_deps, signal) => queryOptions('core.plants', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
};
const STATUS_FILTER: FilterField = { key: 'status', type: 'select', source: 'static', label: 'Status', options: STATUS_OPTIONS };

const statusColumn = <T extends MasterRow & { status: string }>() => ({
  id: 'status',
  header: 'Status',
  critical: true,
  sortable: true,
  cell: (r: T) => <DomainStatusBadge domain="master" status={r.status as 'ACTIVE'} />,
});
const plantColumn = <T extends { plantCode: string }>() => ({ id: 'plantCode', header: 'Plant', sortable: true, cell: (r: T) => r.plantCode });

const ifMatch = (row: MasterRow) => ({ 'If-Match': `"${row.version}"` });
/** Only the keys the form changed (and non-empty) go to the server. */
function changed(before: FormValues, after: FormValues, keys: string[]): Record<string, string> {
  return Object.fromEntries(keys.filter((k) => (after[k] ?? '') !== (before[k] ?? '')).map((k) => [k, after[k] ?? '']));
}

const CODE_HINT = 'Fixed once created. A-Z, 0-9, "_" or "-".';

export const COMPANIES: MasterResource<CompanyRow> = {
  id: 'company',
  resource: 'core.companies',
  title: 'Company',
  description: 'Legal entities of this workspace. Plants belong to one company.',
  itemLabel: 'companies',
  singular: 'company',
  searchPlaceholder: 'Search code or legal name…',
  rowLabel: (r) => `${r.code} ${r.legalName}`,
  defaultSort: [{ id: 'code', desc: false }],
  filters: [],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
    { id: 'legalName', header: 'Legal name', textCol: true, sortable: true, cell: (r) => r.legalName, title: (r) => r.legalName },
  ],
  fields: [
    { key: 'code', label: 'Code', kind: 'text', required: true, code: true, maxLength: 20, createOnly: true, hint: CODE_HINT },
    { key: 'legalName', label: 'Legal name', kind: 'text', required: true, maxLength: 200 },
  ],
  blank: { code: '', legalName: '' },
  toForm: (r) => ({ code: r.code, legalName: r.legalName }),
  create: (v) => call(() => api.POST('/core/companies', { body: { code: v.code!, legalName: v.legalName! } })),
  update: (r, v) =>
    call(() => api.PATCH('/core/companies/{company}', { params: { path: { company: r.id } }, headers: ifMatch(r), body: { legalName: v.legalName! } })),
};

export const PLANTS: MasterResource<PlantRow> = {
  id: 'plants',
  resource: 'core.plants',
  title: 'Plants',
  description: 'Production sites in your scope. Each plant gets its virtual ledger locations automatically.',
  itemLabel: 'plants',
  singular: 'plant',
  searchPlaceholder: 'Search code or name…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [{ id: 'code', desc: false }],
  filters: [],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name, title: (r) => r.name },
    { id: 'companyCode', header: 'Company', sortable: true, cell: (r) => r.companyCode, sub: (r) => r.companyName },
    { id: 'timezone', header: 'Time zone', cell: (r) => r.timezone },
  ],
  fields: [
    { key: 'companyId', label: 'Company', kind: 'select', required: true, options: 'companies', createOnly: true },
    { key: 'code', label: 'Code', kind: 'text', required: true, code: true, maxLength: 20, createOnly: true, hint: CODE_HINT },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 200 },
    {
      key: 'timezone',
      label: 'Time zone',
      kind: 'select',
      required: true,
      options: TIME_ZONES,
      createOnly: true,
      hint: 'Fixed once created: production dates and planning weeks of this plant follow it.',
    },
  ],
  blank: { companyId: '', code: '', name: '', timezone: '' },
  toForm: (r) => ({ companyId: r.companyId, code: r.code, name: r.name }),
  facts: (r) => [
    { label: 'Company', value: `${r.companyCode} · ${r.companyName}` },
    { label: 'Time zone', value: r.timezone },
  ],
  create: (v) => call(() => api.POST('/core/plants', { body: { companyId: v.companyId!, code: v.code!, name: v.name!, timezone: v.timezone as never } })),
  update: (r, v) => call(() => api.PATCH('/core/plants/{plant}', { params: { path: { plant: r.id } }, headers: ifMatch(r), body: { name: v.name! } })),
};

export const WAREHOUSES: MasterResource<WarehouseRow> = {
  id: 'warehouses',
  resource: 'core.warehouses',
  title: 'Warehouses',
  description: 'Storage areas per plant. Locations are created inside an active warehouse.',
  itemLabel: 'warehouses',
  singular: 'warehouse',
  searchPlaceholder: 'Search code or name…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [
    { id: 'plantCode', desc: false },
    { id: 'code', desc: false },
  ],
  filters: [PLANT_FILTER, { key: 'warehouseType', type: 'select', source: 'static', label: 'Type', options: WAREHOUSE_TYPES }, STATUS_FILTER],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name, title: (r) => r.name },
    plantColumn<WarehouseRow>(),
    { id: 'warehouseType', header: 'Type', sortable: true, cell: (r) => label(WAREHOUSE_TYPES, r.warehouseType) },
    { id: 'locationCount', header: 'Locations', numeric: true, cell: (r) => r.locationCount },
    statusColumn<WarehouseRow>(),
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants', createOnly: true },
    { key: 'code', label: 'Code', kind: 'text', required: true, code: true, maxLength: 20, createOnly: true, hint: CODE_HINT },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 200 },
    { key: 'warehouseType', label: 'Type', kind: 'select', required: true, options: WAREHOUSE_TYPES },
    {
      key: 'status',
      label: 'Status',
      kind: 'select',
      required: true,
      options: STATUS_OPTIONS,
      editOnly: true,
      hint: 'An inactive warehouse takes no new locations.',
    },
  ],
  blank: { plantId: '', code: '', name: '', warehouseType: '', status: 'ACTIVE' },
  toForm: (r) => ({ plantId: r.plantId, code: r.code, name: r.name, warehouseType: r.warehouseType, status: r.status }),
  facts: (r) => [{ label: 'Plant', value: r.plantCode }],
  create: (v) =>
    call(() => api.POST('/core/warehouses', { body: { plantId: v.plantId!, code: v.code!, name: v.name!, warehouseType: v.warehouseType as 'GENERAL' } })),
  update: (r, v) =>
    call(() =>
      api.PATCH('/core/warehouses/{warehouse}', {
        params: { path: { warehouse: r.id } },
        headers: ifMatch(r),
        body: changed(WAREHOUSES.toForm(r), v, ['name', 'warehouseType', 'status']) as { name?: string; warehouseType?: 'GENERAL'; status?: Status },
      }),
    ),
};

export const LOCATIONS: MasterResource<LocationRow> = {
  id: 'locations',
  resource: 'core.locations',
  title: 'Locations',
  description:
    'Where material is kept. Storage locations sit inside a warehouse; each work center has its Material at Production location; virtual locations are the ledger counterparties of every plant.',
  itemLabel: 'locations',
  singular: 'location',
  searchPlaceholder: 'Search code or name…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [
    { id: 'plantCode', desc: false },
    { id: 'code', desc: false },
  ],
  filters: [
    PLANT_FILTER,
    { key: 'locationType', type: 'multiselect', source: 'static', label: 'Location type', options: ALL_LOCATION_TYPES },
    {
      key: 'virtual',
      type: 'select',
      source: 'static',
      label: 'Kind',
      options: opts([
        ['false', 'Physical'],
        ['true', 'Virtual'],
      ]),
    },
    STATUS_FILTER,
  ],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span> },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name, title: (r) => r.name },
    plantColumn<LocationRow>(),
    { id: 'locationType', header: 'Type', sortable: true, cell: (r) => label(ALL_LOCATION_TYPES, r.locationType) },
    {
      id: 'parent',
      header: 'Belongs to',
      cell: (r) => (r.warehouseCode ? `Warehouse ${r.warehouseCode}` : r.workCenterCode ? `Work center ${r.workCenterCode}` : 'Plant (virtual)'),
    },
    statusColumn<LocationRow>(),
  ],
  fields: [
    { key: 'warehouseId', label: 'Warehouse', kind: 'select', required: true, options: 'warehouses', createOnly: true },
    { key: 'code', label: 'Code', kind: 'text', required: true, code: true, maxLength: 20, createOnly: true, hint: `${CODE_HINT} V_ and MAP- are reserved.` },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 200 },
    { key: 'locationType', label: 'Type', kind: 'select', required: true, options: LOCATION_TYPES, createOnly: true },
    { key: 'status', label: 'Status', kind: 'select', required: true, options: STATUS_OPTIONS, editOnly: true },
  ],
  blank: { warehouseId: '', code: '', name: '', locationType: '', status: 'ACTIVE' },
  toForm: (r) => ({ warehouseId: r.warehouseId ?? '', code: r.code, name: r.name, locationType: r.locationType, status: r.status }),
  facts: (r) => [
    { label: 'Plant', value: r.plantCode },
    { label: 'Type', value: label(ALL_LOCATION_TYPES, r.locationType) },
    { label: 'Belongs to', value: r.warehouseCode ? `Warehouse ${r.warehouseCode}` : r.workCenterCode ? `Work center ${r.workCenterCode}` : 'Plant (virtual)' },
  ],
  readOnly: (r) =>
    r.virtual
      ? 'Virtual locations are part of every plant. They never hold stock and cannot be renamed or deactivated.'
      : r.locationType === 'MAT_AT_PROD'
        ? 'This Material at Production location belongs to its work center and follows it.'
        : undefined,
  create: (v) =>
    call(() =>
      api.POST('/core/locations', {
        body: { warehouseId: v.warehouseId!, code: v.code!, name: v.name!, locationType: v.locationType as 'STAGING' },
      }),
    ),
  update: (r, v) =>
    call(() =>
      api.PATCH('/core/locations/{location}', {
        params: { path: { location: r.id } },
        headers: ifMatch(r),
        body: changed(LOCATIONS.toForm(r), v, ['name', 'status']) as { name?: string; status?: Status },
      }),
    ),
};

export const WORK_CENTERS: MasterResource<WorkCenterRow> = {
  id: 'work-centers',
  resource: 'core.work_centers',
  title: 'Work Centers',
  description: 'Production lines and cells. Each work center has one Material at Production location for material issued to it.',
  itemLabel: 'work centers',
  singular: 'work center',
  searchPlaceholder: 'Search code or name…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [
    { id: 'plantCode', desc: false },
    { id: 'code', desc: false },
  ],
  filters: [PLANT_FILTER, STATUS_FILTER],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name, title: (r) => r.name },
    plantColumn<WorkCenterRow>(),
    { id: 'workCenterType', header: 'Type', sortable: true, cell: (r) => r.workCenterType },
    {
      id: 'capacity',
      header: 'Hours per shift',
      numeric: true,
      cell: (r) => (r.availableHoursPerShift ? `${Number(r.availableHoursPerShift).toFixed(2)} h` : 'Not configured'),
    },
    { id: 'machineCount', header: 'Machines', numeric: true, cell: (r) => r.machineCount },
    { id: 'matAtProd', header: 'Material at Production', cell: (r) => <span className="font-mono">{r.matAtProdLocationCode ?? '—'}</span> },
    statusColumn<WorkCenterRow>(),
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants', createOnly: true },
    {
      key: 'code',
      label: 'Code',
      kind: 'text',
      required: true,
      code: true,
      maxLength: 16,
      createOnly: true,
      hint: 'Fixed once created; up to 16 characters. Its location is MAP-<code>.',
    },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 200 },
    { key: 'workCenterType', label: 'Type code', kind: 'text', required: true, code: true, maxLength: 32, hint: 'Your own code, e.g. PRESS or WELDING.' },
    {
      key: 'availableHoursPerShift',
      label: 'Available hours per shift',
      kind: 'number',
      decimals: 2,
      min: 0.01,
      max: 24,
      hint: 'Leave empty when not configured. Capacity only produces warnings.',
    },
    {
      key: 'status',
      label: 'Status',
      kind: 'select',
      required: true,
      options: STATUS_OPTIONS,
      editOnly: true,
      hint: 'An inactive work center takes no new machines.',
    },
  ],
  blank: { plantId: '', code: '', name: '', workCenterType: '', availableHoursPerShift: '', status: 'ACTIVE' },
  toForm: (r) => ({
    plantId: r.plantId,
    code: r.code,
    name: r.name,
    workCenterType: r.workCenterType,
    availableHoursPerShift: r.availableHoursPerShift ? String(Number(r.availableHoursPerShift)) : '',
    status: r.status,
  }),
  facts: (r) => [
    { label: 'Plant', value: r.plantCode },
    { label: 'Material at Production', value: <span className="font-mono">{r.matAtProdLocationCode ?? '—'}</span> },
    { label: 'Machines', value: r.machineCount },
  ],
  create: (v) =>
    call(() =>
      api.POST('/core/work-centers', {
        body: {
          plantId: v.plantId!,
          code: v.code!,
          name: v.name!,
          workCenterType: v.workCenterType!,
          availableHoursPerShift: v.availableHoursPerShift ? Number(v.availableHoursPerShift) : null,
        },
      }),
    ),
  update: (r, v) => {
    const diff = changed(WORK_CENTERS.toForm(r), v, ['name', 'workCenterType', 'availableHoursPerShift', 'status']);
    const body: Record<string, unknown> = { ...diff };
    if ('availableHoursPerShift' in diff) body.availableHoursPerShift = diff.availableHoursPerShift ? Number(diff.availableHoursPerShift) : null;
    return call(() => api.PATCH('/core/work-centers/{workCenter}', { params: { path: { workCenter: r.id } }, headers: ifMatch(r), body: body as never }));
  },
};

export const MACHINES: MasterResource<MachineRow> = {
  id: 'machines',
  resource: 'core.machines',
  title: 'Machines',
  description: 'Machines belong to one work center of their plant at a time. Moving a machine is recorded in the audit trail.',
  itemLabel: 'machines',
  singular: 'machine',
  searchPlaceholder: 'Search code or name…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [
    { id: 'plantCode', desc: false },
    { id: 'code', desc: false },
  ],
  filters: [PLANT_FILTER, STATUS_FILTER],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name, title: (r) => r.name },
    plantColumn<MachineRow>(),
    { id: 'workCenterCode', header: 'Work center', sortable: true, cell: (r) => r.workCenterCode },
    { id: 'strokeCounter', header: 'Stroke counter', cell: (r) => (r.strokeCounter ? 'Yes' : 'No') },
    statusColumn<MachineRow>(),
  ],
  fields: [
    { key: 'workCenterId', label: 'Work center', kind: 'select', required: true, options: 'workCenters', hint: 'Only active work centers of the same plant.' },
    { key: 'code', label: 'Code', kind: 'text', required: true, code: true, maxLength: 20, createOnly: true, hint: CODE_HINT },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 200 },
    {
      key: 'strokeCounter',
      label: 'Stroke counter',
      kind: 'select',
      required: true,
      options: opts([
        ['false', 'No'],
        ['true', 'Yes — reports stroke readings'],
      ]),
    },
    { key: 'status', label: 'Status', kind: 'select', required: true, options: STATUS_OPTIONS, editOnly: true },
  ],
  blank: { workCenterId: '', code: '', name: '', strokeCounter: 'false', status: 'ACTIVE' },
  toForm: (r) => ({ workCenterId: r.workCenterId, code: r.code, name: r.name, strokeCounter: String(r.strokeCounter), status: r.status }),
  facts: (r) => [{ label: 'Plant', value: r.plantCode }],
  create: (v) =>
    call(() =>
      api.POST('/core/machines', { body: { workCenterId: v.workCenterId!, code: v.code!, name: v.name!, strokeCounter: v.strokeCounter === 'true' } }),
    ),
  update: (r, v) => {
    const diff = changed(MACHINES.toForm(r), v, ['workCenterId', 'name', 'strokeCounter', 'status']);
    const body: Record<string, unknown> = { ...diff };
    if ('strokeCounter' in diff) body.strokeCounter = diff.strokeCounter === 'true';
    return call(() => api.PATCH('/core/machines/{machine}', { params: { path: { machine: r.id } }, headers: ifMatch(r), body: body as never }));
  },
};

export const DIES: MasterResource<DieRow> = {
  id: 'dies',
  resource: 'core.dies',
  title: 'Dies & Tooling',
  description: 'Dies, jigs and fixtures per plant with cavities and stroke life. Codes are unique across the workspace.',
  itemLabel: 'tools',
  singular: 'tool',
  searchPlaceholder: 'Search code or name…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [{ id: 'code', desc: false }],
  filters: [
    PLANT_FILTER,
    { key: 'dieType', type: 'select', source: 'static', label: 'Type', options: DIE_TYPES },
    { key: 'status', type: 'select', source: 'static', label: 'Status', options: DIE_STATUS },
  ],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name, title: (r) => r.name },
    plantColumn<DieRow>(),
    { id: 'dieType', header: 'Type', sortable: true, cell: (r) => label(DIE_TYPES, r.dieType) },
    { id: 'cavities', header: 'Cavities', numeric: true, sortable: true, cell: (r) => r.cavities },
    {
      id: 'strokes',
      header: 'Strokes used',
      numeric: true,
      cell: (r) =>
        r.strokeLife
          ? `${Number(r.currentStrokes).toLocaleString('en-US')} of ${Number(r.strokeLife).toLocaleString('en-US')}`
          : Number(r.currentStrokes).toLocaleString('en-US'),
      sub: (r) => (r.strokeLife ? undefined : 'Stroke life not set'),
    },
    { id: 'status', header: 'Status', critical: true, sortable: true, cell: (r) => <DomainStatusBadge domain="master" status={r.status} /> },
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants', hint: 'Moving a tool to another plant needs rights in both plants.' },
    { key: 'code', label: 'Code', kind: 'text', required: true, code: true, maxLength: 20, createOnly: true, hint: CODE_HINT },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 200 },
    { key: 'dieType', label: 'Type', kind: 'select', required: true, options: DIE_TYPES },
    { key: 'cavities', label: 'Cavities', kind: 'number', required: true, min: 1, max: 1000 },
    { key: 'strokeLife', label: 'Stroke life', kind: 'number', min: 1, hint: 'Leave empty when unknown.' },
    { key: 'currentStrokes', label: 'Current strokes', kind: 'number', required: true, min: 0 },
    { key: 'status', label: 'Status', kind: 'select', required: true, options: DIE_STATUS, editOnly: true },
  ],
  blank: { plantId: '', code: '', name: '', dieType: '', cavities: '1', strokeLife: '', currentStrokes: '0', status: 'ACTIVE' },
  toForm: (r) => ({
    plantId: r.plantId,
    code: r.code,
    name: r.name,
    dieType: r.dieType,
    cavities: String(r.cavities),
    strokeLife: r.strokeLife ?? '',
    currentStrokes: r.currentStrokes,
    status: r.status,
  }),
  create: (v) =>
    call(() =>
      api.POST('/core/dies', {
        body: {
          plantId: v.plantId!,
          code: v.code!,
          name: v.name!,
          dieType: v.dieType as 'DIE',
          cavities: Number(v.cavities),
          strokeLife: v.strokeLife ? Number(v.strokeLife) : null,
          currentStrokes: Number(v.currentStrokes),
        },
      }),
    ),
  update: (r, v) => {
    const diff = changed(DIES.toForm(r), v, ['plantId', 'name', 'dieType', 'cavities', 'strokeLife', 'currentStrokes', 'status']);
    const body: Record<string, unknown> = { ...diff };
    for (const k of ['cavities', 'currentStrokes'] as const) if (k in diff) body[k] = Number(diff[k]);
    if ('strokeLife' in diff) body.strokeLife = diff.strokeLife ? Number(diff.strokeLife) : null;
    return call(() => api.PATCH('/core/dies/{die}', { params: { path: { die: r.id } }, headers: ifMatch(r), body: body as never }));
  },
};

/** Approved navigation items (Administration → Organization) and the resources each one shows. */
export const MASTER_SCREENS: Record<string, MasterGroup> = {
  company: { title: 'Company', parts: [COMPANIES as never] },
  plants: { title: 'Plants & Warehouses', parts: [PLANTS, WAREHOUSES, LOCATIONS] as never },
  'work-centers': { title: 'Work Centers', parts: [WORK_CENTERS, MACHINES, DIES] as never },
};
