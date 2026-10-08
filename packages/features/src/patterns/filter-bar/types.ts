import type { FilterValues, Option } from '../../data';

type Base = {
  key: string;
  label: string;
  /** Scope filter from the active workspace (e.g. plant): shown as a locked chip, never editable here. */
  locked?: boolean;
};

export type OptionsLoader = (deps: FilterValues, signal?: AbortSignal) => Promise<Option[]>;

/** Schema per EORA-COMPONENT-STANDARDS §4. Production schemas come from GET /api/v1/query/{resource}/schema (permission-filtered). */
export type FilterField =
  | (Base & { type: 'text' | 'search'; placeholder?: string })
  | (Base & { type: 'select' | 'multiselect'; source: 'static'; options: Option[]; dependsOn?: string[] })
  | (Base & { type: 'select' | 'multiselect'; source: 'api'; loadOptions: OptionsLoader; dependsOn?: string[] })
  | (Base & { type: 'daterange' })
  | (Base & {
      type: 'entity';
      entity: 'customer' | 'company' | 'plant' | 'warehouse' | 'material' | 'part' | 'work_order' | 'machine' | 'shift' | 'status';
      loadOptions: OptionsLoader;
      dependsOn?: string[];
    });

export type SavedView = {
  id: string;
  name: string;
  scope: 'mine' | 'shared';
  /** e.g. "PPIC" for a view shared with a role. */
  sharedWith?: string;
  filters: FilterValues;
  view?: string;
  search?: string;
};

/** Date range values travel as `from..to` (ISO dates, either side optional). */
export function parseRange(v: string | undefined): { from: string; to: string } {
  const [from = '', to = ''] = (v ?? '').split('..');
  return { from, to };
}
export function formatRange(from: string, to: string): string | undefined {
  return from || to ? `${from}..${to}` : undefined;
}
