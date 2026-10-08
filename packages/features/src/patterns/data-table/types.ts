import type * as React from 'react';
import type { LucideIcon } from 'lucide-react';

/**
 * Column definition for EoraDataTable. Modules describe columns with this type only; the TanStack Table
 * integration stays inside the pattern (swappable without touching modules).
 */
export type EoraColumn<T> = {
  id: string;
  header: string;
  /** Header text for screen readers only (e.g. actions). */
  hideHeader?: boolean;
  cell: (row: T) => React.ReactNode;
  /** Secondary sub-line (12 px). Hidden in Compact density — reachable via the detail drawer (D-029). */
  sub?: (row: T) => React.ReactNode;
  /** Full text for single-line truncated cells (`title` + ellipsis). */
  title?: (row: T) => string | undefined;
  align?: 'start' | 'end';
  /** Right-aligned tabular numerals. */
  numeric?: boolean;
  sortable?: boolean;
  /** Never hidden by density or column settings (quantity + UOM, critical status). */
  critical?: boolean;
  hideable?: boolean;
  /** Grouped header label id (two-row header). Adjacent columns with the same group share one group cell. */
  group?: string;
  /** Identity column: pinned to the start and used as the card title on phones. */
  lead?: boolean;
  /** Single-line text column, 200–280 px with ellipsis. */
  textCol?: boolean;
  minWidth?: number;
  /** Phone cards hide the cell when this returns true (e.g. zero quantity buckets). */
  emptyOnPhone?: (row: T) => boolean;
};

export type ColumnGroup = { id: string; label: React.ReactNode; title?: string };

export type RowAction<T> = {
  id: string;
  label: string;
  icon?: LucideIcon;
  onSelect: (row: T) => void;
  /** Visible reason when the action is unavailable (e.g. "Requires Inventory Controller role"). */
  disabledReason?: string;
};

export type BulkAction = { id: string; label: string; icon?: LucideIcon; onSelect: (ids: string[]) => void; disabledReason?: string };

/** Selected row ids (absent = not selected). */
export type RowSelection = Record<string, true>;
export type ColumnVisibility = Record<string, boolean>;
