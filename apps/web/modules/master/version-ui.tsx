import * as React from 'react';
import Link from 'next/link';
import { z } from 'zod';
import { DomainStatusBadge, EoraForm, FormTextareaField } from '@eora/features/patterns';
import { Button, Card, CardContent, CardHeader, CardTitle, InlineAlert, Modal, ModalBody, ModalContent } from '@eora/ui';
import { actionMessage, formFailure } from '@/modules/admin/errors';

/*
 * Shared pieces of the versioned engineering records approved by a second person — routing versions, BOM versions,
 * BOM–routing map revisions (doc 26 §2.4a/§2.5, EoraApprovalPanel in the component standards). One implementation, so
 * the three pages say and do the same thing.
 */

export type LifecycleStatus = 'DRAFT' | 'SUBMITTED' | 'REJECTED' | 'APPROVED' | 'SUPERSEDED';
export type LifecycleAction = 'submit' | 'withdraw' | 'reopen' | 'approve';
export type Can = { view: boolean; create: boolean; submit: boolean; approve: boolean };

export const dayRange = (from: string, to: string | null) => (to ? `${from} to ${to}` : `From ${from}, until replaced`);
export const when = (iso: string | null) => (iso ? new Date(iso).toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' }) : '');

/** Today's date (YYYY-MM-DD) in the workspace time zone (D-044). */
export function todayIn(timeZone: string): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}

/** A stale ETag means someone else saved first: say so instead of overwriting. */
export function conflictMessage(error: unknown, fallback: string): string {
  const status = (error as { status?: number } | null)?.status;
  if (status === 412) return 'Someone changed this after you opened it. The page shows the latest; apply your change again.';
  return actionMessage(error, fallback);
}

export function conflictFailure(error: unknown, fallback: string) {
  const status = (error as { status?: number } | null)?.status;
  if (status === 412) return { ok: false as const, message: conflictMessage(error, fallback) };
  return formFailure(error, fallback);
}

/**
 * Maker → checker: the steps of one record and the actions the user's roles allow in its state. Whether the user
 * prepared or edited it is decided by the server (403 SOD_VIOLATION); the panel says so up front.
 */
