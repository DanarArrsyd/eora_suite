'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { CalendarPlus, Copy, FilePlus2, Pencil, Plus, Trash2 } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { z } from 'zod';
import { api, call } from '@eora/features/auth';
import {
  ConfirmDialog,
  DataTable,
  EnterprisePage,
  EoraForm,
  FormDateField,
  FormSelectField,
  FormTextField,
  StatusTabs,
  type EoraColumn,
} from '@eora/features/patterns';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  Checkbox,
  EmptyState,
  ErrorState,
  InlineAlert,
  Input,
  LoadingState,
  Select,
  type IsoDate,
} from '@eora/ui';
import { formFailure } from '@/modules/admin/errors';
import { FormLayer } from './form-layer';
import { useFormParam } from './form-url';
import { queryAll } from './source';

/*
 * Master Data → Calendars (doc 40, D-142): per plant, the shift calendar versions (each shift's times per weekday with its
 * break, C-1/C-2) and the business calendar (production day start, exception days). A version in effect is read-only;
 * changes go into a new version from a later day, and a version that has not started yet may be removed (ADR-0012).
 */

type Day = { isoWeekday: number; startsAt: string; endsAt: string; breakMinutes: number; workMinutes: number };
type Shift = { id: string; code: string; name: string; days: Day[] };
type VersionStatus = 'IN_FORCE' | 'FUTURE' | 'PAST';
type ShiftVersion = {
  id: string;
  scope: 'PLANT' | 'TENANT';
  code: string;
  name: string;
  effectiveFrom: string;
  status: VersionStatus;
  removable: boolean;
  shifts: Shift[];
};
type BusinessVersion = {
  id: string;
  scope: 'PLANT' | 'TENANT';
  effectiveFrom: string;
  timezone: string;
  productionDayStartsAt: string;
  status: VersionStatus;
  removable: boolean;
};
type ExceptionDay = {
  id: string;
  date: string;
  kind: 'HOLIDAY' | 'NON_WORKING' | 'WORKING';
  description: string | null;
  scope: 'PLANT' | 'TENANT';
  editable: boolean;
};
type Calendars = {
  plant: { id: string; code: string; name: string };
  today: string;
  canManage: boolean;
  business: BusinessVersion[];
  exceptions: ExceptionDay[];
  shiftCalendars: ShiftVersion[];
};

const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const KINDS = [
  { value: 'HOLIDAY', label: 'Holiday' },
  { value: 'NON_WORKING', label: 'Non-working day' },
  { value: 'WORKING', label: 'Extra working day' },
];
const STATUS_LABEL: Record<VersionStatus, string> = { IN_FORCE: 'In force', FUTURE: 'Starts later', PAST: 'Replaced' };
const TIME = /^([01]\d|2[0-3]):[0-5]\d$/;

const hours = (minutes: number) => `${(minutes / 60).toLocaleString('en-US', { maximumFractionDigits: 2 })} h`;
const kindLabel = (k: string) => KINDS.find((x) => x.value === k)?.label ?? k;
const fmtDate = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: '2-digit', month: 'short', year: 'numeric' });

function minutesOf(time: string) {
  const [h, m] = time.split(':').map(Number);
  return (h ?? 0) * 60 + (m ?? 0);
}

function workMinutes(start: string, end: string, brk: number) {
  if (!TIME.test(start) || !TIME.test(end) || start === end) return null;
  let length = minutesOf(end) - minutesOf(start);
  if (length <= 0) length += 1440;
  return length - brk > 0 ? length - brk : null;
}

