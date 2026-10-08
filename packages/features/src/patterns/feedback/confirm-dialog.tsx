'use client';

import * as React from 'react';
import { AlertDialog as A } from 'radix-ui';
import { Button, Field, InlineAlert, Textarea, useReturnFocus } from '@eora/ui';

type Props = {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  title: string;
  /** What will happen, including what cannot be undone. */
  description: React.ReactNode;
  confirmLabel: string;
  tone?: 'danger' | 'primary';
  /** Sensitive corrections require a written reason (audit). */
  reason?: { label: string; minLength: number };
  onConfirm: (reason?: string) => Promise<void> | void;
};

/**
 * EoraConfirmDialog — AlertDialog for destructive / irreversible actions (EORA-COMPONENT-STANDARDS §2).
 * Focus starts on Cancel; Esc cancels; the confirm button is guarded while the request runs; failures stay in the dialog.
 */
export function ConfirmDialog({ open, onOpenChange, title, description, confirmLabel, tone = 'primary', reason, onConfirm }: Props) {
  const [text, setText] = React.useState('');
  const [busy, setBusy] = React.useState(false);
  const [error, setError] = React.useState<string>();
  const [touched, setTouched] = React.useState(false);
  const focus = useReturnFocus();
  const reasonError = reason && touched && text.trim().length < reason.minLength ? `Enter at least ${reason.minLength} characters` : undefined;

  const change = (v: boolean) => {
    if (busy) return;
    if (!v) {
      setText('');
      setError(undefined);
      setTouched(false);
    }
    onOpenChange(v);
  };

  const confirm = async (e: React.MouseEvent) => {
    e.preventDefault();
    setTouched(true);
    if (reason && text.trim().length < reason.minLength) return;
    setBusy(true);
    setError(undefined);
    try {
      await onConfirm(reason ? text.trim() : undefined);
      setBusy(false);
      change(false);
    } catch (err) {
      setBusy(false);
      setError(err instanceof Error ? err.message : 'The request failed. Nothing was changed.');
    }
  };

  return (
    <A.Root open={open} onOpenChange={change}>
      <A.Portal>
        <A.Overlay className="fixed inset-0 z-80 bg-overlay" />
        <A.Content
          {...focus}
          className="fixed top-[15vh] left-1/2 z-80 flex w-[calc(100vw-32px)] max-w-md -translate-x-1/2 flex-col rounded-lg bg-surface shadow-[var(--shadow-popover)] focus-visible:outline-none"
        >
          <div className="flex flex-col gap-3 p-5">
            <A.Title className="m-0 text-base font-semibold">{title}</A.Title>
            <A.Description asChild>
              <div className="text-[13.5px] text-ink-2">{description}</div>
            </A.Description>
            {reason ? (
              <Field label={reason.label} required error={reasonError}>
                {(c) => <Textarea {...c} value={text} onChange={(e) => setText(e.target.value)} onBlur={() => setTouched(true)} rows={3} />}
              </Field>
            ) : null}
            {error ? <InlineAlert tone="danger" title={error} /> : null}
          </div>
          <div className="flex justify-end gap-2 border-t border-line px-5 py-3">
            <A.Cancel asChild>
              <Button disabled={busy}>Cancel</Button>
            </A.Cancel>
            <A.Action asChild>
              <Button variant={tone === 'danger' ? 'destructive' : 'primary'} onClick={confirm} disabled={busy}>
                {busy ? 'Working…' : confirmLabel}
              </Button>
            </A.Action>
          </div>
        </A.Content>
      </A.Portal>
    </A.Root>
  );
}
