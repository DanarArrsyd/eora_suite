'use client';

import * as React from 'react';
import { Check, FileUp, Upload } from 'lucide-react';
import { Button, InlineAlert, LoadingState, MockDataLabel, Modal, ModalBody, ModalClose, ModalContent, ModalFooter, ModalTrigger, cn } from '@eora/ui';
import type { ImportCommitResult, ImportSource, ImportValidation } from './types';

const STEPS = ['Template', 'Upload', 'Validate', 'Review', 'Result'] as const;
const MAX_BYTES = 10 * 1024 * 1024;
const ACCEPT = ['.xlsx', '.csv'];

function newKey() {
  return typeof crypto !== 'undefined' && 'randomUUID' in crypto ? crypto.randomUUID() : `k-${Date.now()}`;
}

/** Client-side pre-check only (type and size); the server re-validates every row. Exported for tests. */
export function precheckFile(file: { name: string; size: number }): string | undefined {
  const ext = file.name.slice(file.name.lastIndexOf('.')).toLowerCase();
  if (!ACCEPT.includes(ext)) return `Use an Excel (.xlsx) or CSV file. “${file.name}” is not supported.`;
  if (file.size > MAX_BYTES) return 'The file is larger than 10 MB. Split it into smaller files.';
  if (file.size === 0) return 'The file is empty.';
  return undefined;
}

/**
 * EoraImportWizard — template → upload → validate → review errors → (approval) → idempotent commit (doc 08, doc 16 §11).
 * Retrying a commit reuses the same idempotency key, so rows are never posted twice (doc 14 #15).
 */