export function CalendarsScreen() {
  const router = useRouter();
  const params = useSearchParams();
  const qc = useQueryClient();
  const formUrl = useFormParam(['exception']);
  const tab = params?.get('tab') === 'business' ? 'business' : 'shifts';
  const plants = useQuery({
    queryKey: ['calendars', 'plants'],
    queryFn: ({ signal }) => queryAll<{ id: string; code: string; name: string }>('core.plants', {}, signal),
  });
  const plant = params?.get('plant') ?? plants.data?.[0]?.id ?? '';
  const data = useQuery({
    queryKey: ['calendars', plant],
    enabled: plant !== '',
    queryFn: async () => ((await call(() => api.GET('/core/plants/{plant}/calendars', { params: { path: { plant } } }))) as { data: Calendars }).data,
  });
  const [removing, setRemoving] = React.useState<{ title: string; description: string; run: () => Promise<unknown> } | null>(null);

  const setParam = (key: string, value: string) => {
    const next = new URLSearchParams(params?.toString() ?? '');
    next.set(key, value);
    router.replace(`?${next.toString()}`);
  };
  const refresh = (value: Calendars) => qc.setQueryData(['calendars', plant], value);
  const cal = data.data;

  const primary =
    cal?.canManage && !formUrl.form ? (
      tab === 'shifts' ? (
        <Button variant="primary" onClick={() => formUrl.open('shifts')}>
          <FilePlus2 aria-hidden="true" className="size-4" />
          New shift version
        </Button>
      ) : (
        <Button variant="primary" onClick={() => formUrl.open('exception')}>
          <CalendarPlus aria-hidden="true" className="size-4" />
          Add exception day
        </Button>
      )
    ) : null;

  return (
    <EnterprisePage
      title="Calendars"
      description="Working days, production day start and shift times of a plant. Versions in force never change; a change starts from a later day."
      actions={primary}
      meta={
        plants.data && plants.data.length > 1 ? (
          <Select
            aria-label="Plant"
            variant="compact"
            value={plant}
            onValueChange={(v) => setParam('plant', v)}
            options={plants.data.map((p) => ({ value: p.id, label: `${p.code} · ${p.name}` }))}
          />
        ) : cal ? (
          <span>
            Plant {cal.plant.code} · {cal.plant.name}
          </span>
        ) : null
      }
    >
      {plants.isError || data.isError ? (
        <ErrorState
          title="Calendars could not be loaded"
          action={<Button onClick={() => void (plants.isError ? plants.refetch() : data.refetch())}>Try again</Button>}
        />
      ) : !cal ? (
        <LoadingState title="Loading calendars" />
      ) : (
        <StatusTabs
          label="View"
          value={tab}
          onValueChange={(v) => setParam('tab', v)}
          items={[
            { value: 'shifts', label: 'Shifts', count: cal.shiftCalendars.length },
            { value: 'business', label: 'Working days', count: cal.exceptions.length },
          ]}
        >
          <div className="mt-4 flex flex-col gap-4">
            {tab === 'shifts' ? (
              <ShiftsTab cal={cal} onRemove={setRemoving} refresh={refresh} />
            ) : (
              <BusinessTab
                cal={cal}
                onRemove={setRemoving}
                refresh={refresh}
                onAddVersion={() => formUrl.open('business')}
                onEditException={(id) => formUrl.open('exception', { exception: id })}
              />
            )}
          </div>
        </StatusTabs>
      )}

      {cal ? (
        <>
          <ShiftVersionForm
            cal={cal}
            open={formUrl.form === 'shifts'}
            back={formUrl.closeHref}
            onClose={formUrl.close}
            onSaved={(v) => (refresh(v), formUrl.close())}
          />
          <BusinessVersionForm
            cal={cal}
            open={formUrl.form === 'business'}
            back={formUrl.closeHref}
            onClose={formUrl.close}
            onSaved={(v) => (refresh(v), formUrl.close())}
          />
          <ExceptionForm
            cal={cal}
            open={formUrl.form === 'exception'}
            exception={cal.exceptions.find((e) => e.id === formUrl.get('exception')) ?? null}
            back={formUrl.closeHref}
            onClose={formUrl.close}
            onSaved={(v) => (refresh(v), formUrl.close())}
          />
        </>
      ) : null}

      <ConfirmDialog
        open={removing !== null}
        onOpenChange={(open) => (open ? undefined : setRemoving(null))}
        title={removing?.title ?? ''}
        description={removing?.description ?? ''}
        confirmLabel="Remove"
        tone="danger"
        onConfirm={async () => {
          if (!removing) return;
          const res = (await call(removing.run as never)) as { data: Calendars };
          refresh(res.data);
          setRemoving(null);
        }}
      />
    </EnterprisePage>
  );
}

