'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import { ConfirmDialog, EnterprisePage } from '@eora/features/patterns';
import {
  Badge,
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  DatePicker,
  EmptyState,
  ErrorState,
  Field,
  IconButton,
  InlineAlert,
  Input,
  LoadingState,
  PermissionDeniedState,
  Select,
  todayIn,
  type IsoDate,
} from '@eora/ui';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import { queryAll } from '@/modules/master/source';
import type { MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';

/*
 * M5 step 2 (doc 34 §1.2, D-122, D-040, BR-DEM-08): Demand → Forecast on the live API. A version per customer and plant
 * with a quantity per customer part per ISO week; the planner drafts and activates it. Firm demand of the same customer,
 * part, plant and week consumes it: the page shows forecast, firm and net per line, never their sum.
 */

type VersionStatus = 'DRAFT' | 'ACTIVE' | 'SUPERSEDED';

type ForecastRow = {
  id: string;
  code: string;
  version: number;
  status: VersionStatus;
  plantId: string;
  plantCode: string;
  customerId: string;
  customerCode: string;
  customerName: string;
  lineCount: number;
  totalQty: string;
  firstWeek: string | null;
  lastWeek: string | null;
  note: string | null;
  activatedAt: string | null;
  activatedByName: string | null;
  createdAt: string;
  createdByName: string | null;
};

type ForecastLine = {
  id: string;
  customerItemId: string;
  customerPartNo: string;
  itemCode: string;
  itemName: string;
  uomCode: string;
  uomDecimals: number;
  weekStart: string;
  qty: string;
  firmQty: string;
  netQty: string;
};

type Forecast = ForecastRow & { lines: ForecastLine[] };

const STATUS: Record<VersionStatus, { label: string; tone: 'info' | 'success' | 'neutral' }> = {
  DRAFT: { label: 'Draft', tone: 'info' },
  ACTIVE: { label: 'Active', tone: 'success' },
  SUPERSEDED: { label: 'Superseded', tone: 'neutral' },
};
const statusBadge = (s: VersionStatus) => <Badge tone={STATUS[s].tone}>{STATUS[s].label}</Badge>;
const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });
const BASE = '/demand/forecast';

export const FORECASTS: MasterResource<ForecastRow> = {
  id: 'forecast',
  resource: 'demand.forecast_versions',
  permission: { view: 'demand.forecast.view', create: 'demand.forecast.create', label: 'forecasts' },
  title: 'Forecast',
  description: 'Expected demand per customer part and week.',
  itemLabel: 'forecast versions',
  singular: 'forecast version',
  createLabel: 'New forecast version',
  searchPlaceholder: 'Search forecast or customer…',
  rowLabel: (r) => `${r.code} ${r.customerCode}`,
  openHref: (r) => `${BASE}?version=${r.id}`,
  defaultSort: [{ id: 'createdAt', desc: true }],
  filters: [
    PLANT_FILTER,
    {
      key: 'status',
      type: 'multiselect',
      source: 'static',
      label: 'Status',
      options: (Object.keys(STATUS) as VersionStatus[]).map((k) => ({ value: k, label: STATUS[k].label })),
    },
  ],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    { id: 'code', header: 'Forecast', lead: true, sortable: true, cell: (r) => <span className="font-mono">{r.code}</span>, sub: (r) => r.plantCode },
    { id: 'customerCode', header: 'Customer', sortable: true, cell: (r) => r.customerCode, sub: (r) => r.customerName },
    { id: 'lineCount', header: 'Lines', numeric: true, cell: (r) => String(r.lineCount) },
    { id: 'firstWeek', header: 'Weeks', cell: (r) => (r.firstWeek ? `${r.firstWeek} – ${r.lastWeek}` : '—') },
    { id: 'createdAt', header: 'Created', sortable: true, cell: (r) => when(r.createdAt), sub: (r) => r.createdByName ?? undefined },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => statusBadge(r.status) },
  ],
  fields: [
    { key: 'plantId', label: 'Plant', kind: 'select', required: true, options: 'plants' },
    { key: 'customerId', label: 'Customer', kind: 'select', required: true, options: 'customers' },
    {
      key: 'copyFromActive',
      label: 'Start from',
      kind: 'select',
      required: true,
      options: [
        { value: 'true', label: 'The active version (its lines)' },
        { value: 'false', label: 'Empty' },
      ],
    },
    { key: 'note', label: 'Note', kind: 'text', maxLength: 500 },
  ],
  blank: { plantId: '', customerId: '', copyFromActive: 'true', note: '' },
  toForm: () => ({}),
  savedMessage: (result) => `${(result as { data: ForecastRow }).data.code} drafted; enter its weeks next`,
  create: (v) =>
    call(() =>
      api.POST('/demand/forecast-versions', {
        body: { plantId: v.plantId!, customerId: v.customerId!, copyFromActive: v.copyFromActive === 'true', note: v.note || null },
      }),
    ),
  update: () => Promise.reject(new Error('A forecast is changed on its own page.')),
};