export function ApprovalPanel({
  label,
  record,
  can,
  missing = [],
  approvedNote,
  act,
  reject,
  onDone,
}: {
  /** e.g. "Version 2", "Map revision 1" — used in buttons' results and the reject dialog. */
  label: string;
  record: { status: LifecycleStatus; preparedByName: string | null; approvedByName: string | null; approvedAt: string | null };
  can: Can;
  /** What a Draft still lacks before it can be submitted, e.g. "the standard yield". */
  missing?: string[];
  /** Shown once approved, e.g. "Use New version to change the routing." */
  approvedNote: string;
  act: (action: LifecycleAction) => Promise<unknown>;
  reject: (reason: string) => Promise<unknown>;
  onDone: (message: string) => void;
}) {
  const [error, setError] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState<LifecycleAction | null>(null);
  const [rejecting, setRejecting] = React.useState(false);
  const { status } = record;

  const run = async (action: LifecycleAction, done: string) => {
    setError(null);
    setBusy(action);
    try {
      await act(action);
      onDone(done);
    } catch (e) {
      setError(actionMessage(e, 'Nothing changed.'));
    } finally {
      setBusy(null);
    }
  };

  const submittedStep = status === 'DRAFT' ? 'Not yet' : status === 'REJECTED' ? 'Rejected' : 'Done';
  const approvedStep =
    status === 'APPROVED' || status === 'SUPERSEDED'
      ? `${record.approvedByName ?? 'Approved'}, ${when(record.approvedAt)}`
      : status === 'SUBMITTED'
        ? 'Waiting for a second person'
        : 'Not yet';
  const noun = label.split(' ')[0]?.toLowerCase() ?? 'record';

  return (
    <Card>
      <CardHeader>
        <CardTitle>Approval</CardTitle>
      </CardHeader>
      <CardContent className="flex flex-col gap-4">
        <ol className="m-0 flex list-none flex-col gap-2.5 p-0 text-[13.5px]">
          <li>
            <span className="text-ink-2">Prepared</span>
            <span className="block text-ink">{record.preparedByName ?? '—'}</span>
          </li>
          <li>
            <span className="text-ink-2">Submitted</span>
            <span className="block text-ink">{submittedStep}</span>
          </li>
          <li>
            <span className="text-ink-2">Approved</span>
            <span className="block text-ink">{approvedStep}</span>
          </li>
        </ol>
        <p className="m-0 text-[12.5px] text-ink-2">Approved by someone who did not prepare or edit this {noun}.</p>
        {error ? (
          <InlineAlert tone="danger" title="Not done">
            {error}
          </InlineAlert>
        ) : null}
        <div className="flex flex-col gap-2">
          {status === 'DRAFT' && can.submit ? (
            <>
              {missing.length ? <p className="m-0 text-[13px] text-ink-2">Before submitting, add {missing.join(' and ')}.</p> : null}
              <Button variant="primary" disabled={!!busy} onClick={() => void run('submit', `${label} submitted for approval`)}>
                {busy === 'submit' ? 'Submitting…' : 'Submit for approval'}
              </Button>
            </>
          ) : null}
          {status === 'SUBMITTED' && can.approve ? (
            <>
              <Button variant="primary" disabled={!!busy} onClick={() => void run('approve', `${label} approved`)}>
                {busy === 'approve' ? 'Approving…' : 'Approve'}
              </Button>
              <Button disabled={!!busy} onClick={() => setRejecting(true)}>
                Reject
              </Button>
            </>
          ) : null}
          {status === 'SUBMITTED' && can.submit ? (
            <Button disabled={!!busy} onClick={() => void run('withdraw', `${label} withdrawn to Draft`)}>
              {busy === 'withdraw' ? 'Withdrawing…' : 'Withdraw to edit'}
            </Button>
          ) : null}
          {status === 'REJECTED' && can.submit ? (
            <Button disabled={!!busy} onClick={() => void run('reopen', `${label} reopened as Draft`)}>
              {busy === 'reopen' ? 'Reopening…' : 'Reopen to edit'}
            </Button>
          ) : null}
          {status === 'APPROVED' || status === 'SUPERSEDED' ? <p className="m-0 text-[13px] text-ink-2">{approvedNote}</p> : null}
        </div>
      </CardContent>
      <Modal open={rejecting} onOpenChange={setRejecting}>
        <ModalContent title={`Reject ${label.toLowerCase()}`} description="The preparer sees the reason and can reopen it to rework it." closeLabel="Close">
          <ModalBody>
            <EoraForm
              label={`Reject ${label.toLowerCase()}`}
              schema={z.object({ reason: z.string().trim().min(3, 'Say what has to change.').max(500, 'Use at most 500 characters.') })}
              defaultValues={{ reason: '' }}
              submitLabel={`Reject ${noun}`}
              secondary={
                <Button type="button" onClick={() => setRejecting(false)}>
                  Cancel
                </Button>
              }
              onSubmit={async (v) => {
                try {
                  await reject(v.reason);
                  setRejecting(false);
                  onDone(`${label} rejected`);
                  return { ok: true };
                } catch (e) {
                  return formFailure(e, 'It was not rejected.');
                }
              }}
            >
              <FormTextareaField name="reason" label="Reason" required rows={3} maxLength={500} />
            </EoraForm>
          </ModalBody>
        </ModalContent>
      </Modal>
    </Card>
  );
}

export type StripItem = { id: string; label: string; status: LifecycleStatus; detail: string; inUse?: boolean };

/** Version or revision switcher under the page header: newest first; the one in use today is marked. */
export function VersionStrip({
  items,
  selectedId,
  hrefFor,
  label = 'Versions',
}: {
  items: StripItem[];
  selectedId: string | null;
  hrefFor: (id: string) => string;
  label?: string;
}) {
  if (items.length === 0) return null;
  return (
    <nav aria-label={label} className="mb-5 flex gap-2 overflow-x-auto pb-1">
      {items.map((v) => (
        <Link
          key={v.id}
          href={hrefFor(v.id)}
          replace
          aria-current={v.id === selectedId ? 'page' : undefined}
          className="flex shrink-0 flex-col gap-0.5 rounded-md border border-line bg-surface px-3 py-2 text-[13.5px] text-ink no-underline hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring aria-[current=page]:border-accent aria-[current=page]:ring-1 aria-[current=page]:ring-accent max-md:min-h-11"
        >
          <span className="flex items-center gap-2 font-medium whitespace-nowrap">
            {v.label} <DomainStatusBadge domain="version" status={v.status} />
            {v.inUse ? <span className="text-[12px] font-normal text-ink-2">In use</span> : null}
          </span>
          <span className="text-[12.5px] whitespace-nowrap text-ink-2">{v.detail}</span>
        </Link>
      ))}
    </nav>
  );
}