type RemoveAsk = { title: string; description: string; run: () => Promise<unknown> };

function StatusChip({ status }: { status: VersionStatus }) {
  return <Badge tone={status === 'IN_FORCE' ? 'success' : status === 'FUTURE' ? 'info' : 'neutral'}>{STATUS_LABEL[status]}</Badge>;
}

function ShiftsTab({ cal, onRemove }: { cal: Calendars; onRemove: (a: RemoveAsk) => void; refresh: (v: Calendars) => void }) {
  if (cal.shiftCalendars.length === 0) {
    return (
      <EmptyState
        title="No shift calendar yet"
        description={
          cal.canManage
            ? 'Add the shifts this plant runs, with their times per weekday. Until then capacity shows Not configured and postings carry no shift.'
            : 'No shifts are set up for this plant. Capacity shows Not configured until a planner adds them.'
        }
      />
    );
  }
  return (
    <>
      {cal.shiftCalendars.map((v) => (
        <Card key={v.id}>
          <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
            <div className="flex flex-wrap items-center gap-2">
              <CardTitle>
                {v.code} · {v.name}
              </CardTitle>
              <StatusChip status={v.status} />
              <span className="text-[13px] text-ink-2">
                From {fmtDate(v.effectiveFrom)}
                {v.scope === 'TENANT' ? ' · company default' : ''}
              </span>
            </div>
            {v.removable ? (
              <Button
                onClick={() =>
                  onRemove({
                    title: `Remove shift version ${v.code}?`,
                    description: `It has not started yet (from ${fmtDate(v.effectiveFrom)}). The version in force stays.`,
                    run: () => api.DELETE('/core/shift-calendars/{calendar}', { params: { path: { calendar: v.id } } }),
                  })
                }
              >
                <Trash2 aria-hidden="true" className="size-4" />
                Remove
              </Button>
            ) : null}
          </CardHeader>
          <CardContent className="overflow-x-auto">
            <ShiftWeek shifts={v.shifts} />
          </CardContent>
        </Card>
      ))}
    </>
  );
}

/** The week of a shift version: one row per shift, one column per weekday with its times, break and work hours. */
function ShiftWeek({ shifts }: { shifts: Shift[] }) {
  const totals = WEEKDAYS.map((_, i) => shifts.reduce((sum, s) => sum + (s.days.find((d) => d.isoWeekday === i + 1)?.workMinutes ?? 0), 0));
  return (
    <table className="w-full min-w-[720px] border-collapse text-[13px]">
      <caption className="sr-only">Shift times per weekday</caption>
      <thead>
        <tr className="border-b border-line text-left text-xs text-ink-2">
          <th scope="col" className="py-2 pr-3 font-semibold">
            Shift
          </th>
          {WEEKDAYS.map((d) => (
            <th key={d} scope="col" className="px-2 py-2 font-semibold">
              {d.slice(0, 3)}
            </th>
          ))}
        </tr>
      </thead>
      <tbody>
        {shifts.map((s) => (
          <tr key={s.id} className="border-b border-line align-top">
            <th scope="row" className="py-2 pr-3 text-left font-semibold">
              {s.code}
              <span className="block text-xs font-normal text-ink-2">{s.name}</span>
            </th>
            {WEEKDAYS.map((_, i) => {
              const d = s.days.find((x) => x.isoWeekday === i + 1);
              return (
                <td key={i} className="px-2 py-2 tabular-nums">
                  {d ? (
                    <>
                      {d.startsAt}–{d.endsAt}
                      <span className="block text-xs text-ink-2">
                        {hours(d.workMinutes)}
                        {d.breakMinutes ? ` · break ${d.breakMinutes} min` : ''}
                      </span>
                    </>
                  ) : (
                    <span className="text-ink-2">Off</span>
                  )}
                </td>
              );
            })}
          </tr>
        ))}
        <tr className="text-xs text-ink-2">
          <th scope="row" className="py-2 pr-3 text-left font-semibold">
            Work time
          </th>
          {totals.map((t, i) => (
            <td key={i} className="px-2 py-2 tabular-nums">
              {t ? hours(t) : '—'}
            </td>
          ))}
        </tr>
      </tbody>
    </table>
  );
}

