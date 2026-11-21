'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, FilePlus2, Pencil, Plus, Trash2 } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { z } from 'zod';
import { api, call, useMe } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import {
  DataTable,
  DomainStatusBadge,
  EnterprisePage,
  EoraForm,
  FormCheckboxListField,
  FormDateField,
  FormSelectField,
  FormTextareaField,
  FormTextField,
  useFormContext,
  useWatch,
  type EoraColumn,
} from '@eora/features/patterns';
import {
  Button,
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  EmptyState,
  ErrorState,
  InlineAlert,
  LoadingState,
  PermissionDeniedState,
  Tooltip,
  type IsoDate,
} from '@eora/ui';
import { formFailure } from '@/modules/admin/errors';
import { FormLayer } from './form-layer';
import { useFormParam } from './form-url';
import { ROUTING_KINDS, ROUTINGS, yieldPercent, type RoutingRow } from './engineering-resources';
import { MasterScreen } from './master-screen';
import { queryAll, queryOptions, querySource } from './source';
import {
  ApprovalPanel,
  VersionStrip,
  conflictFailure,
  conflictMessage,
  dayRange,
  todayIn,
  when,
  type LifecycleAction,
  type LifecycleStatus,
} from './version-ui';

/*
 * Routing page (doc 29 §1.3, doc 26 §2.3–§2.4a, page template §3): one routing with its version history. The version
 * shown carries its header facts, its operations and the approval panel. A Draft is edited (details, operations as a
 * whole list at the version's ETag), submitted, then approved or rejected by someone who did not prepare or edit it —
 * the server and the database decide, the screen only offers what the user's roles allow.
 */

type VersionStatus = LifecycleStatus;
type VersionRow = {
  id: string;
  routingId: string;
  versionNo: number;
  status: VersionStatus;
  effectiveFrom: string;
  effectiveTo: string | null;
  changeReason: string | null;
  standardYield: string | null;
  rejectReason: string | null;
  preparedByName: string | null;
  approvedByName: string | null;
  approvedAt: string | null;
  operationCount: number;
  version: number;
};
type Operation = {
  id: string;
  opSeq: number;
  stepKey: string | null;
  processTypeId: string;
  processTypeCode: string;
  processTypeName: string;
  workCenterId: string;
  workCenterCode: string;
  description: string | null;
  stdSetupMinutes: string;
  stdCycleSeconds: string | null;
  stdGoodOutputPerHour: string | null;
  cycleBasis: 'PER_PIECE' | 'PER_STROKE' | 'PER_BATCH';
  partsPerCycle: number;
  inspectionRequired: boolean;
  isCountPoint: boolean;
  requiresTooling: boolean;
  isFinalOperation: boolean;
  machines: { machineId: string; machineCode: string; isPreferred: boolean; cycleSecondsOverride: string | null }[];
  tools: { dieId: string; dieCode: string; quantity: number; cavitiesUsed: number | null; availableInPlant: boolean }[];
  inspections: { characteristic: string; samplingNote: string | null }[];
};
type VersionDetail = VersionRow & { operations: Operation[]; pairedOutput?: { itemId: string; itemCode: string; pairsPerSheet: number } | null };
/** One operation as the PUT body expects it. */
type OperationInput = {
  opSeq: number;
  stepKey: string | null;
  processTypeId: string;
  workCenterId: string;
  description: string | null;
  stdSetupMinutes: number;
  stdCycleSeconds: number | null;
  stdGoodOutputPerHour: number | null;
  cycleBasis: Operation['cycleBasis'];
  partsPerCycle: number;
  inspectionRequired: boolean;
  isCountPoint: boolean;
  requiresTooling: boolean;
  machines: { machineId: string; isPreferred: boolean; cycleSecondsOverride: number | null }[];
  tools: { dieId: string; quantity: number; cavitiesUsed: number | null }[];
  inspections: { characteristic: string; samplingNote: string | null }[];
};

const CYCLE_BASES: Option[] = [
  { value: 'PER_PIECE', label: 'Per piece' },
  { value: 'PER_STROKE', label: 'Per stroke' },
  { value: 'PER_BATCH', label: 'Per batch' },
];
const YES_NO: Option[] = [
  { value: 'true', label: 'Yes' },
  { value: 'false', label: 'No' },
];
const basisLabel = (b: string) => CYCLE_BASES.find((o) => o.value === b)?.label.toLowerCase() ?? b;
const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });

function toInput(op: Operation): OperationInput {
  return {
    opSeq: op.opSeq,
    stepKey: op.stepKey,
    processTypeId: op.processTypeId,
    workCenterId: op.workCenterId,
    description: op.description,
    stdSetupMinutes: Number(op.stdSetupMinutes),
    stdCycleSeconds: op.stdCycleSeconds === null ? null : Number(op.stdCycleSeconds),
    stdGoodOutputPerHour: op.stdGoodOutputPerHour == null ? null : Number(op.stdGoodOutputPerHour),
    cycleBasis: op.cycleBasis,
    partsPerCycle: op.partsPerCycle,
    inspectionRequired: op.inspectionRequired,
    isCountPoint: op.isCountPoint,
    requiresTooling: op.requiresTooling,
    machines: op.machines.map((m) => ({
      machineId: m.machineId,
      isPreferred: m.isPreferred,
      cycleSecondsOverride: m.cycleSecondsOverride === null ? null : Number(m.cycleSecondsOverride),
    })),
    tools: op.tools.map((t) => ({ dieId: t.dieId, quantity: t.quantity, cavitiesUsed: t.cavitiesUsed })),
    inspections: op.inspections.map((i) => ({ characteristic: i.characteristic, samplingNote: i.samplingNote })),
  };
}

