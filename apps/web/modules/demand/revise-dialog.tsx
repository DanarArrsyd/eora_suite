import * as React from 'react';
import { Plus, Trash2 } from 'lucide-react';
import { ApiError, api, call, failureMessage } from '@eora/features/auth';
import { Button, DatePicker, Field, IconButton, InlineAlert, Input, Modal, ModalBody, ModalContent, ModalFooter, Textarea, type IsoDate } from '@eora/ui';

/*
 * M5 step 2 (D-122, D-038, BR-DEM-11): revise a firm delivery of a confirmed order. The old delivery is kept as
 * Superseded with what it delivered; the new deliveries (one, or several to split) take the next revision and the old
 * outstanding goes to them in order.
 */

export type RevisedSchedule = { id: string; requirementDate: string; qty: string; deliveredQty: string; outstandingQty: string };

type Row = { date: string; qty: string };

const scaled = (qty: string): bigint => {
  const [whole = '0', fraction = ''] = qty.split('.');
  return BigInt(whole + fraction.padEnd(6, '0').slice(0, 6));
};

export function ReviseDialog({
  open,
  onOpenChange,
  orderId,
  orderVersion,
  schedule,
  uomCode,
  uomDecimals,
  today,
  onRevised,
}: {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  orderId: string;
  orderVersion: number;
  schedule: RevisedSchedule | null;
  uomCode: string;
  uomDecimals: number;
  today: string;
  onRevised: (message: string) => void;
}) {
  if (!schedule) return null;
  return (
    <Modal open={open} onOpenChange={onOpenChange}>
      <ModalContent title="Revise this delivery" closeLabel="Close" size="lg">
        <ReviseForm
          key={`${schedule.id}:${String(open)}`}
          orderId={orderId}
          orderVersion={orderVersion}
          schedule={schedule}
          uomCode={uomCode}
          uomDecimals={uomDecimals}
          today={today}
          onCancel={() => onOpenChange(false)}
          onRevised={onRevised}
        />
      </ModalContent>
    </Modal>
  );
}

function ReviseForm({
  orderId,
  orderVersion,
  schedule,
  uomCode,
  uomDecimals,
  today,
  onCancel,
  onRevised,
}: {
  orderId: string;
  orderVersion: number;
  schedule: RevisedSchedule;
  uomCode: string;
  uomDecimals: number;
  today: string;
  onCancel: () => void;
  onRevised: (message: string) => void;
}) {
  const [rows, setRows] = React.useState<Row[]>([{ date: schedule.requirementDate < today ? today : schedule.requirementDate, qty: schedule.outstandingQty }]);
  const [reason, setReason] = React.useState('');
  const [problem, setProblem] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);

  const pattern = uomDecimals === 0 ? /^\d+$/ : new RegExp(`^\\d+(\\.\\d{1,${uomDecimals}})?$`);
  const total = rows.reduce((sum, r) => (pattern.test(r.qty) ? sum + scaled(r.qty) : sum), 0n);
  const check = (): string | null => {
    if (reason.trim().length < 3) return 'Give the reason in at least 3 characters.';
    for (const [i, r] of rows.entries()) {
      if (!r.date) return `Delivery ${i + 1}: choose the date.`;
      if (r.date < today) return `Delivery ${i + 1}: choose today (${today}) or a later day.`;
      if (!pattern.test(r.qty) || scaled(r.qty) <= 0n)
        return `Delivery ${i + 1}: enter ${uomDecimals === 0 ? 'a whole number' : `a quantity with at most ${uomDecimals} decimals`} above zero.`;
    }
    return null;
  };
  const submit = async () => {
    const why = check();
    setProblem(why);
    if (why) return;
    setBusy(true);
    try {
      await call(() =>
        api.POST('/demand/sales-orders/{order}/schedule-lines/{schedule}/actions/revise', {
          params: { path: { order: orderId, schedule: schedule.id } },
          headers: { 'If-Match': `"${orderVersion}"` },
          body: { reason: reason.trim(), schedules: rows.map((r) => ({ requirementDate: r.date, qty: r.qty })) },
        }),
      );
      onRevised(rows.length === 1 ? 'Delivery revised' : `Delivery split into ${rows.length}`);
    } catch (e) {
      const errors = e instanceof ApiError ? (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors : undefined;
      setProblem((errors ? Object.values(errors)[0]?.[0] : undefined) ?? failureMessage(e, 'The delivery could not be revised.'));
    } finally {
      setBusy(false);
    }
  };

  return (
    <>
      <ModalBody>
        <p className="mt-0 mb-3 text-ink-2">
          {schedule.qty} {uomCode} on {schedule.requirementDate}, {schedule.deliveredQty} delivered, {schedule.outstandingQty} outstanding. It stays on the
          order as Superseded with what it delivered; the deliveries below replace it. Add a row to split it over several days.
        </p>
        {problem ? (
          <InlineAlert tone="danger" title="Not revised" className="mb-3">
            {problem}
          </InlineAlert>
        ) : null}
        <ul aria-label="New deliveries" className="m-0 flex list-none flex-col gap-3 p-0">
          {rows.map((r, i) => (
            <li key={i} className="grid grid-cols-[minmax(0,1fr)_160px_auto] items-end gap-3 max-md:grid-cols-[minmax(0,1fr)_auto]">
              <div className="flex flex-col gap-1.5">
                <span aria-hidden="true" className="text-[13px] font-medium text-ink">
                  Delivery {i + 1} date
                </span>
                <DatePicker
                  label={`Delivery ${i + 1} date`}
                  value={r.date as IsoDate | ''}
                  min={today as IsoDate}
                  today={today as IsoDate}
                  clearable={false}
                  onChange={(v) => setRows(rows.map((x, j) => (j === i ? { ...x, date: v } : x)))}
                />
              </div>
              <Field label={`Delivery ${i + 1} quantity (${uomCode})`}>
                {(control) => (
                  <Input
                    {...control}
                    inputMode={uomDecimals === 0 ? 'numeric' : 'decimal'}
                    className="tabular-nums"
                    value={r.qty}
                    onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, qty: e.target.value.trim() } : x)))}
                  />
                )}
              </Field>
              <IconButton aria-label={`Remove delivery ${i + 1}`} disabled={rows.length === 1} onClick={() => setRows(rows.filter((_, j) => j !== i))}>
                <Trash2 aria-hidden="true" className="size-4" />
              </IconButton>
            </li>
          ))}
        </ul>
        <div className="mt-3 flex flex-wrap items-center justify-between gap-2">
          <Button size="sm" onClick={() => setRows([...rows, { date: '', qty: '' }])} disabled={rows.length >= 20}>
            <Plus aria-hidden="true" className="size-4" />
            Add delivery
          </Button>
          <span className="text-[13px] text-ink-2 tabular-nums">
            New total {(Number(total) / 1e6).toLocaleString('en-US', { maximumFractionDigits: 6 })} {uomCode}
          </span>
        </div>
        <Field label="Reason" required className="mt-4">
          {(control) => <Textarea {...control} rows={2} maxLength={500} value={reason} onChange={(e) => setReason(e.target.value)} />}
        </Field>
      </ModalBody>
      <ModalFooter>
        <Button onClick={onCancel}>Cancel</Button>
        <Button variant="primary" onClick={() => void submit()} disabled={busy}>
          Revise delivery
        </Button>
      </ModalFooter>
    </>
  );
}
