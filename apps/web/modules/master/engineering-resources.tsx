import { api, call } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DomainStatusBadge, type FilterField } from '@eora/features/patterns';
import { STATUS_OPTIONS } from './resources';
import type { FormValues, MasterResource, MasterRow } from './types';

/*
 * Engineering master (doc 29 §1.3, doc 26 §2.3–§2.4a, D-076): the tenant's process types and the routings of each item
 * and plant. A routing's versions, operations and approvals live on its own page (routing-screen.tsx).
 */

export const ROUTING_PERMISSION = { view: 'master.routing.view', create: 'master.routing.create', label: 'routings and process types' } as const;

type Status = 'ACTIVE' | 'INACTIVE';
export type ProcessTypeRow = MasterRow & { name: string; category: string; defaultWorkCenterType: string | null; status: Status };
export type RoutingRow = MasterRow & {
  routingKind: 'PRIMARY' | 'ALTERNATE';
  status: Status;
  plantId: string;
  plantCode: string;
  itemId: string;
  itemCode: string;
  itemName: string;
  currentVersionId: string | null;
  currentVersionNo: number | null;
  currentEffectiveFrom: string | null;
  currentStandardYield: string | null;
  currentOperationCount: number | null;
  pendingVersionId: string | null;
  pendingVersionNo: number | null;
  pendingStatus: 'DRAFT' | 'SUBMITTED' | 'REJECTED' | null;
};

const opts = (pairs: [string, string][]): Option[] => pairs.map(([value, label]) => ({ value, label }));
const label = (options: Option[], value: string | null | undefined) => options.find((o) => o.value === value)?.label ?? value ?? '—';
const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });

export const PROCESS_CATEGORIES = opts([
  ['TRANSFORMATION', 'Transformation'],
  ['INSPECTION', 'Inspection'],
  ['HANDLING', 'Handling'],
]);
export const ROUTING_KINDS = opts([
  ['PRIMARY', 'Primary'],
  ['ALTERNATE', 'Alternate'],
]);
const STATUS_FILTER: FilterField = { key: 'status', type: 'select', source: 'static', label: 'Status', options: STATUS_OPTIONS };

/** Standard yield as a percentage, e.g. "0.98000" → "98 %". */
export function yieldPercent(value: string | null | undefined): string {
  if (!value) return '—';
  return `${(Number(value) * 100).toLocaleString('en-US', { maximumFractionDigits: 3 })} %`;
}

export const PROCESS_TYPES: MasterResource<ProcessTypeRow> = {
  id: 'process-types',
  resource: 'planning.process_types',
  permission: ROUTING_PERMISSION,
  title: 'Process Types',
  description: 'The kinds of work a routing operation does, such as blanking, bending or final inspection. Shared by every plant.',
  itemLabel: 'process types',
  singular: 'process type',
  searchPlaceholder: 'Search code or name…',
  rowLabel: (r) => `${r.code} ${r.name}`,
  defaultSort: [{ id: 'code', desc: false }],
  filters: [{ key: 'category', type: 'select', source: 'static', label: 'Category', options: PROCESS_CATEGORIES }, STATUS_FILTER],
  columns: [
    { id: 'code', header: 'Code', lead: true, sortable: true, cell: (r) => r.code },
    { id: 'name', header: 'Name', textCol: true, sortable: true, cell: (r) => r.name },
    { id: 'category', header: 'Category', sortable: true, cell: (r) => label(PROCESS_CATEGORIES, r.category) },
    { id: 'defaultWorkCenterType', header: 'Usual work center type', cell: (r) => r.defaultWorkCenterType ?? '—' },
    { id: 'status', header: 'Status', critical: true, sortable: true, cell: (r) => <DomainStatusBadge domain="master" status={r.status} /> },
  ],
  fields: [
    {
      key: 'code',
      label: 'Code',
      kind: 'text',
      required: true,
      code: true,
      maxLength: 32,
      createOnly: true,
      codePattern: { pattern: /^[A-Z][A-Z0-9_-]*$/, message: 'Use A-Z, 0-9, "_" or "-", starting with a letter.' },
      hint: 'Fixed once created, e.g. BLANK, BEND, INSP.',
    },
    { key: 'name', label: 'Name', kind: 'text', required: true, maxLength: 100 },
    { key: 'category', label: 'Category', kind: 'select', required: true, options: PROCESS_CATEGORIES },
    {
      key: 'defaultWorkCenterType',
      label: 'Usual work center type',
      kind: 'text',
      code: true,
      maxLength: 32,
      codePattern: { pattern: /^[A-Z][A-Z0-9_]+$/, message: 'Use A-Z, 0-9 or "_", starting with a letter.' },
      hint: 'Optional, e.g. PRESS. Suggests the work center when an operation is added.',
    },
    {
      key: 'status',
      label: 'Status',
      kind: 'select',
      required: true,
      options: STATUS_OPTIONS,
      editOnly: true,
      hint: 'Inactive types are not offered for new operations.',
    },
  ],
  blank: { code: '', name: '', category: '', defaultWorkCenterType: '' },
  toForm: (r) => ({ name: r.name, category: r.category, defaultWorkCenterType: r.defaultWorkCenterType ?? '', status: r.status }),
  create: (v) =>
    call(() =>
      api.POST('/planning/process-types', {
        body: { code: v.code!, name: v.name!, category: v.category as 'TRANSFORMATION', defaultWorkCenterType: v.defaultWorkCenterType || null },
      }),
    ),
  update: (r, v: FormValues) =>
    call(() =>
      api.PATCH('/planning/process-types/{processType}', {
        params: { path: { processType: r.id } },
        headers: ifMatch(r.version),
        body: { name: v.name, category: v.category as 'TRANSFORMATION', defaultWorkCenterType: v.defaultWorkCenterType || null, status: v.status as Status },
      }),
    ),
};

