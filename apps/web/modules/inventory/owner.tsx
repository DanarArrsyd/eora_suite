import type { FilterField } from '@eora/features/patterns';
import { queryOptions } from '@/modules/master/source';

/** Material owner of a lot (D-135): the company, or the customer that supplied it. One wording everywhere (D-136). */
export type Owned = { ownership?: 'OWN' | 'CUSTOMER'; ownerCode?: string | null };

/** Value the receipt form uses for company-purchased material; sent to the API as no customer owner. */
export const COMPANY_OWNER = 'COMPANY';

export const ownerLabel = (r: Owned) => (r.ownership === 'CUSTOMER' ? `${r.ownerCode} (customer)` : 'Company');

export const ownerColumn = <T extends Owned>() => ({
  id: 'ownerCode',
  header: 'Owner',
  cell: (r: T) => (r.ownership === 'CUSTOMER' ? (r.ownerCode ?? '—') : 'Company'),
  sub: (r: T) => (r.ownership === 'CUSTOMER' ? 'Customer' : (r.ownerCode ?? undefined)),
});

export const ownerFact = (r: Owned) => ({
  label: 'Owner',
  value: r.ownership === 'CUSTOMER' ? `${r.ownerCode} (customer)` : r.ownerCode ? `Company · ${r.ownerCode}` : 'Company',
});

/**
 * One Owner filter (UI/UX audit U-9): Company, or one customer. The list maps it to the API filters with `ownerFilters`.
 * Options come from the customer master (`master.part.view`).
 */
export const OWNER_FILTER: FilterField = {
  key: 'owner',
  type: 'select',
  source: 'api',
  label: 'Owner',
  loadOptions: async (_deps, signal) => [
    { value: COMPANY_OWNER, label: 'Company' },
    ...(await queryOptions('core.customers', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal)),
  ],
};

type Filters = Record<string, string | string[]>;

/** The Owner filter as the query API reads it: Company = `ownership=OWN`, a customer = `ownerCustomerId`. */
export function ownerFilters(filters: Filters): Filters {
  const { owner, ...rest } = filters;
  const value = Array.isArray(owner) ? owner[0] : owner;
  if (!value) return rest;
  return value === COMPANY_OWNER ? { ...rest, ownership: 'OWN' } : { ...rest, ownerCustomerId: value };
}
