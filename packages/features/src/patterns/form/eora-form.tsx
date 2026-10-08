'use client';

import * as React from 'react';
import {
  FormProvider,
  useForm,
  useFormContext,
  type DefaultValues,
  type FieldPath,
  type FieldValues,
  type Resolver,
  type UseFormReturn,
} from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import type { z } from 'zod';
import { Button, Field, InlineAlert, Input, Select, Textarea, cn } from '@eora/ui';

export type SubmitContext = {
  /** Stable for this form instance until a submission succeeds — the server deduplicates retries (CLAUDE.md: idempotent writes). */
  idempotencyKey: string;
};

export type SubmitResult = { ok: true; message?: string } | { ok: false; message: string; fieldErrors?: Record<string, string> };

type EoraFormProps<S extends z.ZodType<FieldValues, FieldValues>> = {
  schema: S;
  defaultValues: DefaultValues<z.input<S>>;
  onSubmit: (values: z.output<S>, ctx: SubmitContext) => Promise<SubmitResult>;
  /** Accessible name of the form. */
  label: string;
  children: React.ReactNode;
  submitLabel: string;
  /** Secondary actions (Cancel). */
  secondary?: React.ReactNode;
  className?: string;
  /** Render actions in a sticky footer (drawers / long forms). */
  stickyActions?: boolean;
};

function newKey(): string {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `k-${Date.now()}-${Math.random().toString(36).slice(2)}`;
}

/**
 * EoraForm — React Hook Form + Zod. Client validation mirrors (never replaces) server validation:
 * server field errors are mapped back onto fields, a summary lists all errors and receives focus,
 * the submit button is guarded against double submission and every attempt carries the same idempotency key.
 */
export function EoraForm<S extends z.ZodType<FieldValues, FieldValues>>({
  schema,
  defaultValues,
  onSubmit,
  label,
  children,
  submitLabel,
  secondary,
  className,
  stickyActions,
}: EoraFormProps<S>) {
  const form = useForm<z.input<S>, unknown, z.output<S>>({
    resolver: zodResolver(schema as never) as unknown as Resolver<z.input<S>, unknown, z.output<S>>,
    defaultValues,
    mode: 'onTouched',
    // The error summary receives focus instead (it lists every error with a link to its field).
    shouldFocusError: false,
  });
  const [key, setKey] = React.useState(newKey);
  const [result, setResult] = React.useState<SubmitResult | null>(null);
  const summaryRef = React.useRef<HTMLDivElement>(null);
  const errors = form.formState.errors;
  const errorList = Object.entries(errors).flatMap(([name, e]) => (e && typeof e.message === 'string' ? [{ name, message: e.message }] : []));

  // Focus the error summary after it has rendered (an effect, not a frame guess).
  // RHF publishes formState in a later commit, so wait until the summary exists before moving focus.
  const [focusTick, setFocusTick] = React.useState(0);
  const handledTick = React.useRef(0);
  React.useEffect(() => {
    if (focusTick > handledTick.current && summaryRef.current) {
      handledTick.current = focusTick;
      summaryRef.current.focus();
    }
  });
  const focusSummary = () => setFocusTick((t) => t + 1);
  const onValid = async (values: z.output<S>) => {
    setResult(null);
    const res = await onSubmit(values, { idempotencyKey: key });
    setResult(res);
    if (res.ok) {
      setKey(newKey());
    } else {
      for (const [name, message] of Object.entries(res.fieldErrors ?? {})) form.setError(name as FieldPath<z.input<S>>, { type: 'server', message });
      focusSummary();
    }
  };

  const showSummary = form.formState.isSubmitted && (errorList.length > 0 || (result && !result.ok));

  return (
    <FormProvider {...(form as unknown as UseFormReturn<FieldValues>)}>
      <form aria-label={label} noValidate onSubmit={(e) => void form.handleSubmit(onValid, focusSummary)(e)} className={cn('flex flex-col gap-4', className)}>
        {showSummary ? (
          <div ref={summaryRef} tabIndex={-1} className="focus-visible:outline-2 focus-visible:outline-ring">
            <InlineAlert
              tone="danger"
              title={result && !result.ok ? result.message : `Fix ${errorList.length} field${errorList.length === 1 ? '' : 's'} before submitting`}
            >
              {errorList.length ? (
                <ul className="m-0 list-disc pl-5">
                  {errorList.map((e) => (
                    <li key={e.name}>
                      <button type="button" className="text-left text-link underline" onClick={() => form.setFocus(e.name as FieldPath<z.input<S>>)}>
                        {e.message}
                      </button>
                    </li>
                  ))}
                </ul>
              ) : null}
              <p className="m-0 mt-1 text-xs">Nothing was saved.</p>
            </InlineAlert>
          </div>
        ) : null}
        {result?.ok ? <InlineAlert tone="success" title={result.message ?? 'Saved'} /> : null}
        {children}
        <div className={cn('flex flex-wrap justify-end gap-2 pt-2', stickyActions && 'sticky bottom-0 -mx-5 border-t border-line bg-surface px-5 py-3')}>
          {secondary}
          <Button type="submit" variant="primary" disabled={form.formState.isSubmitting} aria-disabled={form.formState.isSubmitting || undefined}>
            {form.formState.isSubmitting ? 'Submitting…' : submitLabel}
          </Button>
        </div>
      </form>
    </FormProvider>
  );
}

