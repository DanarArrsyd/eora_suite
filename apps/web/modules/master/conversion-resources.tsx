import { api, call } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { DomainStatusBadge } from '@eora/features/patterns';
import type { MasterResource, MasterRow } from './types';
import type { LifecycleStatus } from './version-ui';

/*
 * Conversion data (doc 29 §1.5, doc 02, ADR-0005, D-080): net weights (NW) and conversion profiles (CP). Stored and
 * validated only — theoretical pieces and the variance indicators are calculated later (B8), so no figure here is a
 * calculation. A record's versions, inputs and validation live on its own page (conversion-screen.tsx).
 */

export const CONVERSION_PERMISSION = { view: 'master.conversion.view', create: 'master.conversion.create', label: 'conversion data' } as const;

const opts = (pairs: [string, string][]): Option[] => pairs.map(([value, label]) => ({ value, label }));
const label = (options: Option[], value: string | null | undefined) => options.find((o) => o.value === value)?.label ?? value ?? '—';

export const CONVERSION_STATUSES = opts([
  ['DRAFT', 'Draft'],
  ['SUBMITTED', 'Submitted'],
  ['REJECTED', 'Rejected'],
  ['VALIDATED', 'Validated'],
  ['RETIRED', 'Retired'],
]);
export const INPUT_FORMS = opts([
  ['COIL', 'Coil'],
  ['SHEET', 'Sheet'],
]);

type VersionFacts = {
  versionNo: number;
  status: LifecycleStatus;
  effectiveFrom: string;
  effectiveTo: string | null;
  inUse: boolean;
  changeReason: string | null;
  rejectReason: string | null;
  preparedByName: string | null;
  validatedByName: string | null;
  validatedAt: string | null;
  retiredAt: string | null;
};

export type ItemWeightRow = MasterRow & VersionFacts & { itemId: string; itemCode: string; itemName: string; itemType: string; netWeightKg: string | null };

export type ConversionProfileRow = MasterRow & {
  itemId: string;
  itemCode: string;
  itemName: string;
  inputItemId: string;
  inputItemCode: string;
  inputItemName: string;
  inputForm: 'COIL' | 'SHEET';
  status: 'ACTIVE' | 'INACTIVE';
  versionInUseId: string | null;
  versionInUseNo: number | null;
  versionCount: number;
};

export type ProfileVersionRow = MasterRow &
  VersionFacts & {
    profileId: string;
    itemId: string;
    itemCode: string;
    itemName: string;
    inputItemId: string;
    inputItemCode: string;
    inputItemName: string;
    inputForm: 'COIL' | 'SHEET';
    profileStatus: 'ACTIVE' | 'INACTIVE';
    formulaCode: string;
  };

/** Kilograms per piece, as stored (up to 6 decimals), without trailing zeros. */
export function kgPerPiece(value: string | null | undefined): string {
  if (!value) return '—';
  return `${Number(value).toLocaleString('en-US', { maximumFractionDigits: 6 })} kg/pc`;
}

const effective = (r: { effectiveFrom: string; effectiveTo: string | null }) =>
  r.effectiveTo ? `${r.effectiveFrom} to ${r.effectiveTo}` : `From ${r.effectiveFrom}`;

/** Master Data → Engineering → Conversion Data → Net weights: one row per version; a row opens the item's page. */
export const NET_WEIGHTS: MasterResource<ItemWeightRow> = {
  id: 'net-weights',
  resource: 'planning.item_weights',
  permission: CONVERSION_PERMISSION,
  title: 'Net weights',
  description:
    'The validated weight of one finished piece, used to explain consumed material by mass. Each change is a new version, validated by a second person.',
  itemLabel: 'net weight versions',
  singular: 'net weight',
  searchPlaceholder: 'Search item code or name…',
  rowLabel: (r) => `${r.itemCode} version ${r.versionNo}`,
  defaultSort: [{ id: 'itemCode', desc: false }],
  filters: [{ key: 'status', type: 'select', source: 'static', label: 'Status', options: CONVERSION_STATUSES }],
  columns: [
    { id: 'itemCode', header: 'Item', lead: true, sortable: true, cell: (r) => r.itemCode, sub: (r) => r.itemName },
    {
      id: 'versionNo',
      header: 'Version',
      sortable: true,
      cell: (r) => (
        <span className="inline-flex items-center gap-2">
          Version {r.versionNo} <DomainStatusBadge domain="version" status={r.status} />
        </span>
      ),
    },
    { id: 'netWeightKg', header: 'Net weight', numeric: true, critical: true, cell: (r) => kgPerPiece(r.netWeightKg) },
    { id: 'effectiveFrom', header: 'Effective', sortable: true, cell: effective, sub: (r) => (r.inUse ? 'In use today' : undefined) },
  ],
  fields: [
    {
      key: 'itemId',
      label: 'Item',
      kind: 'select',
      required: true,
      options: 'madeItems',
      createOnly: true,
      hint: 'A finished good or Semi-Finished Material counted in pieces.',
    },
    { key: 'netWeightKg', label: 'Net weight (kg per piece)', kind: 'number', decimals: 6, createOnly: true, hint: 'Optional now; needed before submitting.' },
    { key: 'effectiveFrom', label: 'Takes effect', kind: 'date', required: true, notBeforeToday: true, createOnly: true, hint: 'Today or later.' },
    { key: 'changeReason', label: 'Reason', kind: 'text', maxLength: 500, createOnly: true, hint: 'Optional, e.g. "Weighed 20 pieces of drawing rev C".' },
  ],
  blank: { itemId: '', netWeightKg: '', effectiveFrom: '', changeReason: '' },
  toForm: () => ({}),
  create: (v) =>
    call(() =>
      api.POST('/planning/item-weights', {
        body: {
          itemId: v.itemId!,
          effectiveFrom: v.effectiveFrom!,
          netWeightKg: v.netWeightKg ? Number(v.netWeightKg) : null,
          changeReason: v.changeReason || null,
        },
      }),
    ),
  update: () => Promise.resolve(undefined),
  openHref: (r) => `/master/conversion-data?weight=${r.itemId}&version=${r.id}`,
};