function BusinessTab({
  cal,
  onRemove,
  onAddVersion,
  onEditException,
}: {
  cal: Calendars;
  onRemove: (a: RemoveAsk) => void;
  refresh: (v: Calendars) => void;
  onAddVersion: () => void;
  onEditException: (id: string) => void;
}) {
  const versionColumns: EoraColumn<BusinessVersion>[] = [
    { id: 'from', header: 'From', lead: true, cell: (v) => fmtDate(v.effectiveFrom), sub: (v) => (v.scope === 'TENANT' ? 'Company default' : 'This plant') },
    { id: 'start', header: 'Production day starts', cell: (v) => v.productionDayStartsAt },
    { id: 'zone', header: 'Time zone', cell: (v) => v.timezone },
    { id: 'status', header: 'Status', cell: (v) => <StatusChip status={v.status} /> },
  ];
  const exceptionColumns: EoraColumn<ExceptionDay>[] = [
    { id: 'date', header: 'Date', lead: true, cell: (e) => fmtDate(e.date) },
    { id: 'kind', header: 'Kind', cell: (e) => kindLabel(e.kind) },
    { id: 'description', header: 'Description', textCol: true, cell: (e) => e.description ?? '—' },
  ];
  return (
    <>
      <Card>
        <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-3">
          <CardTitle>Production day</CardTitle>
          {cal.canManage ? (
            <Button onClick={onAddVersion}>
              <Plus aria-hidden="true" className="size-4" />
              New version
            </Button>
          ) : null}
        </CardHeader>
        <CardContent>
          <p className="mt-0 mb-3 text-[13px] text-ink-2">
            Working days are Monday to Saturday. A posting before the day start belongs to the previous production day; a shift belongs wholly to the day it
            starts.
          </p>
          <DataTable
            label="Business calendar versions"
            columns={versionColumns}
            rows={cal.business}
            getRowId={(v) => v.id}
            rowLabel={(v) => `version from ${v.effectiveFrom}`}
            status="ready"
            sort={[]}
            onSortChange={() => undefined}
            density="comfortable"
            empty={<EmptyState title="No business calendar" description="Ask your administrator to set up the company calendar." />}
            rowActions={{
              primary: () => undefined,
              menu: (v) =>
                v.removable
                  ? [
                      {
                        id: 'remove',
                        label: 'Remove',
                        icon: Trash2,
                        onSelect: () =>
                          onRemove({
                            title: 'Remove this version?',
                            description: `It has not started yet (from ${fmtDate(v.effectiveFrom)}). Its exception days go with it.`,
                            run: () => api.DELETE('/core/business-calendars/{calendar}', { params: { path: { calendar: v.id } } }),
                          }),
                      },
                    ]
                  : [],
            }}
          />
        </CardContent>
      </Card>
      <Card>
        <CardHeader>
          <CardTitle>Exception days</CardTitle>
        </CardHeader>
        <CardContent>
          <DataTable
            label="Exception days"
            columns={exceptionColumns}
            rows={cal.exceptions}
            getRowId={(e) => e.id}
            rowLabel={(e) => e.date}
            status="ready"
            sort={[]}
            onSortChange={() => undefined}
            density="comfortable"
            empty={
              <EmptyState
                title="No exception days"
                description="Add holidays, a Saturday off or a Sunday worked. Planning skips non-working days and counts extra working days."
              />
            }
            rowActions={{
              primary: (e) => (e.editable ? { id: 'edit', label: 'Edit', icon: Pencil, onSelect: () => onEditException(e.id) } : undefined),
              menu: (e) =>
                e.editable
                  ? [
                      {
                        id: 'remove',
                        label: 'Remove',
                        icon: Trash2,
                        onSelect: () =>
                          onRemove({
                            title: `Remove ${fmtDate(e.date)}?`,
                            description: 'The day follows the normal working week again.',
                            run: () =>
                              api.DELETE('/core/calendar-exceptions/{exception}', { params: { path: { exception: e.id }, query: { plantId: cal.plant.id } } }),
                          }),
                      },
                    ]
                  : [],
            }}
          />
        </CardContent>
      </Card>
    </>
  );
}

