'use client';

import { useQuery } from '@tanstack/react-query';
import type { FilterValues, Option } from '../../data';
import type { FilterField } from './types';

const NONE: Option[] = [];

/** Options for select/multiselect/entity fields; dependent fields re-query when their parents change. */
export function useFieldOptions(field: FilterField, values: FilterValues): { options: Option[]; loading: boolean; error: boolean } {
  const loader =
    field.type === 'entity' || ((field.type === 'select' || field.type === 'multiselect') && field.source === 'api') ? field.loadOptions : undefined;
  const dependsOn = 'dependsOn' in field ? (field.dependsOn ?? []) : [];
  const deps = Object.fromEntries(dependsOn.map((k) => [k, values[k]]));
  const q = useQuery({
    queryKey: ['filter-options', field.key, deps],
    queryFn: ({ signal }) => loader!(deps, signal),
    enabled: !!loader,
    staleTime: 5 * 60_000,
  });
  if ((field.type === 'select' || field.type === 'multiselect') && field.source === 'static') return { options: field.options, loading: false, error: false };
  return { options: q.data ?? NONE, loading: q.isLoading, error: q.isError };
}

export function optionLabel(options: Option[], value: string): string {
  return options.find((o) => o.value === value)?.label ?? value;
}