/** Demand → Forecast: the versions, or one version's page with `?version=<id>`. */
export function ForecastScreen() {
  const version = useSearchParams()?.get('version');
  return version ? <ForecastPage id={version} /> : <MasterScreen config={FORECASTS} />;
}

type Row = { customerItemId: string; weekStart: string; qty: string };

function ForecastPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const canView = permissions.includes('demand.forecast.view');
  const canEdit = permissions.includes('demand.forecast.create');
  const canActivate = permissions.includes('demand.forecast.post');
  const router = useRouter();
  const client = useQueryClient();
  const [rows, setRows] = React.useState<Row[] | null>(null);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [problem, setProblem] = React.useState<string | null>(null);
  const [activating, setActivating] = React.useState(false);
  const today = todayIn(me.data?.tenants.find((t) => t.id === me.data?.activeTenantId)?.timezone ?? 'Asia/Jakarta');

  const forecast = useQuery({
    queryKey: ['master', 'demand.forecast_versions', 'one', id],
    queryFn: async () =>
      (await call(() => api.GET('/demand/forecast-versions/{version}', { params: { path: { version: id } } }))) as unknown as { data: Forecast },
    enabled: canView,
    retry: false,
  });
  const f = forecast.data?.data;
  const parts = useQuery({
    queryKey: ['master', 'picker', 'customerParts', f?.customerId],
    queryFn: async () =>
      (
        await queryAll<{ id: string; code: string; itemCode: string; itemName: string }>(
          'core.customer_items',
          { customerId: f!.customerId, status: 'ACTIVE' },
          undefined,
          'code',
        )
      ).map((p): Option => ({ value: p.id, label: `${p.code} · ${p.itemCode} ${p.itemName}` })),
    enabled: !!f && f.status === 'DRAFT',
    staleTime: 30_000,
  });

  if (me.isPending || (canView && forecast.isPending)) return <LoadingState title="Loading forecast" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Forecasts are not available for your roles" description="Ask a tenant administrator for a planner role." />
      </div>
    );
  }
  if (!f) {
    return (
      <div className="p-6 max-md:p-4">
        {forecast.error instanceof ApiError && forecast.error.status === 404 ? (
          <EmptyState
            title="Forecast not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push(BASE)}>
                Back to forecasts
              </Button>
            }
          />
        ) : (
          <ErrorState
            title="The forecast could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void forecast.refetch()}>
                Try again
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const draft = f.status === 'DRAFT' && canEdit;
  const editing = rows ?? f.lines.map((l) => ({ customerItemId: l.customerItemId, weekStart: l.weekStart, qty: l.qty }));
  const dirty = rows !== null;
  const save = async () => {
    for (const [i, r] of editing.entries()) {
      if (!r.customerItemId || !r.weekStart || !/^\d+(\.\d{1,6})?$/.test(r.qty) || Number(r.qty) <= 0) {
        setProblem(`Line ${i + 1}: choose the part and the week and enter a quantity above zero.`);
        return;
      }
    }
    try {
      await call(() =>
        api.PUT('/demand/forecast-versions/{version}/lines', { params: { path: { version: id } }, headers: ifMatch(f.version), body: { lines: editing } }),
      );
      setRows(null);
      setProblem(null);
      setNotice('Forecast lines saved');
      void client.invalidateQueries({ queryKey: ['master'] });
    } catch (e) {
      const errors = e instanceof ApiError ? (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors : undefined;
      setProblem((errors ? Object.values(errors)[0]?.[0] : undefined) ?? failureMessage(e, 'The lines could not be saved.'));
    }
  };

  return (
    <EnterprisePage
      title={f.code}
      description={`${f.customerCode} · ${f.customerName} at ${f.plantCode}. One quantity per customer part and week; firm orders of that week consume it.`}
      meta={statusBadge(f.status)}
      actions={
        <div className="flex flex-wrap gap-2">
          <Button onClick={() => router.push(BASE)}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All forecasts
          </Button>
          {f.status === 'DRAFT' && canActivate ? (
            <Button variant="primary" disabled={dirty || f.lines.length === 0} onClick={() => setActivating(true)}>
              Activate
            </Button>
          ) : null}
        </div>
      }
    >
      {notice ? (
        <InlineAlert
          tone="success"
          title={notice}
          className="mb-4"
          action={
            <Button size="sm" onClick={() => setNotice(null)}>
              Dismiss
            </Button>
          }
        />
      ) : null}
      {problem ? (
        <InlineAlert tone="danger" title="Not saved" className="mb-4">
          {problem}
        </InlineAlert>
      ) : null}
      <Card>
        <CardHeader>
          <CardTitle>Weeks</CardTitle>
        </CardHeader>
        <CardContent>
          {draft ? (
            <>
              <ul aria-label="Forecast lines" className="m-0 flex list-none flex-col gap-3 p-0">
                {editing.map((r, i) => (
                  <li key={i} className="grid grid-cols-[minmax(0,1fr)_200px_140px_auto] items-end gap-3 max-md:grid-cols-1">
                    <Field label={`Line ${i + 1} customer part`}>
                      {(control) => (
                        <Select
                          {...control}
                          value={r.customerItemId}
                          onValueChange={(v) => setRows(editing.map((x, j) => (j === i ? { ...x, customerItemId: v } : x)))}
                          placeholder={parts.isPending ? 'Loading…' : 'Choose a part'}
                          options={parts.data ?? []}
                        />
                      )}
                    </Field>
                    <div className="flex flex-col gap-1.5">
                      <span aria-hidden="true" className="text-[13px] font-medium text-ink">
                        Week of
                      </span>
                      <DatePicker
                        label={`Line ${i + 1} week of`}
                        value={r.weekStart as IsoDate | ''}
                        today={today as IsoDate}
                        clearable={false}
                        onChange={(v) => setRows(editing.map((x, j) => (j === i ? { ...x, weekStart: v } : x)))}
                      />
                    </div>
                    <Field label={`Line ${i + 1} quantity`}>
                      {(control) => (
                        <Input
                          {...control}
                          inputMode="decimal"
                          className="tabular-nums"
                          value={r.qty}
                          onChange={(e) => setRows(editing.map((x, j) => (j === i ? { ...x, qty: e.target.value.trim() } : x)))}
                        />
                      )}
                    </Field>
                    <IconButton aria-label={`Remove line ${i + 1}`} onClick={() => setRows(editing.filter((_, j) => j !== i))}>
                      <Trash2 aria-hidden="true" className="size-4" />
                    </IconButton>
                  </li>
                ))}
              </ul>
              {editing.length === 0 ? <EmptyState title="No weeks yet" description="Add a line per customer part and week." /> : null}
              <div className="mt-4 flex flex-wrap justify-between gap-2">
                <Button size="sm" onClick={() => setRows([...editing, { customerItemId: '', weekStart: '', qty: '' }])}>
                  <Plus aria-hidden="true" className="size-4" />
                  Add line
                </Button>
                <div className="flex gap-2">
                  {dirty ? (
                    <Button
                      onClick={() => {
                        setRows(null);
                        setProblem(null);
                      }}
                    >
                      Discard changes
                    </Button>
                  ) : null}
                  <Button variant="primary" disabled={!dirty} onClick={() => void save()}>
                    Save lines
                  </Button>
                </div>
              </div>
              <p className="mb-0 text-[13px] text-ink-2">Any day stands for its week (Monday–Sunday); the week is saved by its Monday.</p>
            </>
          ) : null}
          {!draft || !dirty ? (
            f.lines.length === 0 ? (
              draft ? null : (
                <EmptyState title="No weeks" description="This version has no lines." />
              )
            ) : (
              <table
                className={draft ? 'mt-4 w-full border-collapse text-[13.5px]' : 'w-full border-collapse text-[13.5px]'}
                aria-label="Forecast and firm demand per week"
              >
                <thead>
                  <tr className="border-b border-line text-left text-ink-2">
                    <th className="px-2 py-2 font-medium">Week of</th>
                    <th className="px-2 py-2 font-medium">Customer part</th>
                    <th className="px-2 py-2 text-right font-medium">Forecast</th>
                    <th className="px-2 py-2 text-right font-medium">Firm orders</th>
                    <th className="px-2 py-2 text-right font-medium">Net forecast</th>
                  </tr>
                </thead>
                <tbody>
                  {f.lines.map((l) => (
                    <tr key={l.id} className="border-b border-line">
                      <td className="px-2 py-2 tabular-nums">{l.weekStart}</td>
                      <td className="px-2 py-2">
                        <span className="font-mono">{l.customerPartNo}</span> <span className="text-ink-2">· {l.itemCode}</span>
                      </td>
                      <td className="px-2 py-2 text-right tabular-nums">
                        {l.qty} {l.uomCode}
                      </td>
                      <td className="px-2 py-2 text-right tabular-nums">{l.firmQty}</td>
                      <td className="px-2 py-2 text-right font-semibold tabular-nums">{l.netQty}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            )
          ) : null}
        </CardContent>
      </Card>
      <ConfirmDialog
        open={activating}
        onOpenChange={setActivating}
        title="Activate this forecast"
        description={`Planning uses ${f.code} for ${f.customerCode} at ${f.plantCode} from now on; the active version becomes Superseded.`}
        confirmLabel="Activate"
        onConfirm={async () => {
          await call(() =>
            api.POST('/demand/forecast-versions/{version}/actions/activate', { params: { path: { version: id } }, headers: ifMatch(f.version) }),
          );
          setActivating(false);
          setNotice(`${f.code} is the active forecast`);
          void client.invalidateQueries({ queryKey: ['master'] });
        }}
      />
    </EnterprisePage>
  );
}