type EditDay = { on: boolean; startsAt: string; endsAt: string; breakMinutes: string };
type EditShift = { key: string; code: string; name: string; days: EditDay[] };

function starting(cal: Calendars): EditShift[] {
  const base = cal.shiftCalendars.find((v) => v.status === 'IN_FORCE') ?? cal.shiftCalendars[0];
  if (base) {
    return base.shifts.map((s, n) => ({
      key: `s${n}`,
      code: s.code,
      name: s.name,
      days: WEEKDAYS.map((_, i) => {
        const d = s.days.find((x) => x.isoWeekday === i + 1);
        return d
          ? { on: true, startsAt: d.startsAt, endsAt: d.endsAt, breakMinutes: String(d.breakMinutes) }
          : { on: false, startsAt: '', endsAt: '', breakMinutes: '0' };
      }),
    }));
  }
  const day = (start: string, end: string, i: number): EditDay => ({ on: i < 6, startsAt: start, endsAt: end, breakMinutes: '60' });
  return [
    { key: 's0', code: 'S1', name: 'Shift 1', days: WEEKDAYS.map((_, i) => day('06:00', '14:00', i)) },
    { key: 's1', code: 'S2', name: 'Shift 2', days: WEEKDAYS.map((_, i) => day('14:00', '22:00', i)) },
  ];
}

