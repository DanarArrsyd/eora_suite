import type * as React from 'react';
import type { EoraColumn, FilterField } from '@eora/features/patterns';
import type { Option, SortRule } from '@eora/features/data';

/* Master Data screens (doc 29 §1, D-072): one config per query resource; the screen is shared. */

/** Every row of a master query resource carries these (doc 24 §5.2). */
export type MasterRow = { id: string; code: string; version: number; status?: string };

/** Picker lists loaded from other query resources. */
export type OptionSource = 'companies' | 'plants' | 'warehouses' | 'workCenters' | 'uoms' | 'items' | 'customers' | 'dies';

export type FieldSpec = {
  key: string;
  label: string;
  kind: 'text' | 'select' | 'number' | 'date';
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
  /** Allowed code characters (default A-Z, 0-9, "_", "-"). */
  codePattern?: { pattern: RegExp; message: string };
  /** Date fields: no day before today (tenant calendar). */
  notBeforeToday?: boolean;
  maxLength?: number;
  /** For number fields: allowed decimals (0 = whole numbers). */
  decimals?: number;
  min?: number;
  max?: number;
};

export type FormValues = Record<string, string>;

export type MasterResource<T extends MasterRow> = {
  /** Section id (`?show=<id>`). */
  id: string;
  resource: string;
  /** View / change permission hints (default: organisation master). The server decides. */
  permission?: { view: string; create: string; label: string };
  /** No create action on this screen (records come from elsewhere). */
  noCreate?: boolean;
  /** Records are added but never edited (e.g. versions); the drawer only shows them. */
  appendOnly?: string;
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
  /**
   * The record has its own page (e.g. a routing with versions and operations): rows open it instead of the edit drawer,
   * and a newly created record opens it. Receives the row, or the created record from the create response.
   */
  openHref?: (row: T) => string;
  /** A reason the record cannot be edited (e.g. virtual locations). */
  readOnly?: (row: T) => string | undefined;
};

/**
 * A save whose first write committed and a later one failed (e.g. the item was saved, its specification was not).
 * The screen refreshes, closes the drawer and says exactly which part was saved; nothing is reported as unsaved.
 */
export class PartialSave extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'PartialSave';
  }
}