/* ------------------------------------------------------------- bound fields ------------------------------------------------------------- */

type BaseFieldProps = { name: string; label: string; hint?: React.ReactNode; required?: boolean; className?: string };

function useFieldError(name: string): string | undefined {
  const { formState } = useFormContext();
  const err = name
    .split('.')
    .reduce<unknown>((acc, k) => (acc && typeof acc === 'object' ? (acc as Record<string, unknown>)[k] : undefined), formState.errors) as
    { message?: unknown } | undefined;
  return typeof err?.message === 'string' ? err.message : undefined;
}

export function FormTextField({ name, label, hint, required, className, ...input }: BaseFieldProps & Omit<React.ComponentPropsWithRef<'input'>, 'name'>) {
  const { register } = useFormContext();
  const error = useFieldError(name);
  return (
    <Field label={label} hint={hint} error={error} required={required} className={className}>
      {(c) => <Input {...c} {...input} {...register(name)} />}
    </Field>
  );
}

export function FormTextareaField({ name, label, hint, required, className, ...rest }: BaseFieldProps & Omit<React.ComponentPropsWithRef<'textarea'>, 'name'>) {
  const { register } = useFormContext();
  const error = useFieldError(name);
  return (
    <Field label={label} hint={hint} error={error} required={required} className={className}>
      {(c) => <Textarea {...c} {...rest} {...register(name)} />}
    </Field>
  );
}

export function FormSelectField({
  name,
  label,
  hint,
  required,
  className,
  options,
  placeholder,
}: BaseFieldProps & { options: { value: string; label: string }[]; placeholder?: string }) {
  const { register } = useFormContext();
  const error = useFieldError(name);
  return (
    <Field label={label} hint={hint} error={error} required={required} className={className}>
      {(c) => (
        <Select {...c} {...register(name)}>
          <option value="">{placeholder ?? 'Select…'}</option>
          {options.map((o) => (
            <option key={o.value} value={o.value}>
              {o.label}
            </option>
          ))}
        </Select>
      )}
    </Field>
  );
}

/** Quantity + UOM in one control (QuantityWithUom rule): decimals per UOM, numeric keypad on phones. */
export function FormQuantityField({ name, label, hint, required, className, uom, decimals }: BaseFieldProps & { uom: string; decimals: number }) {
  const { register } = useFormContext();
  const error = useFieldError(name);
  return (
    <Field label={label} hint={hint ?? `${uom}, up to ${decimals} decimal${decimals === 1 ? '' : 's'}`} error={error} required={required} className={className}>
      {(c) => (
        <div className="relative">
          <Input {...c} inputMode={decimals ? 'decimal' : 'numeric'} autoComplete="off" className="pr-14 text-right tabular-nums" {...register(name)} />
          <span aria-hidden="true" className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-[13px] text-ink-2">
            {uom}
          </span>
        </div>
      )}
    </Field>
  );
}