function ShiftVersionForm({
  cal,
  open,
  back,
  onClose,
  onSaved,
}: {
  cal: Calendars;
  open: boolean;
  back: string;
  onClose: () => void;
  onSaved: (v: Calendars) => void;
}) {
  const first = cal.shiftCalendars.every((v) => v.scope === 'TENANT');
  const min = first ? cal.today : nextDay(cal.today);
  const [shifts, setShifts] = React.useState<EditShift[]>(() => starting(cal));
  const [problem, setProblem] = React.useState<string>();
  // a fresh copy each time the form opens (state adjusted while rendering, not in an effect)
  const [wasOpen, setWasOpen] = React.useState(open);
  if (open !== wasOpen) {
    setWasOpen(open);
    if (open) {
      setShifts(starting(cal));
      setProblem(undefined);
    }
  }
  const schema = z.object({
    code: z.string().regex(/^[A-Z0-9][A-Z0-9_-]{0,19}$/, 'Capital letters, digits, - or _, up to 20'),
    name: z.string().min(1, 'Enter a name').max(200),
    effectiveFrom: z.string().min(1, 'Choose the first day'),
  });
  const base = cal.shiftCalendars.find((v) => v.status === 'IN_FORCE');
  return (
    <FormLayer
      open={open}
      width="wide"
      title="New shift version"
      description={
        base
          ? `Starts as a copy of ${base.code}. ${base.code} stays in force until the new version's first day.`
          : 'The shifts this plant runs, each with its times per weekday. A weekday left off means the shift does not run that day.'
      }
      back={{ label: 'Back to calendars', href: back }}
      onClose={onClose}
    >
      {(guard) => (
        <EoraForm
          label="New shift version"
          schema={schema}
          defaultValues={{ code: base ? '' : 'STD', name: base?.name ?? 'Standard shifts', effectiveFrom: '' }}
          submitLabel="Create version"
          stickyActions
          onDirtyChange={guard.setDirty}
          secondary={
            <Button type="button" onClick={guard.leave}>
              Cancel
            </Button>
          }
          onSubmit={async (v) => {
            const body = {
              code: v.code,
              name: v.name,
              effectiveFrom: v.effectiveFrom,
              shifts: shifts.map((s) => ({
                code: s.code.trim().toUpperCase(),
                name: s.name.trim(),
                days: s.days.flatMap((d, i) =>
                  d.on ? [{ isoWeekday: i + 1, startsAt: d.startsAt, endsAt: d.endsAt, breakMinutes: Number(d.breakMinutes || 0) }] : [],
                ),
              })),
            };
            const bad = body.shifts.find((s) => !s.code || !s.name || s.days.length === 0);
            if (bad) {
              setProblem('Every shift needs a code, a name and at least one weekday.');
              return { ok: false, message: 'Check the shifts.' };
            }
            const wrong = shifts.flatMap((s) =>
              s.days.filter((d) => d.on && workMinutes(d.startsAt, d.endsAt, Number(d.breakMinutes || 0)) === null).map(() => s.code),
            );
            if (wrong.length) {
              setProblem(`Check the times of ${[...new Set(wrong)].join(', ')}: HH:MM, a different end, and a break shorter than the shift.`);
              return { ok: false, message: 'Check the shifts.' };
            }
            setProblem(undefined);
            try {
              const res = (await call(() => api.POST('/core/plants/{plant}/shift-calendars', { params: { path: { plant: cal.plant.id } }, body }))) as {
                data: Calendars;
              };
              guard.saved();
              onSaved(res.data);
              return { ok: true };
            } catch (e) {
              const failure = formFailure(e, 'No version was created.');
              if (!failure.ok && failure.fieldErrors && Object.keys(failure.fieldErrors).some((k) => k.startsWith('shifts'))) {
                setProblem(
                  Object.entries(failure.fieldErrors)
                    .filter(([k]) => k.startsWith('shifts'))
                    .map(([, m]) => m)
                    .join(' '),
                );
              }
              return failure;
            }
          }}
        >
          <FormTextField name="code" label="Code" required maxLength={20} hint="e.g. KMI-2S" />
          <FormTextField name="name" label="Name" required maxLength={200} />
          <FormDateField
            name="effectiveFrom"
            label="First day"
            required
            min={min as IsoDate}
            today={cal.today as IsoDate}
            hint={first ? 'Today or later.' : 'After today: days already worked keep their shifts.'}
          />
          <div className="col-span-full flex flex-col gap-4">
            {problem ? (
              <InlineAlert tone="danger" title="Check the shifts">
                {problem}
              </InlineAlert>
            ) : null}
            {shifts.map((s, n) => (
              <ShiftEditor
                key={s.key}
                shift={s}
                onChange={(next) => {
                  guard.setDirty(true);
                  setShifts((all) => all.map((x) => (x.key === s.key ? next : x)));
                }}
                onRemove={
                  shifts.length > 1
                    ? () => {
                        guard.setDirty(true);
                        setShifts((all) => all.filter((x) => x.key !== s.key));
                      }
                    : undefined
                }
                index={n}
              />
            ))}
            {shifts.length < 6 ? (
              <div>
                <Button
                  type="button"
                  onClick={() => {
                    guard.setDirty(true);
                    setShifts((all) => [
                      ...all,
                      {
                        key: `s${Date.now()}`,
                        code: `S${all.length + 1}`,
                        name: `Shift ${all.length + 1}`,
                        days: WEEKDAYS.map((_, i) => ({ on: i < 6, startsAt: '', endsAt: '', breakMinutes: '60' })),
                      },
                    ]);
                  }}
                >
                  <Plus aria-hidden="true" className="size-4" />
                  Add shift
                </Button>
              </div>
            ) : null}
          </div>
        </EoraForm>
      )}
    </FormLayer>
  );
}

