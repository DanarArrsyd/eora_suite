import * as React from 'react';
import { useQuery } from '@tanstack/react-query';
import { z } from 'zod';
import { useMe } from '@eora/features/auth';
import { ConfirmDialog, EoraForm, FormDateField, FormSelectField, FormTextField } from '@eora/features/patterns';
import type { Option } from '@eora/features/data';
import { Button, Drawer, DrawerContent, InlineAlert, type IsoDate } from '@eora/ui';
import { formFailure } from '@/modules/admin/errors';
import { queryOptions } from './source';
import { PartialSave, type Confirmation, type FieldSpec, type FormValues, type MasterResource, type MasterRow, type OptionSource } from './types';

const PICKERS: Record<
  OptionSource,
  { resource: string; label: (r: Record<string, unknown>) => string; activeOnly?: boolean; filters?: Record<string, string[]> }
> = {
  companies: { resource: 'core.companies', label: (r) => `${String(r.code)} · ${String(r.legalName)}` },
  plants: { resource: 'core.plants', label: (r) => `${String(r.code)} · ${String(r.name)}` },
  warehouses: { resource: 'core.warehouses', label: (r) => `${String(r.plantCode)} / ${String(r.code)} · ${String(r.name)}`, activeOnly: true },
  workCenters: { resource: 'core.work_centers', label: (r) => `${String(r.plantCode)} / ${String(r.code)} · ${String(r.name)}`, activeOnly: true },
  uoms: { resource: 'core.uoms', label: (r) => `${String(r.code)} · ${String(r.name)}`, activeOnly: true },
  items: { resource: 'core.items', label: (r) => `${String(r.code)} · ${String(r.name)}`, activeOnly: true },
  // items that are made, so they have a BOM (finished goods and Semi-Finished Material)
  madeItems: {
    resource: 'core.items',
    label: (r) => `${String(r.code)} · ${String(r.name)}`,
    activeOnly: true,
    filters: { itemType: ['FINISHED', 'SEMI_FINISHED'] },
  },
  // what a conversion profile converts from: raw coil or sheets, or Semi-Finished Material (D-080)
  materials: {
    resource: 'core.items',
    label: (r) => `${String(r.code)} · ${String(r.name)}`,
    activeOnly: true,
    filters: { itemType: ['RAW', 'SEMI_FINISHED'] },
  },
  customers: { resource: 'core.customers', label: (r) => `${String(r.code)} · ${String(r.name)}`, activeOnly: true },
  dies: { resource: 'core.dies', label: (r) => `${String(r.plantCode)} / ${String(r.code)} · ${String(r.name)}` },
  suppliers: { resource: 'core.suppliers', label: (r) => `${String(r.code)} · ${String(r.name)}`, activeOnly: true },
  // what a supplier receipt takes: raw material and consumables (D-097), with the base unit its quantity is posted in
  receivedItems: {
    resource: 'core.items',
    label: (r) => `${String(r.code)} · ${String(r.name)} (${String(r.baseUomCode)})`,
    activeOnly: true,
    filters: { itemType: ['RAW', 'CONSUMABLE'] },
  },
  rmLocations: {
    resource: 'core.locations',
    label: (r) => `${String(r.plantCode)} / ${String(r.code)} · ${String(r.name)}`,
    activeOnly: true,
    filters: { locationType: ['RM_WAREHOUSE'] },
  },
};