/** Master Data → Engineering → Routings: the list, or one routing's page with `?routing=<id>`. */
export function RoutingsScreen() {
  const routing = useSearchParams()?.get('routing');
  return routing ? <RoutingPage key={routing} id={routing} /> : <MasterScreen config={ROUTINGS} />;
}

function RoutingPage({ id }: { id: string }) {
  const me = useMe();
  const permissions = me.data?.permissions ?? [];
  const can = {
    view: permissions.includes('master.routing.view'),
    create: permissions.includes('master.routing.create'),
    submit: permissions.includes('master.routing.submit'),
    approve: permissions.includes('master.routing.approve'),
  };
  const params = useSearchParams();
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);

  const routing = useQuery({
    queryKey: ['master', 'planning.routings', 'one', id],
    queryFn: async ({ signal }) =>
      (await querySource<RoutingRow>('planning.routings').list({ filters: { id }, search: '', sort: [], page: 1, pageSize: 1, view: 'all' }, signal)).rows[0] ??
      null,
    enabled: can.view,
  });
  const versions = useQuery({
    queryKey: ['master', 'planning.routing_versions', 'of', id],
    queryFn: async ({ signal }) =>
      (
        await querySource<VersionRow>('planning.routing_versions').list(
          { filters: { routingId: id }, search: '', sort: [{ id: 'versionNo', desc: true }], page: 1, pageSize: 100, view: 'all' },
          signal,
        )
      ).rows,
    enabled: can.view,
  });
  const r = routing.data;
  // a `version` from the URL counts only when it is a version of this routing (a stale or edited link never selects
  // another routing's version); until the list is loaded nothing is selected
  const asked = params?.get('version');
  const selectedId = !versions.data
    ? null
    : ((asked && versions.data.some((v) => v.id === asked) ? asked : null) ?? r?.pendingVersionId ?? r?.currentVersionId ?? versions.data[0]?.id ?? null);
  const detail = useQuery({
    queryKey: ['master', 'routing-version', selectedId],
    queryFn: async () =>
      ((await call(() => api.GET('/planning/routing-versions/{version}', { params: { path: { version: selectedId! } } }))) as { data: VersionDetail }).data,
    select: (v: VersionDetail) => (v.routingId === id ? v : null),
    enabled: can.view && !!selectedId,
  });

  const refresh = () => client.invalidateQueries({ queryKey: ['master'] });
  const [statusBusy, setStatusBusy] = React.useState(false);
  const [statusError, setStatusError] = React.useState<string | null>(null);
  /** Active ↔ Inactive at the routing's version; a second active primary for the item and plant is refused (422). */
  const toggleStatus = async () => {
    if (!routing.data) return;
    setStatusError(null);
    setStatusBusy(true);
    const next = routing.data.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE';
    try {
      await call(() =>
        api.PATCH('/planning/routings/{routing}', { params: { path: { routing: id } }, headers: ifMatch(routing.data!.version), body: { status: next } }),
      );
      setNotice(next === 'ACTIVE' ? `Routing ${routing.data.code} activated` : `Routing ${routing.data.code} deactivated`);
      void refresh();
    } catch (e) {
      const err = e as { status?: number; fieldErrors?: Record<string, string> } | null;
      setStatusError(err?.status === 422 && err.fieldErrors?.status ? err.fieldErrors.status : conflictMessage(e, 'The status did not change.'));
      if (err?.status === 412) void refresh();
    } finally {
      setStatusBusy(false);
    }
  };
  const showVersion = (versionId: string) => router.replace(`/master/routings?routing=${id}&version=${versionId}`);

  if (me.isPending || (can.view && routing.isPending)) return <LoadingState title="Loading routing" />;
  if (!can.view) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Routings are not available for your roles" description="Ask a tenant administrator for a role that includes routings." />
      </div>
    );
  }
  if (routing.isError || !r) {
    return (
      <div className="p-6 max-md:p-4">
        {routing.isError ? (
          <ErrorState
            title="The routing could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void routing.refetch()}>
                Try again
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="Routing not found"
            description="It does not exist or is outside the plants you can see."
            action={
              <Button size="sm" onClick={() => router.push('/master/routings')}>
                Back to routings
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const kind = ROUTING_KINDS.find((k) => k.value === r.routingKind)?.label ?? r.routingKind;
  const hasOpenVersion = !!r.pendingVersionId;
  const newVersionReason = !can.create
    ? 'Your roles do not allow changes to routings.'
    : hasOpenVersion
      ? `Version ${r.pendingVersionNo} is still open. Finish or withdraw it first.`
      : undefined;

  return (
    <EnterprisePage
      title={`${r.itemCode} routing ${r.code}`}
      description={`${r.itemName}. ${kind} routing in plant ${r.plantCode}.`}
      meta={<DomainStatusBadge domain="master" status={r.status} />}
      actions={
        <>
          <Button onClick={() => router.push('/master/routings')}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All routings
          </Button>
          {can.create ? (
            <Button
              disabled={statusBusy}
              onClick={() => void toggleStatus()}
              aria-label={r.status === 'ACTIVE' ? `Deactivate routing ${r.code}` : `Activate routing ${r.code}`}
            >
              {r.status === 'ACTIVE' ? 'Deactivate' : 'Activate'}
            </Button>
          ) : null}
          <NewVersionButton routing={r} reason={newVersionReason} onCreated={(versionId) => (void refresh(), showVersion(versionId))} />
        </>
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
      {statusError ? (
        <InlineAlert tone="danger" title="Status not changed" className="mb-4">
          {statusError}
        </InlineAlert>
      ) : null}
      <VersionStrip
        items={(versions.data ?? []).map((v) => ({
          id: v.id,
          label: `Version ${v.versionNo}`,
          status: v.status,
          detail: dayRange(v.effectiveFrom, v.effectiveTo),
          inUse: v.id === r.currentVersionId,
        }))}
        selectedId={selectedId}
        hrefFor={(versionId) => `/master/routings?routing=${id}&version=${versionId}`}
      />
      {(detail.isPending && selectedId) || (versions.isPending && !versions.isError) ? (
        <LoadingState title="Loading version" />
      ) : detail.isError ? (
        <ErrorState
          title="The version could not be loaded"
          description="Check your connection and try again."
          action={
            <Button size="sm" onClick={() => void detail.refetch()}>
              Try again
            </Button>
          }
        />
      ) : detail.data ? (
        <VersionBody
          routing={r}
          version={detail.data}
          canEdit={can.create}
          onChanged={refresh}
          approval={
            <ApprovalPanel
              label={`Version ${detail.data.versionNo}`}
              approval={{ kind: 'routing_version', id: detail.data.id }}
              record={detail.data}
              can={can}
              missing={[!detail.data.standardYield ? 'the standard yield' : null, detail.data.operations.length === 0 ? 'at least one operation' : null].filter(
                (m): m is string => m !== null,
              )}
              approvedNote="Approved versions never change. Use New version to change the routing."
              act={(action) => routingAction(detail.data!.id, action)}
              reject={(reason) =>
                call(() =>
                  api.POST('/planning/routing-versions/{version}/actions/reject', { params: { path: { version: detail.data!.id } }, body: { reason } }),
                )
              }
              onDone={(message) => {
                setNotice(message);
                void refresh();
              }}
            />
          }
        />
      ) : (
        <EmptyState title="No version yet" description="Create the first version with New version." />
      )}
    </EnterprisePage>
  );
}

function NewVersionButton({ routing, reason, onCreated }: { routing: RoutingRow; reason: string | undefined; onCreated: (versionId: string) => void }) {
  const formUrl = useFormParam();
  const open = formUrl.form === 'new-version';
  const me = useMe();
  const zone = me.data?.tenants.find((t) => t.id === me.data?.activeTenantId)?.timezone ?? 'Asia/Jakarta';
  const today = todayIn(zone);
  const schema = z.object({
    effectiveFrom: z.string().min(1, 'Choose the day the new version takes effect.'),
    changeReason: z.string().trim().min(1, 'Say what changes, e.g. "Faster press cycle".').max(500, 'Use at most 500 characters.'),
  });
  const button = (
    <Button
      variant="primary"
      aria-disabled={reason ? 'true' : undefined}
      aria-label={reason ? `New version. ${reason}` : undefined}
      onClick={(e) => (reason ? e.preventDefault() : formUrl.open('new-version'))}
    >
      <FilePlus2 aria-hidden="true" className="size-4" />
      New version
    </Button>
  );
  return (
    <>
      {reason ? <Tooltip content={reason}>{button}</Tooltip> : button}
      <FormLayer
        open={open && !reason}
        title={`New version of ${routing.code}`}
        description={
          routing.currentVersionNo
            ? `Starts as a Draft with the operations of version ${routing.currentVersionNo}. Version ${routing.currentVersionNo} stays in use until the new version is approved and takes effect.`
            : 'Starts as a Draft. Add its operations, then submit it for approval.'
        }
        back={{ label: `Back to routing ${routing.code}`, href: formUrl.closeHref }}
        onClose={formUrl.close}
      >
        {(guard) => (
          <EoraForm
            label={`New version of ${routing.code}`}
            schema={schema}
            defaultValues={{ effectiveFrom: '', changeReason: '' }}
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
                const res = (await call(() =>
                  api.POST('/planning/routings/{routing}/versions', {
                    params: { path: { routing: routing.id } },
                    body: { effectiveFrom: v.effectiveFrom, changeReason: v.changeReason },
                  }),
                )) as { data: { id: string } };
                guard.saved();
                onCreated(res.data.id);
                return { ok: true };
              } catch (e) {
                return formFailure(e, 'No version was created.');
              }
            }}
          >
            <FormDateField
              name="effectiveFrom"
              label="Takes effect"
              required
              min={today as IsoDate}
              today={today as IsoDate}
              hint="Today or later. Past days keep the version they had."
            />
            <FormTextareaField name="changeReason" label="What changes" required maxLength={500} rows={3} />
          </EoraForm>
        )}
      </FormLayer>
    </>
  );
}

function VersionBody({
  routing,
  version,
  canEdit,
  onChanged,
  approval,
}: {
  routing: RoutingRow;
  version: VersionDetail;
  canEdit: boolean;
  onChanged: () => void;
  approval: React.ReactNode;
}) {
  const draft = version.status === 'DRAFT';
  const editable = draft && canEdit;
  const formUrl = useFormParam(['op']);
  const editingOp = formUrl.form === 'operation' ? (version.operations.find((o) => o.id === formUrl.get('op')) ?? null) : null;
  const [error, setError] = React.useState<string | null>(null);

  const save = async (operations: OperationInput[]) => {
    try {
      await call(() =>
        api.PUT('/planning/routing-versions/{version}/operations', {
          params: { path: { version: version.id } },
          headers: ifMatch(version.version),
          body: { operations } as never,
        }),
      );
    } finally {
      // success shows the saved list; a stale ETag (412) shows the latest one
      onChanged();
    }
  };

  const remove = async (op: Operation) => {
    setError(null);
    try {
      await save(version.operations.filter((o) => o.id !== op.id).map(toInput));
    } catch (e) {
      setError(conflictMessage(e, `Operation ${op.opSeq} was not removed.`));
    }
  };

  const columns: EoraColumn<Operation>[] = [
    { id: 'opSeq', header: 'Seq', lead: true, numeric: true, cell: (o) => o.opSeq, sub: (o) => (o.isFinalOperation ? 'Final operation' : undefined) },
    {
      id: 'process',
      header: 'Process',
      textCol: true,
      cell: (o) => o.processTypeName,
      sub: (o) => [o.processTypeCode, o.description].filter(Boolean).join(', '),
    },
    { id: 'workCenter', header: 'Work center', cell: (o) => o.workCenterCode },
    { id: 'setup', header: 'Setup (min)', numeric: true, cell: (o) => Number(o.stdSetupMinutes).toLocaleString('en-US') },
    {
      id: 'capacityStandard',
      header: 'Capacity standard',
      numeric: true,
      cell: (o) =>
        o.stdGoodOutputPerHour == null
          ? `${Number(o.stdCycleSeconds).toLocaleString('en-US')} s`
          : `${Number(o.stdGoodOutputPerHour).toLocaleString('en-US')} good pcs/h`,
      sub: (o) => (o.stdGoodOutputPerHour == null ? basisLabel(o.cycleBasis) : undefined),
    },
    { id: 'parts', header: 'Parts per cycle', numeric: true, cell: (o) => o.partsPerCycle },
    {
      id: 'machines',
      header: 'Machines',
      cell: (o) =>
        o.machines.length ? o.machines.map((m) => (m.isPreferred ? `${m.machineCode} (preferred)` : m.machineCode)).join(', ') : 'Any in the work center',
    },
    {
      id: 'tools',
      header: 'Dies & tooling',
      // a die moved to another plant or unlinked from the item stays on the version, flagged (B2-AT-39)
      cell: (o) =>
        o.tools.length
          ? o.tools.map((t, i) => (
              <span key={t.dieId}>
                {i > 0 ? ', ' : null}
                {t.dieCode}
                {t.availableInPlant ? null : <span className="font-medium text-stat-warn-fg"> (tooling not available in plant)</span>}
              </span>
            ))
          : o.requiresTooling
            ? 'Needed, none chosen'
            : '—',
    },
    {
      id: 'inspection',
      header: 'Inspection',
      cell: (o) => (o.inspections.length ? o.inspections.map((i) => i.characteristic).join(', ') : o.inspectionRequired ? 'Required' : '—'),
    },
    { id: 'countPoint', header: 'Reports quantity', cell: (o) => (o.isCountPoint ? 'Yes' : 'No') },
  ];

  return (
    <div className="flex min-w-0 flex-col gap-5">
      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <Card>
          <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
            <div className="flex flex-wrap items-center gap-2">
              <CardTitle>Version {version.versionNo}</CardTitle>
              <DomainStatusBadge domain="version" status={version.status} />
              {version.id === routing.currentVersionId ? <span className="text-[13px] text-ink-2">In use today</span> : null}
            </div>
            {editable ? (
              <Button size="sm" onClick={() => formUrl.open('details')}>
                <Pencil aria-hidden="true" className="size-4" />
                Edit details
              </Button>
            ) : null}
          </CardHeader>
          <CardContent>
            {version.status === 'REJECTED' && version.rejectReason ? (
              <InlineAlert tone="warning" title="Rejected" className="mb-4">
                {version.rejectReason}
              </InlineAlert>
            ) : null}
            <dl className="m-0 grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-[13.5px] max-sm:grid-cols-1 max-sm:gap-y-0.5">
              <dt className="text-ink-2">Effective</dt>
              <dd className="m-0 text-ink max-sm:mb-2">{dayRange(version.effectiveFrom, version.effectiveTo)}</dd>
              {version.pairedOutput ? (
                <>
                  <dt className="text-ink-2">Paired blanking</dt>
                  <dd className="m-0 text-ink max-sm:mb-2">
                    {routing.itemCode} + {version.pairedOutput.itemCode} · {version.pairedOutput.pairsPerSheet} pcs per side per sheet
                  </dd>
                </>
              ) : null}
              <dt className="text-ink-2">Standard yield</dt>
              <dd className="m-0 text-ink tabular-nums max-sm:mb-2">{version.standardYield ? yieldPercent(version.standardYield) : 'Not entered yet'}</dd>
              <dt className="text-ink-2">What changes</dt>
              <dd className="m-0 text-ink max-sm:mb-2">{version.changeReason ?? '—'}</dd>
              <dt className="text-ink-2">Prepared by</dt>
              <dd className="m-0 text-ink max-sm:mb-2">{version.preparedByName ?? '—'}</dd>
              {version.approvedByName ? (
                <>
                  <dt className="text-ink-2">Approved by</dt>
                  <dd className="m-0 text-ink max-sm:mb-2">
                    {version.approvedByName}, {when(version.approvedAt)}
                  </dd>
                </>
              ) : null}
            </dl>
          </CardContent>
        </Card>
        {approval}
      </div>

      <section aria-labelledby="operations-heading" className="flex min-w-0 flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="operations-heading" className="m-0 text-[15px] font-semibold text-ink">
            Operations
          </h2>
          {editable ? (
            <Button size="sm" onClick={() => formUrl.open('operation')}>
              <Plus aria-hidden="true" className="size-4" />
              Add operation
            </Button>
          ) : null}
        </div>
        {error ? (
          <InlineAlert tone="danger" title="Not saved">
            {error}
          </InlineAlert>
        ) : null}
        {!draft ? <p className="m-0 text-[13px] text-ink-2">Operations change only in a Draft version. Use New version to change them.</p> : null}
        <DataTable
          label={`Operations of version ${version.versionNo}`}
          columns={columns}
          rows={version.operations}
          getRowId={(o) => o.id}
          rowLabel={(o) => `Operation ${o.opSeq} ${o.processTypeName}`}
          status="ready"
          sort={[]}
          onSortChange={() => undefined}
          density="comfortable"
          empty={
            <EmptyState
              title="No operations yet"
              description={editable ? 'Add the first operation. A version needs at least one before it can be submitted.' : 'This version has no operations.'}
            />
          }
          rowActions={
            editable
              ? {
                  primary: (o) => ({ id: 'edit', label: 'Edit', icon: Pencil, onSelect: () => formUrl.open('operation', { op: o.id }) }),
                  menu: (o) => [{ id: 'remove', label: 'Remove', icon: Trash2, onSelect: () => void remove(o) }],
                }
              : undefined
          }
        />
      </section>

      <VersionDetailsForm
        routing={routing}
        version={version}
        open={editable && formUrl.form === 'details'}
        back={formUrl.closeHref}
        onClose={formUrl.close}
        onSaved={onChanged}
      />
      <OperationForm
        key={editingOp?.id ?? 'new'}
        routing={routing}
        version={version}
        op={editingOp}
        open={editable && formUrl.form === 'operation' && (formUrl.get('op') === null || editingOp !== null)}
        back={formUrl.closeHref}
        onClose={formUrl.close}
        save={save}
      />
    </div>
  );
}

function VersionDetailsForm({
  routing,
  version,
  open,
  back,
  onClose,
  onSaved,
}: {
  routing: RoutingRow;
  version: VersionDetail;
  open: boolean;
  back: string;
  onClose: () => void;
  onSaved: () => void;
}) {
  const me = useMe();
  const zone = me.data?.tenants.find((t) => t.id === me.data?.activeTenantId)?.timezone ?? 'Asia/Jakarta';
  const today = todayIn(zone);
  const outputs = useQuery({
    queryKey: ['master', 'routing', 'paired-output-items'],
    queryFn: ({ signal }) =>
      queryAll<{ id: string; code: string; name: string; baseUomCode: string }>(
        'core.items',
        { itemType: 'SEMI_FINISHED', lotControlled: 'true', status: 'ACTIVE' },
        signal,
      ),
    enabled: open && routing.routingKind === 'PRIMARY',
  });
  const pieceItems = (outputs.data ?? []).filter((item) => item.baseUomCode === 'PCS');
  const showPair = Boolean(version.pairedOutput) || pieceItems.some((item) => item.id === routing.itemId);
  const schema = z
    .object({
      effectiveFrom: z.string().min(1, 'Choose the day this version takes effect.'),
      standardYield: z
        .string()
        .trim()
        .regex(/^$|^\d{1,3}(\.\d{1,3})?$/, 'Enter a percentage with at most 3 decimals, e.g. 98.5.')
        .refine((v) => v === '' || (Number(v) > 0 && Number(v) <= 100), 'Enter more than 0 and at most 100.'),
      pairedItemId: z.string(),
      pairsPerSheet: z.string().trim(),
      changeReason: z.string().trim().max(500, 'Use at most 500 characters.'),
    })
    .superRefine((v, ctx) => {
      if (v.pairedItemId !== 'NONE' && !/^(?:[1-9]\d{0,4}|100000)$/.test(v.pairsPerSheet)) {
        ctx.addIssue({ code: 'custom', path: ['pairsPerSheet'], message: 'Enter whole pieces per side per sheet.' });
      }
    });
  return (
    <FormLayer
      open={open}
      title={`Version ${version.versionNo} details`}
      back={{ label: `Back to routing ${routing.code} version ${version.versionNo}`, href: back }}
      onClose={onClose}
    >
      {(guard) => (
        <EoraForm
          key={`${version.id}:${version.version}`}
          label={`Version ${version.versionNo} details`}
          schema={schema}
          defaultValues={{
            effectiveFrom: version.effectiveFrom,
            standardYield: version.standardYield ? String(Number((Number(version.standardYield) * 100).toFixed(3))) : '',
            pairedItemId: version.pairedOutput?.itemId ?? 'NONE',
            pairsPerSheet: version.pairedOutput ? String(version.pairedOutput.pairsPerSheet) : '',
            changeReason: version.changeReason ?? '',
          }}
          submitLabel="Save changes"
          stickyActions
          onDirtyChange={guard.setDirty}
          secondary={
            <Button type="button" onClick={guard.leave}>
              Cancel
            </Button>
          }
          onSubmit={async (v) => {
            try {
              await call(() =>
                api.PATCH('/planning/routing-versions/{version}', {
                  params: { path: { version: version.id } },
                  headers: ifMatch(version.version),
                  body: {
                    effectiveFrom: v.effectiveFrom,
                    standardYield: v.standardYield === '' ? null : Number((Number(v.standardYield) / 100).toFixed(5)),
                    ...(showPair
                      ? {
                          pairedItemId: v.pairedItemId === 'NONE' ? null : v.pairedItemId,
                          pairsPerSheet: v.pairedItemId === 'NONE' ? null : Number(v.pairsPerSheet),
                        }
                      : {}),
                    changeReason: v.changeReason || null,
                  },
                }),
              );
              guard.saved();
              onClose();
              onSaved();
              return { ok: true };
            } catch (e) {
              if ((e as { status?: number } | null)?.status === 412) onSaved();
              return conflictFailure(e, 'Nothing was saved.');
            }
          }}
        >
          <FormDateField name="effectiveFrom" label="Takes effect" required min={today as IsoDate} today={today as IsoDate} hint="Today or later." />
          <FormTextField
            name="standardYield"
            label="Standard yield (%)"
            inputMode="decimal"
            autoComplete="off"
            hint="Good pieces expected from the material put in, already counting normal process loss, e.g. 98.5. Needed before submitting."
          />
          {showPair ? (
            <div className="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
              <FormSelectField
                name="pairedItemId"
                label="Second output"
                options={[
                  { value: 'NONE', label: 'One output' },
                  ...pieceItems.filter((item) => item.id !== routing.itemId).map((item) => ({ value: item.id, label: `${item.code} · ${item.name}` })),
                ]}
              />
              <FormTextField
                name="pairsPerSheet"
                label="Pieces per side per sheet"
                inputMode="numeric"
                autoComplete="off"
                hint="Uses one combined good-output rate in the blanking operation."
              />
            </div>
          ) : null}
          <FormTextareaField name="changeReason" label="What changes" maxLength={500} rows={3} />
        </EoraForm>
      )}
    </FormLayer>
  );
}

const CAPACITY_BASES: Option[] = [
  { value: 'GOOD_OUTPUT', label: 'Standard good output' },
  { value: 'CYCLE_TIME', label: 'Cycle time' },
];

const OPERATION_SCHEMA = z
  .object({
    opSeq: z
      .string()
      .trim()
      .regex(/^\d{1,4}$/, 'Enter a whole number from 1 to 9999.')
      .refine((v) => Number(v) >= 1, 'Enter a whole number from 1 to 9999.'),
    stepKey: z
      .string()
      .trim()
      .toUpperCase()
      .regex(/^$|^[A-Z][A-Z0-9_-]{0,31}$/, 'Use A-Z, 0-9, "_" or "-", starting with a letter.'),
    processTypeId: z.string().min(1, 'Choose the process.'),
    workCenterId: z.string().min(1, 'Choose the work center.'),
    description: z.string().trim().max(500, 'Use at most 500 characters.'),
    stdSetupMinutes: z
      .string()
      .trim()
      .regex(/^$|^\d+(\.\d{1,2})?$/, 'Enter minutes with at most 2 decimals.'),
    capacityBasis: z.enum(['GOOD_OUTPUT', 'CYCLE_TIME']),
    stdGoodOutputPerHour: z.string().trim(),
    stdCycleSeconds: z.string().trim(),
    cycleBasis: z.string().min(1, 'Choose what one cycle produces.'),
    partsPerCycle: z
      .string()
      .trim()
      .regex(/^\d{1,6}$/, 'Enter a whole number.')
      .refine((v) => Number(v) >= 1, 'Enter at least 1.'),
    isCountPoint: z.string(),
    inspectionRequired: z.string(),
    requiresTooling: z.string(),
    machines: z.array(z.string()),
    preferredMachine: z.string(),
    tools: z.array(z.string()),
    inspections: z.string().max(5000, 'Use fewer lines.'),
  })
  .superRefine((values, ctx) => {
    if (values.capacityBasis === 'GOOD_OUTPUT') {
      if (!/^\d{1,8}(\.\d{1,3})?$/.test(values.stdGoodOutputPerHour) || Number(values.stdGoodOutputPerHour) === 0) {
        ctx.addIssue({ code: 'custom', path: ['stdGoodOutputPerHour'], message: 'Enter a positive rate with up to 8 whole digits and 3 decimals.' });
      }
    } else if (!/^\d{1,9}(\.\d{1,3})?$/.test(values.stdCycleSeconds)) {
      ctx.addIssue({ code: 'custom', path: ['stdCycleSeconds'], message: 'Enter seconds with at most 3 decimals.' });
    }
  });
type OperationForm = z.infer<typeof OPERATION_SCHEMA>;

function OperationForm({
  routing,
  version,
  op,
  open,
  back,
  onClose,
  save,
}: {
  routing: RoutingRow;
  version: VersionDetail;
  op: Operation | null;
  open: boolean;
  back: string;
  onClose: () => void;
  save: (operations: OperationInput[]) => Promise<void>;
}) {
  const nextSeq = version.operations.length ? Math.ceil((Math.max(...version.operations.map((o) => o.opSeq)) + 1) / 10) * 10 : 10;
  const processTypes = useQuery({
    queryKey: ['master', 'picker', 'process-types-active'],
    queryFn: ({ signal }) => queryOptions('planning.process_types', (r) => `${String(r.code)} · ${String(r.name)}`, { status: 'ACTIVE' }, signal),
    enabled: open,
    staleTime: 30_000,
  });
  const workCenters = useQuery({
    queryKey: ['master', 'picker', 'work-centers-of', routing.plantId],
    queryFn: ({ signal }) =>
      queryOptions('core.work_centers', (r) => `${String(r.code)} · ${String(r.name)}`, { plantId: routing.plantId, status: 'ACTIVE' }, signal),
    enabled: open,
    staleTime: 30_000,
  });
  const defaults: OperationForm = op
    ? {
        opSeq: String(op.opSeq),
        stepKey: op.stepKey ?? '',
        processTypeId: op.processTypeId,
        workCenterId: op.workCenterId,
        description: op.description ?? '',
        stdSetupMinutes: String(Number(op.stdSetupMinutes)),
        capacityBasis: op.stdGoodOutputPerHour == null ? 'CYCLE_TIME' : 'GOOD_OUTPUT',
        stdGoodOutputPerHour: op.stdGoodOutputPerHour == null ? '' : String(Number(op.stdGoodOutputPerHour)),
        stdCycleSeconds: op.stdCycleSeconds === null ? '' : String(Number(op.stdCycleSeconds)),
        cycleBasis: op.cycleBasis,
        partsPerCycle: String(op.partsPerCycle),
        isCountPoint: String(op.isCountPoint),
        inspectionRequired: String(op.inspectionRequired),
        requiresTooling: String(op.requiresTooling),
        machines: op.machines.map((m) => m.machineId),
        preferredMachine: op.machines.find((m) => m.isPreferred)?.machineId ?? '',
        tools: op.tools.map((t) => t.dieId),
        inspections: op.inspections.map((i) => (i.samplingNote ? `${i.characteristic}: ${i.samplingNote}` : i.characteristic)).join('\n'),
      }
    : {
        opSeq: String(nextSeq),
        stepKey: '',
        processTypeId: '',
        workCenterId: '',
        description: '',
        stdSetupMinutes: '0',
        capacityBasis: 'GOOD_OUTPUT',
        stdGoodOutputPerHour: '',
        stdCycleSeconds: '',
        cycleBasis: 'PER_PIECE',
        partsPerCycle: '1',
        isCountPoint: 'true',
        inspectionRequired: 'false',
        requiresTooling: 'false',
        machines: [],
        preferredMachine: '',
        tools: [],
        inspections: '',
      };
  const title = op ? `Operation ${op.opSeq}` : 'Add operation';

  return (
    <FormLayer
      open={open}
      title={title}
      back={{ label: `Back to routing ${routing.code} version ${version.versionNo}`, href: back }}
      facts={[
        { label: 'Routing', value: routing.code },
        { label: 'Version', value: `${version.versionNo} · Draft` },
      ]}
      onClose={onClose}
    >
      {(guard) => (
        <EoraForm
          label={title}
          schema={OPERATION_SCHEMA}
          defaultValues={defaults}
          submitLabel={op ? 'Save operation' : 'Add operation'}
          stickyActions
          onDirtyChange={guard.setDirty}
          secondary={
            <Button type="button" onClick={guard.leave}>
              Cancel
            </Button>
          }
          onSubmit={async (v) => {
            const seq = Number(v.opSeq);
            if (version.operations.some((o) => o.opSeq === seq && o.id !== op?.id)) {
              return { ok: false, message: 'Check the highlighted fields.', fieldErrors: { opSeq: `Sequence ${seq} is already used in this version.` } };
            }
            const input: OperationInput = {
              opSeq: seq,
              stepKey: v.stepKey || null,
              processTypeId: v.processTypeId,
              workCenterId: v.workCenterId,
              description: v.description || null,
              stdSetupMinutes: v.stdSetupMinutes === '' ? 0 : Number(v.stdSetupMinutes),
              stdCycleSeconds: v.capacityBasis === 'CYCLE_TIME' ? Number(v.stdCycleSeconds) : null,
              stdGoodOutputPerHour: v.capacityBasis === 'GOOD_OUTPUT' ? Number(v.stdGoodOutputPerHour) : null,
              cycleBasis: v.cycleBasis as OperationInput['cycleBasis'],
              partsPerCycle: Number(v.partsPerCycle),
              isCountPoint: v.isCountPoint === 'true',
              inspectionRequired: v.inspectionRequired === 'true',
              requiresTooling: v.requiresTooling === 'true',
              // attributes the drawer does not show (cycle overrides, die quantity, cavities) keep their stored values
              machines: v.machines.map((m) => {
                const kept = op?.machines.find((x) => x.machineId === m);
                return {
                  machineId: m,
                  isPreferred: m === v.preferredMachine,
                  cycleSecondsOverride: kept?.cycleSecondsOverride == null ? null : Number(kept.cycleSecondsOverride),
                };
              }),
              tools: v.tools.map((d) => {
                const kept = op?.tools.find((x) => x.dieId === d);
                return { dieId: d, quantity: kept?.quantity ?? 1, cavitiesUsed: kept?.cavitiesUsed ?? null };
              }),
              inspections: v.inspections
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean)
                .map((line) => {
                  const at = line.indexOf(':');
                  return at > 0
                    ? { characteristic: line.slice(0, at).trim(), samplingNote: line.slice(at + 1).trim() || null }
                    : { characteristic: line, samplingNote: null };
                }),
            };
            const others = version.operations.filter((o) => o.id !== op?.id).map(toInput);
            try {
              await save([...others, input].sort((a, b) => a.opSeq - b.opSeq));
              guard.saved();
              onClose();
              return { ok: true };
            } catch (e) {
              const failure = conflictFailure(e, 'The operation was not saved.');
              // the whole list is validated by index: point the message at this form instead
              return failure.ok
                ? failure
                : {
                    ok: false,
                    message: failure.message.startsWith('Check the highlighted')
                      ? 'The server refused this operation. Check the machines, dies and numbers.'
                      : failure.message,
                  };
            }
          }}
        >
          <div className="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
            <FormTextField name="opSeq" label="Sequence" required inputMode="numeric" autoComplete="off" hint="e.g. 10, 20, 30 — gaps leave room to insert." />
            <FormTextField
              name="stepKey"
              label="Step key"
              autoComplete="off"
              spellCheck={false}
              className="[&_input]:font-mono [&_input]:uppercase"
              hint="Optional, links BOM lines later."
            />
          </div>
          <FormSelectField
            name="processTypeId"
            label="Process"
            required
            options={processTypes.data ?? []}
            placeholder={processTypes.isPending ? 'Loading…' : undefined}
            hint={processTypes.data?.length === 0 ? 'Create a process type first under Engineering → Process Types.' : undefined}
          />
          <FormSelectField
            name="workCenterId"
            label="Work center"
            required
            options={workCenters.data ?? []}
            placeholder={workCenters.isPending ? 'Loading…' : undefined}
            hint={`Active work centers of plant ${routing.plantCode}.`}
          />
          <FormTextField name="description" label="Description" maxLength={500} autoComplete="off" />
          <OperationCapacityFields />
          <div className="grid grid-cols-3 gap-4 max-sm:grid-cols-1">
            <FormSelectField name="isCountPoint" label="Reports quantity" required options={YES_NO} />
            <FormSelectField name="inspectionRequired" label="Inspection" required options={YES_NO} />
            <FormSelectField name="requiresTooling" label="Needs a die" required options={YES_NO} />
          </div>
          <MachineFields />
          <ToolField routing={routing} />
          <FormTextareaField
            name="inspections"
            label="What to inspect"
            rows={3}
            hint='One characteristic per line; add the sampling after a colon, e.g. "Hole position: 1 of 50".'
          />
        </EoraForm>
      )}
    </FormLayer>
  );
}

function OperationCapacityFields() {
  const capacityBasis = useWatch({ name: 'capacityBasis' });

  return (
    <div className="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
      <FormSelectField name="capacityBasis" label="Capacity basis" required options={CAPACITY_BASES} />
      <FormTextField name="stdSetupMinutes" label="Setup time (min)" inputMode="decimal" autoComplete="off" />
      {capacityBasis === 'GOOD_OUTPUT' ? (
        <FormTextField
          name="stdGoodOutputPerHour"
          label="Standard Good Output (pcs/h)"
          required
          inputMode="decimal"
          autoComplete="off"
          hint="Good pieces in one hour of running time. Enter setup separately."
        />
      ) : (
        <FormTextField name="stdCycleSeconds" label="Cycle time (s)" required inputMode="decimal" autoComplete="off" />
      )}
      <FormSelectField name="cycleBasis" label="One cycle makes" required options={CYCLE_BASES} hint="Per stroke for presses, per batch for ovens." />
      <FormTextField name="partsPerCycle" label="Parts per cycle" required inputMode="numeric" autoComplete="off" />
    </div>
  );
}

/** Machines of the chosen work center; the list follows the work center field. */
function MachineFields() {
  const workCenterId = useWatch({ name: 'workCenterId' }) as string;
  const { setValue } = useFormContext();
  // machines belong to one work center: a different work center starts with none chosen
  const previous = React.useRef(workCenterId);
  React.useEffect(() => {
    if (previous.current === workCenterId) return;
    previous.current = workCenterId;
    setValue('machines', [], { shouldDirty: true });
    setValue('preferredMachine', '', { shouldDirty: true });
  }, [workCenterId, setValue]);
  const chosen = (useWatch({ name: 'machines' }) as string[] | undefined) ?? [];
  const machines = useQuery({
    queryKey: ['master', 'picker', 'machines-of', workCenterId],
    queryFn: ({ signal }) => queryOptions('core.machines', (r) => `${String(r.code)} · ${String(r.name)}`, { workCenterId, status: 'ACTIVE' }, signal),
    enabled: !!workCenterId,
    staleTime: 30_000,
  });
  const options = machines.data ?? [];
  const preferredOptions = options.filter((o) => chosen.includes(o.value));
  return (
    <>
      <FormCheckboxListField
        name="machines"
        label="Machines"
        options={options}
        emptyText={!workCenterId ? 'Choose the work center first.' : machines.isPending ? 'Loading machines…' : 'This work center has no active machines.'}
        hint="None chosen means any machine of the work center."
      />
      {preferredOptions.length > 1 ? (
        <FormSelectField name="preferredMachine" label="Preferred machine" options={preferredOptions} placeholder="No preference" />
      ) : null}
    </>
  );
}

/** Dies of the routing's plant linked to the item (Work Centers → Die parts). */
function ToolField({ routing }: { routing: RoutingRow }) {
  const dies = useQuery({
    queryKey: ['master', 'picker', 'dies-for', routing.itemId, routing.plantCode],
    queryFn: async ({ signal }) =>
      (
        await queryAll<{ dieId: string; code: string; dieName: string; plantCode: string }>(
          'core.die_items',
          { itemId: routing.itemId, status: 'ACTIVE' },
          signal,
        )
      )
        .filter((d) => d.plantCode === routing.plantCode)
        .map((d) => ({ value: d.dieId, label: `${d.code} · ${d.dieName}` })),
    staleTime: 30_000,
  });
  return (
    <FormCheckboxListField
      name="tools"
      label="Dies & tooling"
      options={dies.data ?? []}
      emptyText={
        dies.isPending ? 'Loading dies…' : `No die of plant ${routing.plantCode} is linked to ${routing.itemCode}. Link one under Work Centers → Die parts.`
      }
    />
  );
}

/** One lifecycle action on a routing version. */
function routingAction(version: string, action: LifecycleAction) {
  const params = { path: { version } };
  return call(() =>
    action === 'submit'
      ? api.POST('/planning/routing-versions/{version}/actions/submit', { params })
      : action === 'withdraw'
        ? api.POST('/planning/routing-versions/{version}/actions/withdraw', { params })
        : action === 'reopen'
          ? api.POST('/planning/routing-versions/{version}/actions/reopen', { params })
          : api.POST('/planning/routing-versions/{version}/actions/approve', { params }),
  );
}
