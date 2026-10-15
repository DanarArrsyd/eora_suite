import type * as React from 'react';
import type { EoraColumn, FilterField } from '@eora/features/patterns';
import type { Option, SortRule } from '@eora/features/data';

/* Master Data screens (doc 29 §1, D-072): one config per query resource; the screen is shared. */

/** Every row of a master query resource carries these (doc 24 §5.2). */
export type MasterRow = { id: string; code: string; version: number; status?: string };

/** Picker lists loaded from other query resources. */
export type OptionSource = 'companies' | 'plants' | 'warehouses' | 'workCenters';

export type FieldSpec = {
  key: string;
  label: string;
  kind: 'text' | 'select' | 'number';
  required?: boolean;
  hint?: string;
  /** Static options, or a live picker. */
  options?: Option[] | OptionSource;
  /** Shown only when creating (codes and parents are fixed afterwards). */
  createOnly?: boolean;
  /** Shown only when editing (e.g. status: a new record always starts active). */
  editOnly?: boolean;
  /** Codes: upper-cased while typing. */
  code?: boolean;
  maxLength?: number;
  /** For number fields: allowed decimals (0 = whole numbers). */
  decimals?: number;
  min?: number;
  max?: number;
};

export type FormValues = Record<string, string>;

export type MasterResource<T extends MasterRow> = {
  /** Navigation item id (`/admin/<id>`). */
  id: string;
  resource: string;
  title: string;
  description: string;
  /** Plural and singular nouns for counts, empty states and buttons. */
  itemLabel: string;
  singular: string;
  columns: EoraColumn<T>[];
  filters: FilterField[];
  defaultSort: SortRule[];
  searchPlaceholder: string;
  rowLabel: (row: T) => string;
  /** Form fields; `createOnly` ones are read-only on edit. */
  fields: FieldSpec[];
  /** Initial form values for a new record. */
  blank: FormValues;
  /** Form values of an existing record. */
  toForm: (row: T) => FormValues;
  create: (values: FormValues) => Promise<unknown>;
  /** Saves the edit at the row's version (If-Match); omitted keys are unchanged. */
  update: (row: T, values: FormValues) => Promise<unknown>;
  /** Extra read-only facts shown at the top of the edit drawer. */
  facts?: (row: T) => { label: string; value: React.ReactNode }[];
  /** A reason the record cannot be edited (e.g. virtual locations). */
  readOnly?: (row: T) => string | undefined;
};
