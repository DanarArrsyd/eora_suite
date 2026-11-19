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

export const OWNERSHIP_FILTER: FilterField = {
  key: 'ownership',
  type: 'multiselect',
  source: 'static',
  label: 'Owner',
  options: [
    { value: 'OWN', label: 'Company' },
    { value: 'CUSTOMER', label: 'Customer' },
  ],
};

/** Which customer owns the material; options come from the customer master (`master.part.view`). */
export const OWNER_CUSTOMER_FILTER: FilterField = {
  key: 'ownerCustomerId',
  type: 'entity',
  entity: 'customer',
  label: 'Owner customer',
  loadOptions: (_deps, signal) => queryOptions('core.customers', (r) => `${String(r.code)} · ${String(r.name)}`, {}, signal),
};