/** Master Data → Engineering → Conversion Data → Conversion profiles: one row per (part, material) pair. */
export const CONVERSION_PROFILES: MasterResource<ConversionProfileRow> = {
  id: 'profiles',
  resource: 'planning.conversion_profiles',
  permission: CONVERSION_PERMISSION,
  title: 'Conversion profiles',
  description:
    'How a coil or a sheet turns into pieces of one part: geometry, pitch or nesting, and parts per stroke or blank. Each change is a new version, validated by a second person.',
  itemLabel: 'conversion profiles',
  singular: 'conversion profile',
  searchPlaceholder: 'Search part or material code…',
  rowLabel: (r) => `${r.itemCode} from ${r.inputItemCode}`,
  defaultSort: [{ id: 'itemCode', desc: false }],
  filters: [
    { key: 'inputForm', type: 'select', source: 'static', label: 'Material form', options: INPUT_FORMS },
    {
      key: 'status',
      type: 'select',
      source: 'static',
      label: 'Status',
      options: opts([
        ['ACTIVE', 'Active'],
        ['INACTIVE', 'Inactive'],
      ]),
    },
  ],
  columns: [
    { id: 'itemCode', header: 'Part', lead: true, sortable: true, cell: (r) => r.itemCode, sub: (r) => r.itemName },
    { id: 'inputItemCode', header: 'Material', sortable: true, cell: (r) => r.inputItemCode, sub: (r) => r.inputItemName },
    { id: 'inputForm', header: 'Form', cell: (r) => label(INPUT_FORMS, r.inputForm) },
    {
      id: 'inUse',
      header: 'Version in use',
      cell: (r) => (r.versionInUseNo ? `Version ${r.versionInUseNo}` : 'None validated yet'),
      sub: (r) => `${r.versionCount} ${r.versionCount === 1 ? 'version' : 'versions'}`,
    },
    { id: 'status', header: 'Status', critical: true, cell: (r) => <DomainStatusBadge domain="master" status={r.status} /> },
  ],
  fields: [
    { key: 'itemId', label: 'Part', kind: 'select', required: true, options: 'madeItems', createOnly: true, hint: 'The piece that is made.' },
    {
      key: 'inputItemId',
      label: 'Material',
      kind: 'select',
      required: true,
      options: 'materials',
      createOnly: true,
      hint: 'Kept in kg → coil formula; counted in sheets → sheet formula.',
    },
    {
      key: 'effectiveFrom',
      label: 'Version 1 takes effect',
      kind: 'date',
      required: true,
      notBeforeToday: true,
      createOnly: true,
      hint: 'Today or later. Thickness, width and density come from the material spec; the rest is entered on the next page.',
    },
    { key: 'changeReason', label: 'Reason', kind: 'text', maxLength: 500, createOnly: true, hint: 'Optional, e.g. "New die D-100".' },
  ],
  blank: { itemId: '', inputItemId: '', effectiveFrom: '', changeReason: '' },
  toForm: () => ({}),
  create: (v) =>
    call(() =>
      api.POST('/planning/conversion-profile-versions', {
        body: { itemId: v.itemId!, inputItemId: v.inputItemId!, effectiveFrom: v.effectiveFrom!, changeReason: v.changeReason || null },
      }),
    ),
  update: () => Promise.resolve(undefined),
  // a list row is the profile; a create response is its first version (carrying profileId)
  openHref: (r) => `/master/conversion-data?profile=${(r as { profileId?: string }).profileId ?? r.id}`,
};
