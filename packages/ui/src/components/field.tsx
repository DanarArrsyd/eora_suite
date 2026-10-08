import * as React from 'react';
import { CircleAlert } from 'lucide-react';
import { cn } from '../lib/cn';

export type FieldControlProps = { id: string; 'aria-describedby'?: string; 'aria-invalid'?: true; 'aria-required'?: true };

type FieldProps = {
  label: React.ReactNode;
  hint?: React.ReactNode;
  error?: React.ReactNode;
  required?: boolean;
  className?: string;
  children: (control: FieldControlProps) => React.ReactNode;
};

/** EoraField — label + control + hint + error with correct id / aria wiring. */
export function Field({ label, hint, error, required, className, children }: FieldProps) {
  const id = React.useId();
  const hintId = hint ? `${id}-hint` : undefined;
  const errorId = error ? `${id}-error` : undefined;
  const describedBy = [hintId, errorId].filter(Boolean).join(' ') || undefined;
  return (
    <div className={cn('flex min-w-0 flex-col gap-1.5', className)}>
      <label htmlFor={id} className="text-[13px] font-medium text-ink">
        {label}
        {required ? <span aria-hidden="true" className="text-stat-err-fg"> *</span> : null}
      </label>
      {children({ id, 'aria-describedby': describedBy, ...(error ? { 'aria-invalid': true } : {}), ...(required ? { 'aria-required': true } : {}) })}
      {hint ? <p id={hintId} className="text-xs text-ink-2">{hint}</p> : null}
      {error ? (
        <p id={errorId} className="flex items-center gap-1 text-xs font-medium text-stat-err-fg">
          <CircleAlert aria-hidden="true" className="size-3.5" />
          {error}
        </p>
      ) : null}
    </div>
  );
}
