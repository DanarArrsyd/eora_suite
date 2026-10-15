import * as React from 'react';
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { EoraForm, FormSelectField, FormTextField } from '@eora/features/patterns';
import type { Option } from '@eora/features/data';
import { Button, Drawer, DrawerContent, InlineAlert } from '@eora/ui';
import { formFailure } from '@/modules/admin/errors';
import { queryOptions } from './source';
import type { FieldSpec, FormValues, MasterResource, MasterRow, OptionSource } from './types';

const PICKERS: Record<OptionSource, { resource: string; label: (r: Record<string, unknown>) => string; activeOnly?: boolean }> = {
  companies: { resource: 'core.companies', label: (r) => `${String(r.code)} · ${String(r.legalName)}` },
  plants: { resource: 'core.plants', label: (r) => `${String(r.code)} · ${String(r.name)}` },
  warehouses: { resource: 'core.warehouses', label: (r) => `${String(r.plantCode)} / ${String(r.code)} · ${String(r.name)}`, activeOnly: true },
  workCenters: { resource: 'core.work_centers', label: (r) => `${String(r.plantCode)} / ${String(r.code)} · ${String(r.name)}`, activeOnly: true },
};

function usePicker(source: OptionSource | undefined) {
  const p = source ? PICKERS[source] : undefined;
  return useQuery({
    queryKey: ['master', 'picker', source],
    queryFn: ({ signal }) => queryOptions(p!.resource, p!.label, p!.activeOnly ? { status: 'ACTIVE' } : {}, signal),
    enabled: !!p,
    staleTime: 30_000,
  });
}

/** Zod schema from the field specs: trimmed strings; codes and numbers checked like the server does. */
function schemaFor(fields: FieldSpec[]) {
  const shape: Record<string, z.ZodType<string>> = {};
  for (const f of fields) {
    let s = f.code ? z.string().trim().toUpperCase() : z.string().trim();
    if (f.maxLength) s = s.max(f.maxLength, `Use at most ${f.maxLength} characters.`);
    if (f.required) s = s.min(1, `Enter ${f.label.toLowerCase()}.`);
    let field: z.ZodType<string> = s;
    if (f.code) field = s.regex(/^$|^[A-Z0-9][A-Z0-9_-]*$/, 'Use A-Z, 0-9, "_" or "-", starting with a letter or digit.');
    if (f.kind === 'number') {
      const decimals = f.decimals ?? 0;
      const pattern = decimals === 0 ? /^$|^\d+$/ : new RegExp(`^$|^\\d+(\\.\\d{1,${decimals}})?$`);
      field = s
        .regex(pattern, decimals === 0 ? 'Enter a whole number.' : `Enter a number with at most ${decimals} decimals.`)
        .refine(
          (v) => v === '' || ((f.min === undefined || Number(v) >= f.min) && (f.max === undefined || Number(v) <= f.max)),
          `Enter a value${f.min !== undefined ? ` from ${f.min}` : ''}${f.max !== undefined ? ` up to ${f.max}` : ''}.`,
        );
    }
    shape[f.key] = field;
  }
  return z.object(shape);
}

function FieldControl({ field }: { field: FieldSpec }) {
  const picker = usePicker(typeof field.options === 'string' ? field.options : undefined);
  if (field.kind === 'select') {
    const options: Option[] = Array.isArray(field.options) ? field.options : (picker.data ?? []);
    const hint = picker.isError ? 'The list could not be loaded. Close and reopen to try again.' : field.hint;
    return (
      <FormSelectField
        name={field.key}
        label={field.label}
        hint={hint}
        required={field.required}
        options={options}
        placeholder={picker.isPending && !Array.isArray(field.options) ? 'Loading…' : undefined}
      />
    );
  }
  return (
    <FormTextField
      name={field.key}
      label={field.label}
      hint={field.hint}
      required={field.required}
      maxLength={field.maxLength}
      inputMode={field.kind === 'number' ? (field.decimals ? 'decimal' : 'numeric') : undefined}
      autoComplete="off"
      spellCheck={false}
      className={field.code ? '[&_input]:font-mono [&_input]:uppercase' : undefined}
    />
  );
}

/**
 * Create or edit drawer of a master record (EoraForm, doc 29 §1). Codes and parents are chosen once; an edit saves at the
 * version the row was read with, so a concurrent change answers 412 and asks for a reload instead of overwriting it.
 */
export function MasterEditor<T extends MasterRow>({
  config,
  row,
  canEdit,
  open,
  onOpenChange,
  onSaved,
}: {
  config: MasterResource<T>;
  /** null = create */
  row: T | null;
  /** The caller's create/edit permission hint (the server decides). */
  canEdit: boolean;
  open: boolean;
  onOpenChange: (open: boolean) => void;
  onSaved: (message: string) => void;
}) {
  const editing = row !== null;
  // codes and parents are fixed once created: the drawer title names the record, its facts show the parents
  const fields = editing ? config.fields.filter((f) => !f.createOnly) : config.fields.filter((f) => !f.editOnly);
  const lockedReason = row
    ? (config.readOnly?.(row) ?? (canEdit ? undefined : 'Your roles allow viewing organisation master data, not changing it.'))
    : undefined;
  const schema = React.useMemo(() => schemaFor(fields), [fields]);
  const defaults = React.useMemo(() => (row ? config.toForm(row) : config.blank), [row, config]);
  const title = editing ? `${row.code}` : `New ${config.singular}`;

  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent title={title} closeLabel="Close">
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
          {row && config.facts ? (
            <dl className="m-0 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5 text-[13px]">
              {config.facts(row).map((f) => (
                <React.Fragment key={f.label}>
                  <dt className="text-ink-2">{f.label}</dt>
                  <dd className="m-0 text-ink">{f.value}</dd>
                </React.Fragment>
              ))}
            </dl>
          ) : null}
          {lockedReason ? (
            <InlineAlert tone="info" title="This record cannot be changed">
              {lockedReason}
            </InlineAlert>
          ) : (
            <EoraForm
              key={row ? `${row.id}:${row.version}` : 'new'}
              label={title}
              schema={schema}
              defaultValues={defaults}
              submitLabel={editing ? 'Save changes' : `Create ${config.singular}`}
              secondary={
                <Button type="button" onClick={() => onOpenChange(false)}>
                  Cancel
                </Button>
              }
              onSubmit={async (values: FormValues) => {
                const clean = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, typeof v === 'string' ? v.trim() : v])) as FormValues;
                try {
                  if (row) await config.update(row, clean);
                  else await config.create(clean);
                  onSaved(editing ? `${row.code} saved` : `${config.singular[0]?.toUpperCase()}${config.singular.slice(1)} ${clean.code ?? ''} created`.trim());
                  return { ok: true, message: editing ? 'Saved' : 'Created' };
                } catch (e) {
                  return conflictAware(e);
                }
              }}
            >
              {fields.map((f) => (
                <FieldControl key={f.key} field={f} />
              ))}
            </EoraForm>
          )}
        </div>
      </DrawerContent>
    </Drawer>
  );
}

function conflictAware(error: unknown) {
  const status = (error as { status?: number } | null)?.status;
  if (status === 412) {
    return { ok: false as const, message: 'Someone changed this record after you opened it. Close the drawer, reopen the record and apply your change again.' };
  }
  return formFailure(error, 'Nothing was saved.');
}