function ShiftEditor({ shift, index, onChange, onRemove }: { shift: EditShift; index: number; onChange: (s: EditShift) => void; onRemove?: () => void }) {
  const setDay = (i: number, patch: Partial<EditDay>) => onChange({ ...shift, days: shift.days.map((d, j) => (j === i ? { ...d, ...patch } : d)) });
  const copyMonday = () => {
    const mon = shift.days[0]!;
    onChange({ ...shift, days: shift.days.map((d, j) => (j >= 1 && j <= 3 ? { ...mon } : d)) });
  };
  const label = shift.code || `Shift ${index + 1}`;
  return (
    <fieldset className="m-0 rounded-lg border border-line p-4">
      <legend className="px-1 text-[13px] font-semibold">{label}</legend>
      <div className="flex flex-wrap items-end gap-3">
        <label className="flex w-32 flex-col gap-1 text-[13px] font-medium">
          Code
          <Input value={shift.code} maxLength={10} onChange={(e) => onChange({ ...shift, code: e.target.value.toUpperCase() })} />
        </label>
        <label className="flex min-w-48 flex-1 flex-col gap-1 text-[13px] font-medium">
          Name
          <Input value={shift.name} maxLength={100} onChange={(e) => onChange({ ...shift, name: e.target.value })} />
        </label>
        <Button type="button" onClick={copyMonday}>
          <Copy aria-hidden="true" className="size-4" />
          Monday times to Tue–Thu
        </Button>
        {onRemove ? (
          <Button type="button" onClick={onRemove} aria-label={`Remove ${label}`}>
            <Trash2 aria-hidden="true" className="size-4" />
            Remove shift
          </Button>
        ) : null}
      </div>
      <div className="mt-3 overflow-x-auto">
        <table className="w-full min-w-[560px] border-collapse text-[13px]">
          <caption className="sr-only">Times of {label} per weekday</caption>
          <thead>
            <tr className="border-b border-line text-left text-xs text-ink-2">
              <th scope="col" className="py-2 pr-3 font-semibold">
                Day
              </th>
              <th scope="col" className="px-2 py-2 font-semibold">
                Start
              </th>
              <th scope="col" className="px-2 py-2 font-semibold">
                End
              </th>
              <th scope="col" className="px-2 py-2 font-semibold">
                Break (min)
              </th>
              <th scope="col" className="px-2 py-2 font-semibold">
                Work time
              </th>
            </tr>
          </thead>
          <tbody>
            {shift.days.map((d, i) => {
              const work = d.on ? workMinutes(d.startsAt, d.endsAt, Number(d.breakMinutes || 0)) : null;
              return (
                <tr key={i} className="border-b border-line">
                  <th scope="row" className="py-1.5 pr-3 text-left font-normal">
                    <label className="flex items-center gap-2">
                      <Checkbox checked={d.on} onChange={(e) => setDay(i, { on: e.target.checked })} aria-label={`${label} runs on ${WEEKDAYS[i]}`} />
                      {WEEKDAYS[i]}
                    </label>
                  </th>
                  <td className="px-2 py-1.5">
                    <Input
                      className="w-24"
                      inputMode="numeric"
                      placeholder="HH:MM"
                      aria-label={`${label} ${WEEKDAYS[i]} start`}
                      disabled={!d.on}
                      value={d.startsAt}
                      onChange={(e) => setDay(i, { startsAt: e.target.value })}
                    />
                  </td>
                  <td className="px-2 py-1.5">
                    <Input
                      className="w-24"
                      inputMode="numeric"
                      placeholder="HH:MM"
                      aria-label={`${label} ${WEEKDAYS[i]} end`}
                      disabled={!d.on}
                      value={d.endsAt}
                      onChange={(e) => setDay(i, { endsAt: e.target.value })}
                    />
                  </td>
                  <td className="px-2 py-1.5">
                    <Input
                      className="w-20"
                      inputMode="numeric"
                      aria-label={`${label} ${WEEKDAYS[i]} break minutes`}
                      disabled={!d.on}
                      value={d.breakMinutes}
                      onChange={(e) => setDay(i, { breakMinutes: e.target.value.replace(/\D/g, '') })}
                    />
                  </td>
                  <td className="px-2 py-1.5 text-ink-2 tabular-nums">{!d.on ? 'Off' : work === null ? 'Check the times' : hours(work)}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </fieldset>
  );
}

function nextDay(d: string) {
  const t = new Date(`${d}T00:00:00Z`);
  t.setUTCDate(t.getUTCDate() + 1);
  return t.toISOString().slice(0, 10);
}

function BusinessVersionForm({
  cal,
  open,
  back,
  onClose,
  onSaved,
}: {
  cal: Calendars;
  open: boolean;
  back: string;
  onClose: () => void;
  onSaved: (v: Calendars) => void;
}) {
  const current = cal.business.find((v) => v.status === 'IN_FORCE');
  const schema = z.object({
    effectiveFrom: z.string().min(1, 'Choose the first day'),
    productionDayStartsAt: z.string().regex(TIME, 'Enter a time as HH:MM, e.g. 06:00'),
  });
  return (
    <FormLayer
      open={open}
      title="New production day version"
      description={`From its first day, a posting before the day start belongs to the previous production day. Now: ${current?.productionDayStartsAt ?? '—'} (${current?.timezone ?? '—'}).`}
      back={{ label: 'Back to calendars', href: back }}
      onClose={onClose}
    >
      {(guard) => (
        <EoraForm
          label="New production day version"
          schema={schema}
          defaultValues={{ effectiveFrom: '', productionDayStartsAt: current?.productionDayStartsAt ?? '06:00' }}
          submitLabel="Create version"
          stickyActions
          onDirtyChange={guard.setDirty}
          secondary={
            <Button type="button" onClick={guard.leave}>
              Cancel
            </Button>
          }
          onSubmit={async (v) => {
            try {
              const res = (await call(() => api.POST('/core/plants/{plant}/business-calendars', { params: { path: { plant: cal.plant.id } }, body: v }))) as {
                data: Calendars;
              };
              guard.saved();
              onSaved(res.data);
              return { ok: true };
            } catch (e) {
              return formFailure(e, 'No version was created.');
            }
          }}
        >
          <FormDateField
            name="effectiveFrom"
            label="First day"
            required
            min={nextDay(cal.today) as IsoDate}
            today={cal.today as IsoDate}
            hint="After today: days already worked keep their production dates."
          />
          <FormTextField
            name="productionDayStartsAt"
            label="Production day starts"
            required
            inputMode="numeric"
            placeholder="HH:MM"
            hint="Usually the start of the first shift."
          />
        </EoraForm>
      )}
    </FormLayer>
  );
}

function ExceptionForm({
  cal,
  open,
  exception,
  back,
  onClose,
  onSaved,
}: {
  cal: Calendars;
  open: boolean;
  exception: ExceptionDay | null;
  back: string;
  onClose: () => void;
  onSaved: (v: Calendars) => void;
}) {
  const schema = z.object({
    date: z.string().min(1, 'Choose the day'),
    kind: z.enum(['HOLIDAY', 'NON_WORKING', 'WORKING'], { message: 'Choose the kind' }),
    description: z.string().max(200),
  });
  const title = exception ? `Exception day ${fmtDate(exception.date)}` : 'Add exception day';
  return (
    <FormLayer
      open={open}
      title={title}
      description="Planning skips holidays and non-working days, and counts an extra working day (e.g. a Sunday worked)."
      back={{ label: 'Back to calendars', href: back }}
      onClose={onClose}
    >
      {(guard) => (
        <EoraForm
          key={exception?.id ?? 'new'}
          label={title}
          schema={schema}
          defaultValues={{ date: exception?.date ?? '', kind: exception?.kind ?? 'HOLIDAY', description: exception?.description ?? '' }}
          submitLabel={exception ? 'Save changes' : 'Add day'}
          stickyActions
          onDirtyChange={guard.setDirty}
          secondary={
            <Button type="button" onClick={guard.leave}>
              Cancel
            </Button>
          }
          onSubmit={async (v) => {
            try {
              const res = (await call(() =>
                exception
                  ? api.PATCH('/core/calendar-exceptions/{exception}', {
                      params: { path: { exception: exception.id } },
                      body: { plantId: cal.plant.id, kind: v.kind, description: v.description || null },
                    })
                  : api.POST('/core/plants/{plant}/calendar-exceptions', {
                      params: { path: { plant: cal.plant.id } },
                      body: { date: v.date, kind: v.kind, description: v.description || null },
                    }),
              )) as { data: Calendars };
              guard.saved();
              onSaved(res.data);
              return { ok: true };
            } catch (e) {
              return formFailure(e, 'The day was not saved.');
            }
          }}
        >
          {exception ? null : <FormDateField name="date" label="Day" required min={cal.today as IsoDate} today={cal.today as IsoDate} hint="Today or later." />}
          <FormSelectField name="kind" label="Kind" required options={KINDS} />
          <FormTextField name="description" label="Description" maxLength={200} hint="e.g. Idul Fitri, company outing" />
        </EoraForm>
      )}
    </FormLayer>
  );
}