export const ROUTINGS: MasterResource<RoutingRow> = {
  id: 'routings',
  resource: 'planning.routings',
  permission: ROUTING_PERMISSION,
  title: 'Routings',
  description: 'The sequence of operations that makes an item in a plant. Each change is a new version, approved by a second person.',
  itemLabel: 'routings',
  singular: 'routing',
  searchPlaceholder: 'Search routing, item code or name…',
  rowLabel: (r) => `${r.code} ${r.itemCode}`,
  defaultSort: [{ id: 'itemCode', desc: false }],
  filters: [{ key: 'routingKind', type: 'select', source: 'static', label: 'Kind', options: ROUTING_KINDS }, STATUS_FILTER],
  columns: [
    { id: 'itemCode', header: 'Item', lead: true, sortable: true, cell: (r) => r.itemCode, sub: (r) => r.itemName },
    { id: 'code', header: 'Routing', sortable: true, cell: (r) => r.code, sub: (r) => label(ROUTING_KINDS, r.routingKind) },
    { id: 'plantCode', header: 'Plant', sortable: true, cell: (r) => r.plantCode },
    {
      id: 'current',
      header: 'Version in use',
      cell: (r) => (r.currentVersionNo ? `Version ${r.currentVersionNo}` : 'None approved yet'),
      sub: (r) =>
        r.currentVersionNo
          ? `From ${r.currentEffectiveFrom}, ${r.currentOperationCount ?? 0} operations, yield ${yieldPercent(r.currentStandardYield)}`
          : undefined,
    },
    {
      id: 'pending',
      header: 'Open version',
      cell: (r) =>
        r.pendingStatus ? (
          <span className="inline-flex items-center gap-2">
            Version {r.pendingVersionNo} <DomainStatusBadge domain="version" status={r.pendingStatus} />
          </span>
        ) : (
          '—'
        ),
    },
    { id: 'status', header: 'Status', critical: true, sortable: true, cell: (r) => <DomainStatusBadge domain="master" status={r.status} /> },
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants', createOnly: true },
    { key: 'itemId', label: 'Item', kind: 'select', required: true, options: 'items', createOnly: true },
    {
      key: 'routingCode',
      label: 'Routing code',
      kind: 'text',
      required: true,
      code: true,
      maxLength: 20,
      createOnly: true,
      hint: 'Fixed once created, e.g. R1. Unique for the item in this plant.',
    },
    {
      key: 'routingKind',
      label: 'Kind',
      kind: 'select',
      required: true,
      options: ROUTING_KINDS,
      createOnly: true,
      hint: 'An item has one active primary routing per plant; alternates cover other machines or lines.',
    },
    {
      key: 'effectiveFrom',
      label: 'Version 1 takes effect',
      kind: 'date',
      required: true,
      notBeforeToday: true,
      createOnly: true,
      hint: 'Today or later. Operations are added on the next page.',
    },
    { key: 'changeReason', label: 'Reason', kind: 'text', maxLength: 500, createOnly: true, hint: 'Optional, e.g. "New part for model K2".' },
  ],
  blank: { plantId: '', itemId: '', routingCode: '', routingKind: 'PRIMARY', effectiveFrom: '', changeReason: '' },
  toForm: () => ({}),
  create: (v) =>
    call(() =>
      api.POST('/planning/routings', {
        body: {
          plantId: v.plantId!,
          itemId: v.itemId!,
          routingCode: v.routingCode!,
          routingKind: v.routingKind as 'PRIMARY',
          effectiveFrom: v.effectiveFrom!,
          changeReason: v.changeReason || null,
        },
      }),
    ),
  update: () => Promise.resolve(undefined),
  openHref: (r) => `/master/routings?routing=${r.id}`,
};
