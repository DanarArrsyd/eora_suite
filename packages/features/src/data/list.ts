/**
 * List-resource contract shared by FilterBar, DataTable, Pagination and ExportMenu (ADR-0008 QueryDefinition).
 * Real implementations call GET /api/v1/query/{resource}; Phase 2 uses typed mock adapters (doc 22 §2).
 * Filtering, sorting and pagination are always server-side; the UI never pages through a full client dataset.
 */
export type SortRule = { id: string; desc: boolean };
export type FilterValue = string | string[];
export type FilterValues = Readonly<Record<string, FilterValue | undefined>>;

export type ListQuery = {
  page: number; // 1-based
  pageSize: number;
  sort: SortRule[];
  filters: FilterValues;
  search: string;
  view: string;
};

export type ListMeta = {
  total: number;
  /** ISO timestamp of the read model the rows come from. */
  asOf: string;
  timezone: string;
};

export type ListResult<T> = { rows: T[]; meta: ListMeta };

export type Option = { value: string; label: string };

export interface ListSource<T> {
  readonly resource: string;
  /** True for adapters that return sample data; pages must show a visible "Mock data" label. */
  readonly isMock: boolean;
  list(query: ListQuery, signal?: AbortSignal): Promise<ListResult<T>>;
  /** Counts per status-tab view for the current search/filters (tabs never invent statuses). */
  viewCounts?(query: Pick<ListQuery, 'filters' | 'search'>, signal?: AbortSignal): Promise<Record<string, number>>;
}

export type SourceErrorKind = 'network' | 'forbidden' | 'server';

/** Error raised by a source. `reference` is shown to the user for support (never a stack trace). */
export class SourceError extends Error {
  readonly kind: SourceErrorKind;
  readonly reference: string;
  constructor(message: string, kind: SourceErrorKind, reference: string) {
    super(message);
    this.name = 'SourceError';
    this.kind = kind;
    this.reference = reference;
  }
}

export function isSourceError(e: unknown): e is SourceError {
  return e instanceof SourceError;
}

export function activeFilterCount(values: FilterValues, ignore: readonly string[] = []): number {
  return Object.entries(values).filter(([k, v]) => !ignore.includes(k) && (Array.isArray(v) ? v.length > 0 : !!v)).length;
}