export function ImportWizard({ title, source, triggerLabel = 'Import' }: { title: string; source: ImportSource; triggerLabel?: string }) {
  const [open, setOpen] = React.useState(false);
  const [step, setStep] = React.useState(0);
  const [file, setFile] = React.useState<File | null>(null);
  const [fileError, setFileError] = React.useState<string>();
  const [validation, setValidation] = React.useState<ImportValidation | null>(null);
  const [result, setResult] = React.useState<ImportCommitResult | null>(null);
  const [busy, setBusy] = React.useState(false);
  const [failure, setFailure] = React.useState<string>();
  const [key, setKey] = React.useState(newKey);
  const fileId = React.useId();

  const reset = () => {
    setStep(0);
    setFile(null);
    setFileError(undefined);
    setValidation(null);
    setResult(null);
    setFailure(undefined);
    setKey(newKey());
  };

  const validate = async () => {
    if (!file) return;
    setStep(2);
    setBusy(true);
    setFailure(undefined);
    try {
      setValidation(await source.validate(file));
      setStep(3);
    } catch {
      setFailure('Validation could not run. The file was not imported. Try again.');
      setStep(1);
    } finally {
      setBusy(false);
    }
  };

  const commit = async () => {
    if (!validation) return;
    setBusy(true);
    setFailure(undefined);
    try {
      setResult(await source.commit(validation.jobId, { idempotencyKey: key, validRowsOnly: validation.errors.length > 0 }));
      setStep(4);
    } catch {
      setFailure('Commit failed. No rows were posted; retrying is safe (same idempotency key).');
    } finally {
      setBusy(false);
    }
  };

  return (
    <Modal
      open={open}
      onOpenChange={(v) => {
        if (busy) return;
        setOpen(v);
        if (!v) reset();
      }}
    >
      <ModalTrigger asChild>
        <Button>
          <Upload aria-hidden="true" />
          <span className="max-md:hidden">{triggerLabel}</span>
          <span className="sr-only md:hidden">{triggerLabel}</span>
        </Button>
      </ModalTrigger>
      <ModalContent title={title} size="lg" closeLabel="Close import">
        <ModalBody className="flex flex-col gap-4">
          <ol aria-label="Import steps" className="m-0 flex list-none flex-wrap gap-x-4 gap-y-2 p-0 text-[13px]">
            {STEPS.map((s, i) => (
              <li
                key={s}
                aria-current={i === step ? 'step' : undefined}
                className={cn('flex items-center gap-1.5', i === step ? 'font-semibold text-accent' : i < step ? 'text-ink' : 'text-ink-2')}
              >
                <span
                  aria-hidden="true"
                  className={cn(
                    'grid size-5 place-items-center rounded-full text-[11px] font-semibold',
                    i < step ? 'bg-stat-ok-bg text-stat-ok-fg' : i === step ? 'bg-accent text-on-accent' : 'bg-stat-neutral-bg text-ink-2',
                  )}
                >
                  {i < step ? <Check className="size-3" /> : i + 1}
                </span>
                {s}
                {i < step ? <span className="sr-only"> (done)</span> : null}
              </li>
            ))}
          </ol>
          {source.isMock ? (
            <p className="m-0 flex items-center gap-2 text-xs text-ink-2">
              <MockDataLabel /> Mock adapter: file content is not read and nothing is posted.
            </p>
          ) : null}
          {failure ? <InlineAlert tone="danger" title={failure} /> : null}

          {step === 0 ? (
            <div className="flex flex-col gap-3">
              <p className="m-0 text-ink-2">
                Use the <b className="text-ink">{source.templateName}</b> template. Columns:
              </p>
              <ul className="m-0 grid list-none grid-cols-[repeat(auto-fill,minmax(200px,1fr))] gap-1.5 p-0">
                {source.templateColumns.map((c) => (
                  <li key={c.name} className="rounded-md border border-line px-3 py-2">
                    <span className="font-medium">{c.name}</span>
                    {c.required ? <span className="text-xs text-stat-err-fg"> · required</span> : <span className="text-xs text-ink-2"> · optional</span>}
                    {c.note ? <span className="block text-xs text-ink-2">{c.note}</span> : null}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          {step === 1 ? (
            <div className="flex flex-col gap-2">
              <label htmlFor={fileId} className="text-[13px] font-medium">
                File (.xlsx or .csv, max 10 MB)
              </label>
              <input
                id={fileId}
                type="file"
                accept={ACCEPT.join(',')}
                aria-describedby={fileError ? `${fileId}-err` : undefined}
                aria-invalid={fileError ? true : undefined}
                onChange={(e) => {
                  const f = e.target.files?.[0] ?? null;
                  const err = f ? precheckFile(f) : undefined;
                  setFile(err ? null : f);
                  setFileError(err);
                }}
                className="rounded-md border border-dashed border-line-control p-4 text-[13.5px] file:mr-3 file:rounded-md file:border file:border-line-strong file:bg-surface file:px-3 file:py-1.5 file:text-[13px] file:font-medium focus-visible:outline-2 focus-visible:outline-ring"
              />
              {fileError ? (
                <p id={`${fileId}-err`} className="m-0 text-xs font-medium text-stat-err-fg">
                  {fileError}
                </p>
              ) : null}
              {file ? (
                <p className="m-0 flex items-center gap-1.5 text-[13px] text-ink-2">
                  <FileUp aria-hidden="true" className="size-4" /> {file.name} · {(file.size / 1024).toFixed(1)} KB
                </p>
              ) : null}
            </div>
          ) : null}

          {step === 2 ? <LoadingState title="Validating rows on the server…" /> : null}

          {step === 3 && validation ? (
            <div className="flex flex-col gap-3">
              <dl className="m-0 grid grid-cols-3 gap-2 max-md:grid-cols-1">
                {[
                  ['Rows in file', validation.totalRows],
                  ['Valid rows', validation.validRows],
                  ['Rows with errors', validation.totalRows - validation.validRows],
                ].map(([k, v]) => (
                  <div key={k} className="rounded-md border border-line px-3 py-2">
                    <dt className="text-xs text-ink-2">{k}</dt>
                    <dd className="m-0 text-lg font-semibold tabular-nums">{Number(v).toLocaleString('en-US')}</dd>
                  </div>
                ))}
              </dl>
              {validation.errors.length ? (
                <div className="max-h-56 overflow-auto rounded-md border border-line">
                  <table className="w-full border-separate border-spacing-0 text-[13px]">
                    <caption className="sr-only">Row errors</caption>
                    <thead>
                      <tr>
                        {['Row', 'Column', 'Problem'].map((h) => (
                          <th key={h} scope="col" className="sticky top-0 border-b border-line bg-subtle px-3 py-2 text-left text-xs font-semibold text-ink-2">
                            {h}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {validation.errors.map((e, i) => (
                        <tr key={i}>
                          <td className="border-b border-line px-3 py-2 tabular-nums">{e.row}</td>
                          <td className="border-b border-line px-3 py-2">{e.column}</td>
                          <td className="border-b border-line px-3 py-2">{e.message}</td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <InlineAlert tone="success" title="All rows passed validation" />
              )}
              {validation.requiresApproval ? (
                <InlineAlert tone="info" title="This import needs independent approval">
                  Submitting sends the job to an approver. Nothing is posted until it is approved.
                </InlineAlert>
              ) : null}
            </div>
          ) : null}

          {step === 4 && result ? (
            <InlineAlert tone={result.status === 'AWAITING_APPROVAL' ? 'info' : 'success'} title={RESULT_TITLE[result.status]}>
              {result.committedRows.toLocaleString('en-US')} rows {result.status === 'AWAITING_APPROVAL' ? 'are waiting for approval' : 'committed'}. Job{' '}
              {validation?.jobId}. You will also find the result in Notifications.
            </InlineAlert>
          ) : null}
        </ModalBody>
        <ModalFooter>
          {step === 4 ? (
            <ModalClose asChild>
              <Button variant="primary">Done</Button>
            </ModalClose>
          ) : (
            <>
              <ModalClose asChild>
                <Button disabled={busy}>Cancel</Button>
              </ModalClose>
              {step === 1 || step === 3 ? (
                <Button onClick={() => setStep(step === 3 ? 1 : 0)} disabled={busy}>
                  Back
                </Button>
              ) : null}
              {step === 0 ? (
                <Button variant="primary" onClick={() => setStep(1)}>
                  Continue
                </Button>
              ) : null}
              {step === 1 ? (
                <Button variant="primary" onClick={validate} disabled={!file || busy}>
                  Validate file
                </Button>
              ) : null}
              {step === 3 && validation ? (
                <Button variant="primary" onClick={commit} disabled={busy || validation.validRows === 0}>
                  {busy
                    ? 'Submitting…'
                    : validation.requiresApproval
                      ? 'Submit for approval'
                      : validation.errors.length
                        ? `Import ${validation.validRows} valid rows`
                        : 'Import all rows'}
                </Button>
              ) : null}
            </>
          )}
        </ModalFooter>
      </ModalContent>
    </Modal>
  );
}

const RESULT_TITLE: Record<ImportCommitResult['status'], string> = {
  COMMITTED: 'Import committed',
  PARTIALLY_COMMITTED: 'Valid rows committed; rows with errors were skipped',
  AWAITING_APPROVAL: 'Import submitted for approval',
};