function usePicker(source: OptionSource | undefined) {
  const p = source ? PICKERS[source] : undefined;
  return useQuery({
    queryKey: ['master', 'picker', source],
    queryFn: ({ signal }) => queryOptions(p!.resource, p!.label, { ...(p!.activeOnly ? { status: 'ACTIVE' } : {}), ...(p!.filters ?? {}) }, signal),
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
    if (f.code) {
      const rule = f.codePattern ?? { pattern: /^[A-Z0-9][A-Z0-9_-]*$/, message: 'Use A-Z, 0-9, "_" or "-", starting with a letter or digit.' };
      field = s.refine((v) => v === '' || rule.pattern.test(v), rule.message);
    }
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

function FieldControl({ field, today }: { field: FieldSpec; today: string }) {
  const picker = usePicker(typeof field.options === 'string' ? field.options : undefined);
  if (field.kind === 'date') {
    return (
      <FormDateField
        name={field.key}
        label={field.label}
        hint={field.hint}
        required={field.required}
        min={field.notBeforeToday ? (today as IsoDate) : undefined}
        today={today as IsoDate}
      />
    );
  }
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
  /** Called after a save; `partial` names what was saved when a later write failed; `created` is the create response. */
  onSaved: (message: string, partial?: boolean, created?: unknown) => void;
}) {
  const editing = row !== null;
  // codes and parents are fixed once created: the drawer title names the record, its facts show the parents
  const fields = editing ? config.fields.filter((f) => !f.createOnly) : config.fields.filter((f) => !f.editOnly);
  const lockedReason = row
    ? (config.appendOnly ??
      config.readOnly?.(row) ??
      (canEdit ? undefined : `Your roles allow viewing ${config.permission?.label ?? 'organisation master data'}, not changing it.`))
    : undefined;
  const schema = React.useMemo(() => schemaFor(fields), [fields]);
  const me = useMe();
  // a new plant starts in the workspace time zone (D-044), changeable before it is created
  const tenantZone = me.data?.tenants.find((t) => t.id === me.data?.activeTenantId)?.timezone ?? 'Asia/Jakarta';
  const defaults = React.useMemo(
    () => (row ? config.toForm(row) : 'timezone' in config.blank && !config.blank.timezone ? { ...config.blank, timezone: tenantZone } : config.blank),
    [row, config, tenantZone],
  );
  const title = editing ? (config.drawerTitle?.(row) ?? `${row.code}`) : `New ${config.singular}`;
  // a posting asks first; the form waits for the answer and keeps its idempotency key when declined
  const [asking, setAsking] = React.useState<{ question: Confirmation; answer: (yes: boolean) => void } | null>(null);
  const ask = (question: Confirmation) =>
    new Promise<boolean>((resolve) => {
      let answered = false;
      setAsking({
        question,
        answer: (yes) => {
          if (answered) return;
          answered = true;
          resolve(yes);
          setAsking(null);
        },
      });
    });

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
            <>
              <EoraForm
                key={row ? `${row.id}:${row.version}` : 'new'}
                label={title}
                schema={schema}
                defaultValues={defaults}
                submitLabel={
                  editing
                    ? (config.rowAction?.label ?? 'Save changes')
                    : config.confirm
                      ? config.confirm(config.blank, null).confirmLabel
                      : `Create ${config.singular}`
                }
                secondary={
                  <Button type="button" onClick={() => onOpenChange(false)}>
                    Cancel
                  </Button>
                }
                onSubmit={async (values: FormValues, ctx) => {
                  const clean = Object.fromEntries(Object.entries(values).map(([k, v]) => [k, typeof v === 'string' ? v.trim() : v])) as FormValues;
                  if (config.confirm && !(await ask(config.confirm(clean, row)))) {
                    return { ok: false, message: 'Nothing was posted. Check the values and post again when they are right.' };
                  }
                  try {
                    const created = row ? await config.update(row, clean, ctx) : await config.create(clean, ctx);
                    onSaved(
                      config.savedMessage
                        ? config.savedMessage(created)
                        : editing
                          ? `${row.code} saved`
                          : `${config.singular[0]?.toUpperCase()}${config.singular.slice(1)} ${clean.code ?? ''} created`.trim(),
                      false,
                      row ? undefined : created,
                    );
                    return { ok: true, message: editing ? 'Saved' : 'Created' };
                  } catch (e) {
                    if (e instanceof PartialSave) {
                      onSaved(e.message, true);
                      return { ok: true };
                    }
                    return conflictAware(e);
                  }
                }}
              >
                {fields.map((f) => (
                  <FieldControl key={f.key} field={f} today={todayIn(tenantZone)} />
                ))}
              </EoraForm>
              <ConfirmDialog
                open={asking !== null}
                onOpenChange={(open) => {
                  if (!open) asking?.answer(false);
                }}
                title={asking?.question.title ?? ''}
                description={asking?.question.description ?? ''}
                confirmLabel={asking?.question.confirmLabel ?? 'Confirm'}
                onConfirm={async () => asking?.answer(true)}
              />
            </>
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

/** Today's date (YYYY-MM-DD) in the workspace time zone (D-044). */
function todayIn(timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}
