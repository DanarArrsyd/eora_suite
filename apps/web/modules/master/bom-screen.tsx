'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, FilePlus2, FolderOpen, Pencil, Plus, Trash2 } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { z } from 'zod';
import { api, call, useMe } from '@eora/features/auth';
import type { Option } from '@eora/features/data';
import {
  DataTable,
  DomainStatusBadge,
  EnterprisePage,
  EoraForm,
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
  Drawer,
  DrawerContent,
  EmptyState,
  ErrorState,
  InlineAlert,
  LoadingState,
  PermissionDeniedState,
  Select,
  Tooltip,
  type IsoDate,
} from '@eora/ui';
import { BOMS, MADE_ITEM_TYPES, type BomVersionRow } from './engineering-resources';
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
  type Can,
  type LifecycleAction,
  type LifecycleStatus,
} from './version-ui';

/*
 * BOM page (doc 29 §1.4, doc 26 §2.5, D-B2-01, D-078, D-079, page template §3): one item's BOM versions. The version
 * shown carries its facts, the approval panel, its lines and its routing maps. A Draft is edited (lines as one list at the
 * version's ETag), submitted and approved by a second person; a map links each line to the operation that consumes it,
 * for one routing version of the same item, once both versions are locked.
 */

type BomLine = {
  id: string;
  lineNo: number;
  componentItemId: string;
  componentCode: string;
  componentName: string;
  componentType: string;
  grossQtyPer: string;
  uomId: string;
  uomCode: string;
  scrapAllowance: string;
  linePurpose: string | null;
  conversionProfileId: string | null;
};
type BomDetail = BomVersionRow & { lines: BomLine[] };
type LineInput = {
  lineNo: number;
  componentItemId: string;
  grossQtyPer: number;
  uomId: string;
  scrapAllowance: number;
  linePurpose: string | null;
  conversionProfileId: string | null;
};
type MapRow = {
  id: string;
  plantCode: string;
  bomVersionId: string;
  routingCode: string;
  routingVersionId: string;
  routingVersionNo: number;
  routingStatus: LifecycleStatus;
  bomStatus: LifecycleStatus;
  mapRevision: number;
  status: LifecycleStatus;
  changeReason: string | null;
  rejectReason: string | null;
  preparedByName: string | null;
  approvedByName: string | null;
  approvedAt: string | null;
  lineCount: number;
  bomLineCount: number;
  version: number;
};
type MapDetail = MapRow & {
  bomLines: {
    bomLineId: string;
    lineNo: number;
    componentCode: string;
    componentName: string;
    grossQtyPer: string;
    uomCode: string;
    linePurpose: string | null;
    routingOperationId: string | null;
  }[];
  operations: { id: string; opSeq: number; stepKey: string | null; processTypeName: string; workCenterCode: string }[];
};

const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });
const qty = (v: string) => Number(v).toLocaleString('en-US', { maximumFractionDigits: 6 });
const percent = (v: string) => `${(Number(v) * 100).toLocaleString('en-US', { maximumFractionDigits: 3 })} %`;
const LOCKED: LifecycleStatus[] = ['SUBMITTED', 'APPROVED'];

function toInput(l: BomLine): LineInput {
  return {
    lineNo: l.lineNo,
    componentItemId: l.componentItemId,
    grossQtyPer: Number(l.grossQtyPer),
    uomId: l.uomId,
    scrapAllowance: Number(l.scrapAllowance),
    linePurpose: l.linePurpose,
    conversionProfileId: l.conversionProfileId,
  };
}

/** Master Data → Engineering → BOMs: the list, or one item's BOM page with `?item=<id>`. */
export function BomsScreen() {
  const item = useSearchParams()?.get('item');
  return item ? <BomPage key={item} itemId={item} /> : <MasterScreen config={BOMS} />;
}

function useCan(): Can & { pending: boolean } {
  const me = useMe();
  const p = me.data?.permissions ?? [];
  return {
    pending: me.isPending,
    view: p.includes('master.routing.view'),
    create: p.includes('master.routing.create'),
    submit: p.includes('master.routing.submit'),
    approve: p.includes('master.routing.approve'),
  };
}

function useToday(): string {
  const me = useMe();
  return todayIn(me.data?.tenants.find((t) => t.id === me.data?.activeTenantId)?.timezone ?? 'Asia/Jakarta');
}

function BomPage({ itemId }: { itemId: string }) {
  const can = useCan();
  const params = useSearchParams();
  const router = useRouter();
  const client = useQueryClient();
  const [notice, setNotice] = React.useState<string | null>(null);

  const versions = useQuery({
    queryKey: ['master', 'planning.bom_versions', 'of', itemId],
    queryFn: async ({ signal }) =>
      (
        await querySource<BomVersionRow>('planning.bom_versions').list(
          { filters: { itemId }, search: '', sort: [{ id: 'versionNo', desc: true }], page: 1, pageSize: 100, view: 'all' },
          signal,
        )
      ).rows,
    enabled: can.view,
  });
  const list = versions.data;
  // a `version` from the URL counts only when it is a version of this item
  const asked = params?.get('version');
  const open = list?.find((v) => v.status === 'DRAFT' || v.status === 'SUBMITTED' || v.status === 'REJECTED');
  const selectedId = !list
    ? null
    : ((asked && list.some((v) => v.id === asked) ? asked : null) ?? open?.id ?? list.find((v) => v.inUse)?.id ?? list[0]?.id ?? null);
  const detail = useQuery({
    queryKey: ['master', 'bom-version', selectedId],
    queryFn: async () =>
      ((await call(() => api.GET('/planning/bom-versions/{version}', { params: { path: { version: selectedId! } } }))) as { data: BomDetail }).data,
    select: (v: BomDetail) => (v.itemId === itemId ? v : null),
    enabled: can.view && !!selectedId,
  });
  const refresh = () => client.invalidateQueries({ queryKey: ['master'] });
  const hrefFor = (versionId: string) => `/master/boms?item=${itemId}&version=${versionId}`;

  if (can.pending || (can.view && versions.isPending)) return <LoadingState title="Loading BOM" />;
  if (!can.view) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="BOMs are not available for your roles"
          description="Ask a tenant administrator for a role that includes routings and BOMs."
        />
      </div>
    );
  }
  if (versions.isError || !list || list.length === 0) {
    return (
      <div className="p-6 max-md:p-4">
        {versions.isError ? (
          <ErrorState
            title="The BOM could not be loaded"
            description="Check your connection and try again."
            action={
              <Button size="sm" onClick={() => void versions.refetch()}>
                Try again
              </Button>
            }
          />
        ) : (
          <EmptyState
            title="No BOM for this item"
            description="It has no BOM version, or it is not an item you can see."
            action={
              <Button size="sm" onClick={() => router.push('/master/boms')}>
                Back to BOMs
              </Button>
            }
          />
        )}
      </div>
    );
  }

  const head = list[0]!;
  const type = MADE_ITEM_TYPES.find((t) => t.value === head.itemType)?.label ?? head.itemType;
  const newVersionReason = !can.create
    ? 'Your roles do not allow changes to BOMs.'
    : open
      ? `Version ${open.versionNo} is still open. Finish or withdraw it first.`
      : undefined;

  return (
    <EnterprisePage
      title={`${head.itemCode} BOM`}
      description={`${head.itemName}. ${type}; the BOM applies to every plant.`}
      actions={
        <>
          <Button onClick={() => router.push('/master/boms')}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All BOMs
          </Button>
          <NewVersionButton
            itemId={itemId}
            itemCode={head.itemCode}
            reason={newVersionReason}
            copyFrom={list.find((v) => v.status === 'APPROVED' || v.status === 'SUPERSEDED')?.versionNo ?? null}
            onCreated={(id) => (void refresh(), router.replace(hrefFor(id)))}
          />
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
      <VersionStrip
        items={list.map((v) => ({
          id: v.id,
          label: `Version ${v.versionNo}`,
          status: v.status,
          detail: dayRange(v.effectiveFrom, v.effectiveTo),
          inUse: v.inUse,
        }))}
        selectedId={selectedId}
        hrefFor={hrefFor}
      />
      {detail.isPending && selectedId ? (
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
          version={detail.data}
          can={can}
          onChanged={refresh}
          onNotice={(m) => {
            setNotice(m);
            void refresh();
          }}
        />
      ) : null}
    </EnterprisePage>
  );
}

function NewVersionButton({
  itemId,
  itemCode,
  reason,
  copyFrom,
  onCreated,
}: {
  itemId: string;
  itemCode: string;
  reason: string | undefined;
  copyFrom: number | null;
  onCreated: (id: string) => void;
}) {
  const [open, setOpen] = React.useState(false);
  const today = useToday();
  const schema = z.object({
    effectiveFrom: z.string().min(1, 'Choose the day the new version takes effect.'),
    changeReason: z.string().trim().min(1, 'Say what changes, e.g. "Thinner coil".').max(500, 'Use at most 500 characters.'),
  });
  const button = (
    <Button
      variant="primary"
      aria-disabled={reason ? 'true' : undefined}
      aria-label={reason ? `New version. ${reason}` : undefined}
      onClick={(e) => (reason ? e.preventDefault() : setOpen(true))}
    >
      <FilePlus2 aria-hidden="true" className="size-4" />
      New version
    </Button>
  );
  return (
    <>
      {reason ? <Tooltip content={reason}>{button}</Tooltip> : button}
      <Drawer open={open} onOpenChange={setOpen}>
        <DrawerContent title={`New BOM version of ${itemCode}`} closeLabel="Close">
          <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
            <p className="m-0 text-[13.5px] text-ink-2">
              {copyFrom
                ? `Starts as a Draft with the lines of version ${copyFrom}. Version ${copyFrom} stays in use until the new version is approved and takes effect.`
                : 'Starts as a Draft. Add its lines, then submit it for approval.'}
            </p>
            <EoraForm
              label={`New BOM version of ${itemCode}`}
              schema={schema}
              defaultValues={{ effectiveFrom: '', changeReason: '' }}
              submitLabel="Create version"
              secondary={
                <Button type="button" onClick={() => setOpen(false)}>
                  Cancel
                </Button>
              }
              onSubmit={async (v) => {
                try {
                  const res = (await call(() =>
                    api.POST('/planning/bom-versions', { body: { itemId, effectiveFrom: v.effectiveFrom, changeReason: v.changeReason } }),
                  )) as { data: { id: string } };
                  setOpen(false);
                  onCreated(res.data.id);
                  return { ok: true };
                } catch (e) {
                  return conflictFailure(e, 'No version was created.');
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
          </div>
        </DrawerContent>
      </Drawer>
    </>
  );
}

function VersionBody({ version, can, onChanged, onNotice }: { version: BomDetail; can: Can; onChanged: () => void; onNotice: (m: string) => void }) {
  const editable = version.status === 'DRAFT' && can.create;
  const [detailsOpen, setDetailsOpen] = React.useState(false);
  const [editing, setEditing] = React.useState<{ open: boolean; line: BomLine | null }>({ open: false, line: null });
  const [error, setError] = React.useState<string | null>(null);

  const save = async (lines: LineInput[]) => {
    try {
      await call(() =>
        api.PUT('/planning/bom-versions/{version}/lines', {
          params: { path: { version: version.id } },
          headers: ifMatch(version.version),
          body: { lines } as never,
        }),
      );
    } finally {
      onChanged();
    }
  };
  const remove = async (line: BomLine) => {
    setError(null);
    try {
      await save(version.lines.filter((l) => l.id !== line.id).map(toInput));
    } catch (e) {
      setError(conflictMessage(e, `Line ${line.lineNo} was not removed.`));
    }
  };

  const columns: EoraColumn<BomLine>[] = [
    { id: 'lineNo', header: 'Line', lead: true, numeric: true, cell: (l) => l.lineNo },
    { id: 'component', header: 'Component', textCol: true, cell: (l) => l.componentCode, sub: (l) => l.componentName },
    { id: 'qty', header: 'Quantity per piece', numeric: true, critical: true, cell: (l) => `${qty(l.grossQtyPer)} ${l.uomCode}` },
    { id: 'scrap', header: 'Scrap allowance', numeric: true, cell: (l) => (Number(l.scrapAllowance) > 0 ? percent(l.scrapAllowance) : '—') },
    { id: 'purpose', header: 'Purpose', textCol: true, cell: (l) => l.linePurpose ?? '—' },
    { id: 'profile', header: 'Conversion profile', cell: (l) => (l.conversionProfileId ? 'Linked' : '—') },
  ];

  return (
    <div className="flex min-w-0 flex-col gap-5">
      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <Card>
          <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
            <div className="flex flex-wrap items-center gap-2">
              <CardTitle>Version {version.versionNo}</CardTitle>
              <DomainStatusBadge domain="version" status={version.status} />
              {version.inUse ? <span className="text-[13px] text-ink-2">In use today</span> : null}
            </div>
            {editable ? (
              <Button size="sm" onClick={() => setDetailsOpen(true)}>
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
        <ApprovalPanel
          label={`Version ${version.versionNo}`}
          record={version}
          can={can}
          missing={version.lines.length === 0 ? ['at least one line'] : []}
          approvedNote="Approved versions never change. Use New version to change the BOM."
          act={(action) => bomAction(version.id, action)}
          reject={(reason) =>
            call(() => api.POST('/planning/bom-versions/{version}/actions/reject', { params: { path: { version: version.id } }, body: { reason } }))
          }
          onDone={onNotice}
        />
      </div>

      <section aria-labelledby="lines-heading" className="flex min-w-0 flex-col gap-3">
        <div className="flex flex-wrap items-center justify-between gap-2">
          <h2 id="lines-heading" className="m-0 text-[15px] font-semibold text-ink">
            Lines
          </h2>
          {editable ? (
            <Button size="sm" onClick={() => setEditing({ open: true, line: null })}>
              <Plus aria-hidden="true" className="size-4" />
              Add line
            </Button>
          ) : null}
        </div>
        {error ? (
          <InlineAlert tone="danger" title="Not saved">
            {error}
          </InlineAlert>
        ) : null}
        {version.status !== 'DRAFT' ? (
          <p className="m-0 text-[13px] text-ink-2">Lines change only in a Draft version. Use New version to change them.</p>
        ) : null}
        <DataTable
          label={`Lines of version ${version.versionNo}`}
          columns={columns}
          rows={version.lines}
          getRowId={(l) => l.id}
          rowLabel={(l) => `Line ${l.lineNo} ${l.componentCode}`}
          status="ready"
          sort={[]}
          onSortChange={() => undefined}
          density="comfortable"
          empty={
            <EmptyState
              title="No lines yet"
              description={editable ? 'Add the first line. A version needs at least one before it can be submitted.' : 'This version has no lines.'}
            />
          }
          rowActions={
            editable
              ? {
                  primary: (l) => ({ id: 'edit', label: 'Edit', icon: Pencil, onSelect: () => setEditing({ open: true, line: l }) }),
                  menu: (l) => [{ id: 'remove', label: 'Remove', icon: Trash2, onSelect: () => void remove(l) }],
                }
              : undefined
          }
        />
      </section>

      <MapsSection version={version} can={can} onNotice={onNotice} />

      <DetailsDrawer version={version} open={detailsOpen} onOpenChange={setDetailsOpen} onSaved={onChanged} />
      <LineDrawer
        key={editing.line?.id ?? 'new'}
        version={version}
        line={editing.line}
        open={editing.open}
        onOpenChange={(o) => setEditing((e) => ({ ...e, open: o }))}
        save={save}
      />
    </div>
  );
}

function bomAction(version: string, action: LifecycleAction) {
  const params = { path: { version } };
  return call(() =>
    action === 'submit'
      ? api.POST('/planning/bom-versions/{version}/actions/submit', { params })
      : action === 'withdraw'
        ? api.POST('/planning/bom-versions/{version}/actions/withdraw', { params })
        : action === 'reopen'
          ? api.POST('/planning/bom-versions/{version}/actions/reopen', { params })
          : api.POST('/planning/bom-versions/{version}/actions/approve', { params }),
  );
}

function mapAction(map: string, action: LifecycleAction) {
  const params = { path: { map } };
  return call(() =>
    action === 'submit'
      ? api.POST('/planning/bom-routing-maps/{map}/actions/submit', { params })
      : action === 'withdraw'
        ? api.POST('/planning/bom-routing-maps/{map}/actions/withdraw', { params })
        : action === 'reopen'
          ? api.POST('/planning/bom-routing-maps/{map}/actions/reopen', { params })
          : api.POST('/planning/bom-routing-maps/{map}/actions/approve', { params }),
  );
}

function DetailsDrawer({
  version,
  open,
  onOpenChange,
  onSaved,
}: {
  version: BomDetail;
  open: boolean;
  onOpenChange: (o: boolean) => void;
  onSaved: () => void;
}) {
  const today = useToday();
  const schema = z.object({
    effectiveFrom: z.string().min(1, 'Choose the day this version takes effect.'),
    changeReason: z.string().trim().max(500, 'Use at most 500 characters.'),
  });
  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent title={`Version ${version.versionNo} details`} closeLabel="Close">
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
          <EoraForm
            key={`${version.id}:${version.version}`}
            label={`Version ${version.versionNo} details`}
            schema={schema}
            defaultValues={{ effectiveFrom: version.effectiveFrom, changeReason: version.changeReason ?? '' }}
            submitLabel="Save changes"
            secondary={
              <Button type="button" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
            }
            onSubmit={async (v) => {
              try {
                await call(() =>
                  api.PATCH('/planning/bom-versions/{version}', {
                    params: { path: { version: version.id } },
                    headers: ifMatch(version.version),
                    body: { effectiveFrom: v.effectiveFrom, changeReason: v.changeReason || null },
                  }),
                );
                onOpenChange(false);
                onSaved();
                return { ok: true };
              } catch (e) {
                if ((e as { status?: number } | null)?.status === 412) onSaved();
                return conflictFailure(e, 'Nothing was saved.');
              }
            }}
          >
            <FormDateField name="effectiveFrom" label="Takes effect" required min={today as IsoDate} today={today as IsoDate} hint="Today or later." />
            <FormTextareaField name="changeReason" label="What changes" maxLength={500} rows={3} />
          </EoraForm>
        </div>
      </DrawerContent>
    </Drawer>
  );
}

const LINE_SCHEMA = z.object({
  lineNo: z
    .string()
    .trim()
    .regex(/^\d{1,4}$/, 'Enter a whole number from 1 to 9999.')
    .refine((v) => Number(v) >= 1, 'Enter a whole number from 1 to 9999.'),
  componentItemId: z.string().min(1, 'Choose the component.'),
  grossQtyPer: z
    .string()
    .trim()
    .regex(/^\d+(\.\d{1,6})?$/, 'Enter a quantity with at most 6 decimals.')
    .refine((v) => Number(v) > 0, 'Enter more than 0.'),
  uomId: z.string().min(1, 'Choose the unit.'),
  scrapAllowance: z
    .string()
    .trim()
    .regex(/^$|^\d{1,2}(\.\d{1,3})?$/, 'Enter a percentage below 100 with at most 3 decimals, e.g. 2.5.'),
  linePurpose: z.string().trim().max(200, 'Use at most 200 characters.'),
  conversionProfileId: z.string(),
});

function LineDrawer({
  version,
  line,
  open,
  onOpenChange,
  save,
}: {
  version: BomDetail;
  line: BomLine | null;
  open: boolean;
  onOpenChange: (o: boolean) => void;
  save: (lines: LineInput[]) => Promise<void>;
}) {
  const next = version.lines.length ? Math.ceil((Math.max(...version.lines.map((l) => l.lineNo)) + 1) / 10) * 10 : 10;
  const components = useQuery({
    queryKey: ['master', 'picker', 'bom-components', version.itemId],
    queryFn: async ({ signal }) =>
      (await queryAll<{ id: string; code: string; name: string; baseUomId: string }>('core.items', { status: 'ACTIVE' }, signal))
        .filter((i) => i.id !== version.itemId)
        .map((i) => ({ value: i.id, label: `${i.code} · ${i.name}`, baseUomId: i.baseUomId })),
    enabled: open,
    staleTime: 30_000,
  });
  const units = useQuery({
    queryKey: ['master', 'picker', 'uoms-active'],
    queryFn: ({ signal }) => queryOptions('core.uoms', (r) => `${String(r.code)} · ${String(r.name)}`, { status: 'ACTIVE' }, signal),
    enabled: open,
    staleTime: 30_000,
  });
  const title = line ? `Line ${line.lineNo}` : 'Add line';

  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent title={title} closeLabel="Close">
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
          <EoraForm
            label={title}
            schema={LINE_SCHEMA}
            defaultValues={
              line
                ? {
                    lineNo: String(line.lineNo),
                    componentItemId: line.componentItemId,
                    grossQtyPer: String(Number(line.grossQtyPer)),
                    uomId: line.uomId,
                    scrapAllowance: Number(line.scrapAllowance) > 0 ? String(Number((Number(line.scrapAllowance) * 100).toFixed(3))) : '',
                    linePurpose: line.linePurpose ?? '',
                    conversionProfileId: line.conversionProfileId ?? '',
                  }
                : {
                    lineNo: String(next),
                    componentItemId: '',
                    grossQtyPer: '',
                    uomId: '',
                    scrapAllowance: '',
                    linePurpose: '',
                    conversionProfileId: '',
                  }
            }
            submitLabel={line ? 'Save line' : 'Add line'}
            secondary={
              <Button type="button" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
            }
            onSubmit={async (v) => {
              const no = Number(v.lineNo);
              if (version.lines.some((l) => l.lineNo === no && l.id !== line?.id)) {
                return { ok: false, message: 'Check the highlighted fields.', fieldErrors: { lineNo: `Line ${no} is already used in this version.` } };
              }
              const input: LineInput = {
                lineNo: no,
                componentItemId: v.componentItemId,
                grossQtyPer: Number(v.grossQtyPer),
                uomId: v.uomId,
                scrapAllowance: v.scrapAllowance === '' ? 0 : Number((Number(v.scrapAllowance) / 100).toFixed(5)),
                linePurpose: v.linePurpose || null,
                conversionProfileId: v.conversionProfileId || null,
              };
              const lines = [...version.lines.filter((l) => l.id !== line?.id).map(toInput), input].sort((a, b) => a.lineNo - b.lineNo);
              try {
                await save(lines);
                onOpenChange(false);
                return { ok: true };
              } catch (e) {
                // the list is validated line by line: the errors of this line go back onto its fields
                const failure = conflictFailure(e, 'The line was not saved.');
                if (failure.ok || !('fieldErrors' in failure) || !failure.fieldErrors) return failure;
                const at = lines.indexOf(input);
                const own = Object.fromEntries(
                  Object.entries(failure.fieldErrors)
                    .filter(([k]) => k.startsWith(`lines.${at}.`))
                    .map(([k, m]) => [k.slice(`lines.${at}.`.length), m]),
                );
                return Object.keys(own).length
                  ? { ok: false, message: 'Check the highlighted fields.', fieldErrors: own }
                  : { ok: false, message: Object.values(failure.fieldErrors)[0] ?? failure.message };
              }
            }}
          >
            <FormTextField name="lineNo" label="Line" required inputMode="numeric" autoComplete="off" hint="e.g. 10, 20, 30 — gaps leave room to insert." />
            <FormSelectField
              name="componentItemId"
              label="Component"
              required
              options={components.data ?? []}
              placeholder={components.isPending ? 'Loading…' : undefined}
            />
            <div className="grid grid-cols-2 gap-4 max-sm:grid-cols-1">
              <FormTextField
                name="grossQtyPer"
                label="Quantity per piece"
                required
                inputMode="decimal"
                autoComplete="off"
                hint="Gross, before scrap allowance."
              />
              <FormSelectField
                name="uomId"
                label="Unit"
                required
                options={units.data ?? []}
                placeholder={units.isPending ? 'Loading…' : undefined}
                hint="The component's base unit, or a unit it has a conversion for."
              />
            </div>
            <FormTextField
              name="scrapAllowance"
              label="Scrap allowance (%)"
              inputMode="decimal"
              autoComplete="off"
              hint="Applied once on top of the quantity, e.g. 2.5. Leave empty for none."
            />
            <FormTextField
              name="linePurpose"
              label="Purpose"
              maxLength={200}
              autoComplete="off"
              hint='Needed when the same component is on several lines, e.g. "bracket side".'
            />
            <ProfileField itemId={version.itemId} enabled={open} />
          </EoraForm>
        </div>
      </DrawerContent>
    </Drawer>
  );
}

/**
 * The conversion profile of the chosen component for this BOM's item (optional, D-080): how a coil or sheet of it turns
 * into pieces. Offered once a component is chosen; a choice that no longer fits the component is cleared.
 */
function ProfileField({ itemId, enabled }: { itemId: string; enabled: boolean }) {
  const component = useWatch({ name: 'componentItemId' }) as string;
  const chosen = useWatch({ name: 'conversionProfileId' }) as string;
  const { setValue } = useFormContext();
  const profiles = useQuery({
    queryKey: ['master', 'picker', 'conversion-profiles', itemId, component],
    queryFn: async ({ signal }) =>
      (
        await queryAll<{ id: string; inputForm: string; versionInUseNo: number | null }>(
          'planning.conversion_profiles',
          { itemId, inputItemId: component, status: 'ACTIVE' },
          signal,
          'itemCode',
        )
      ).map((p) => ({
        value: p.id,
        label: `${p.inputForm === 'COIL' ? 'Coil' : 'Sheet'} profile, ${p.versionInUseNo ? `version ${p.versionInUseNo} in use` : 'none validated yet'}`,
      })),
    enabled: enabled && !!component,
    staleTime: 30_000,
  });
  React.useEffect(() => {
    if (profiles.data && chosen && !profiles.data.some((o) => o.value === chosen)) setValue('conversionProfileId', '');
  }, [profiles.data, chosen, setValue]);
  return (
    <FormSelectField
      name="conversionProfileId"
      label="Conversion profile"
      options={profiles.data ?? []}
      placeholder={component && profiles.isPending ? 'Loading…' : 'No conversion profile'}
      hint={
        !component
          ? 'Choose the component first.'
          : profiles.isError
            ? 'Profiles could not be loaded; the line is saved without one.'
            : profiles.data && profiles.data.length === 0
              ? 'This component has no active profile for this item. Add one in Conversion Data.'
              : 'Optional. Lets reconciliation turn this material into expected pieces.'
      }
    />
  );
}

/** Routing maps of this BOM version: which operation consumes each line, per routing version of the item (D-079). */
function MapsSection({ version, can, onNotice }: { version: BomDetail; can: Can; onNotice: (m: string) => void }) {
  const params = useSearchParams();
  const router = useRouter();
  const client = useQueryClient();
  const [creating, setCreating] = React.useState(false);
  const maps = useQuery({
    queryKey: ['master', 'planning.bom_routing_maps', 'of', version.id],
    queryFn: async ({ signal }) =>
      (
        await querySource<MapRow>('planning.bom_routing_maps').list(
          {
            filters: { bomVersionId: version.id },
            search: '',
            sort: [
              { id: 'routingCode', desc: false },
              { id: 'mapRevision', desc: true },
            ],
            page: 1,
            pageSize: 100,
            view: 'all',
          },
          signal,
        )
      ).rows,
  });
  const openMap = params?.get('map') ?? null;
  const setMap = (id: string | null) => {
    const q = new URLSearchParams(params?.toString() ?? '');
    if (id) q.set('map', id);
    else q.delete('map');
    router.replace(`/master/boms?${q.toString()}`);
  };
  const locked = LOCKED.includes(version.status);
  const createReason = !can.create
    ? 'Your roles do not allow changes to BOMs.'
    : !locked
      ? 'Submit this version first; a map is drawn on lines that no longer change.'
      : undefined;

  const columns: EoraColumn<MapRow>[] = [
    { id: 'routing', header: 'Routing', lead: true, cell: (m) => m.routingCode, sub: (m) => `Plant ${m.plantCode}` },
    {
      id: 'routingVersion',
      header: 'Routing version',
      cell: (m) => (
        <span className="inline-flex items-center gap-2">
          Version {m.routingVersionNo} <DomainStatusBadge domain="version" status={m.routingStatus} />
        </span>
      ),
    },
    {
      id: 'revision',
      header: 'Map',
      cell: (m) => (
        <span className="inline-flex items-center gap-2">
          Revision {m.mapRevision} <DomainStatusBadge domain="version" status={m.status} />
        </span>
      ),
    },
    { id: 'coverage', header: 'Lines mapped', numeric: true, cell: (m) => `${m.lineCount} of ${m.bomLineCount}` },
  ];

  return (
    <section aria-labelledby="maps-heading" className="flex min-w-0 flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <div>
          <h2 id="maps-heading" className="m-0 text-[15px] font-semibold text-ink">
            Routing maps
          </h2>
          <p className="m-0 text-[13px] text-ink-2">
            Which operation of a routing consumes each line. A work order can use this BOM with a routing only through an approved map.
          </p>
        </div>
        {createReason ? (
          <Tooltip content={createReason}>
            <Button size="sm" aria-disabled="true" aria-label={`New map. ${createReason}`} onClick={(e) => e.preventDefault()}>
              <Plus aria-hidden="true" className="size-4" />
              New map
            </Button>
          </Tooltip>
        ) : (
          <Button size="sm" onClick={() => setCreating(true)}>
            <Plus aria-hidden="true" className="size-4" />
            New map
          </Button>
        )}
      </div>
      <DataTable
        label={`Routing maps of version ${version.versionNo}`}
        columns={columns}
        rows={maps.data ?? []}
        getRowId={(m) => m.id}
        rowLabel={(m) => `Map ${m.routingCode} revision ${m.mapRevision}`}
        status={maps.isError ? 'error' : maps.isPending ? 'loading' : 'ready'}
        error={maps.error}
        onRetry={() => maps.refetch()}
        sort={[]}
        onSortChange={() => undefined}
        density="comfortable"
        empty={<EmptyState title="No routing maps yet" description={createReason ?? 'Create a map for each routing this BOM is used with.'} />}
        rowActions={{ primary: (m) => ({ id: 'open', label: 'Open', icon: FolderOpen, onSelect: () => setMap(m.id) }) }}
      />
      <NewMapDrawer
        version={version}
        open={creating}
        onOpenChange={setCreating}
        onCreated={(id) => {
          setCreating(false);
          void client.invalidateQueries({ queryKey: ['master'] });
          setMap(id);
        }}
      />
      {openMap ? (
        <MapDrawer
          key={openMap}
          mapId={openMap}
          bomVersionId={version.id}
          can={can}
          onClose={() => setMap(null)}
          onOpenRevision={setMap}
          onNotice={(m) => {
            onNotice(m);
            void client.invalidateQueries({ queryKey: ['master'] });
          }}
        />
      ) : null}
    </section>
  );
}

function NewMapDrawer({
  version,
  open,
  onOpenChange,
  onCreated,
}: {
  version: BomDetail;
  open: boolean;
  onOpenChange: (o: boolean) => void;
  onCreated: (id: string) => void;
}) {
  const candidates = useQuery({
    queryKey: ['master', 'picker', 'routing-versions-of', version.itemCode],
    queryFn: async ({ signal }) =>
      (
        await querySource<{ id: string; code: string; itemCode: string; plantCode: string; versionNo: number; status: LifecycleStatus }>(
          'planning.routing_versions',
        ).list(
          {
            filters: { status: ['SUBMITTED', 'APPROVED'] },
            search: version.itemCode,
            sort: [
              { id: 'code', desc: false },
              { id: 'versionNo', desc: true },
            ],
            page: 1,
            pageSize: 200,
            view: 'all',
          },
          signal,
        )
      ).rows
        .filter((r) => r.itemCode === version.itemCode)
        .map((r) => ({ value: r.id, label: `${r.plantCode} / ${r.code} · version ${r.versionNo} (${r.status === 'APPROVED' ? 'approved' : 'submitted'})` })),
    enabled: open,
  });
  const schema = z.object({
    routingVersionId: z.string().min(1, 'Choose the routing version.'),
    changeReason: z.string().trim().max(500, 'Use at most 500 characters.'),
  });
  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent title="New routing map" closeLabel="Close">
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
          <p className="m-0 text-[13.5px] text-ink-2">
            For version {version.versionNo} of the {version.itemCode} BOM. Routing versions that are submitted or approved can be mapped; a draft routing must
            be submitted first.
          </p>
          <EoraForm
            label="New routing map"
            schema={schema}
            defaultValues={{ routingVersionId: '', changeReason: '' }}
            submitLabel="Create map"
            secondary={
              <Button type="button" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
            }
            onSubmit={async (v) => {
              try {
                const res = (await call(() =>
                  api.POST('/planning/bom-routing-maps', {
                    body: { bomVersionId: version.id, routingVersionId: v.routingVersionId, changeReason: v.changeReason || null },
                  }),
                )) as { data: { id: string } };
                onCreated(res.data.id);
                return { ok: true };
              } catch (e) {
                return conflictFailure(e, 'No map was created.');
              }
            }}
          >
            <FormSelectField
              name="routingVersionId"
              label="Routing version"
              required
              options={candidates.data ?? []}
              placeholder={candidates.isPending ? 'Loading…' : undefined}
              hint={candidates.data?.length === 0 ? `No submitted or approved routing version of ${version.itemCode} yet.` : undefined}
            />
            <FormTextareaField name="changeReason" label="Reason" maxLength={500} rows={2} />
          </EoraForm>
        </div>
      </DrawerContent>
    </Drawer>
  );
}

/** One map revision: each BOM line with the operation that consumes it, saved as one list; its own approval. */
function MapDrawer({
  mapId,
  bomVersionId,
  can,
  onClose,
  onNotice,
  onOpenRevision,
}: {
  mapId: string;
  bomVersionId: string;
  can: Can;
  onClose: () => void;
  onNotice: (m: string) => void;
  onOpenRevision: (id: string) => void;
}) {
  const client = useQueryClient();
  const map = useQuery({
    queryKey: ['master', 'bom-routing-map', mapId],
    queryFn: async () => ((await call(() => api.GET('/planning/bom-routing-maps/{map}', { params: { path: { map: mapId } } }))) as { data: MapDetail }).data,
    // a map id from the URL counts only for the BOM version on screen
    select: (m: MapDetail) => (m.bomVersionId === bomVersionId ? m : null),
  });
  const m = map.data;
  // the stored mapping, overlaid with the user's unsaved choices
  const stored = React.useMemo(
    () =>
      Object.fromEntries((m?.bomLines ?? []).filter((l) => l.routingOperationId).map((l) => [l.bomLineId, l.routingOperationId!])) as Record<string, string>,
    [m],
  );
  const [edits, setEdits] = React.useState<Record<string, string> | null>(null);
  const choice = edits ?? stored;
  const [saving, setSaving] = React.useState(false);
  const [error, setError] = React.useState<string | null>(null);
  const [saved, setSaved] = React.useState(false);

  const editable = !!m && m.status === 'DRAFT' && can.create;
  const opOptions: Option[] = (m?.operations ?? []).map((o) => ({ value: o.id, label: `${o.opSeq} · ${o.processTypeName} · ${o.workCenterCode}` }));
  const dirty = !!m && m.bomLines.some((l) => (choice[l.bomLineId] ?? null) !== l.routingOperationId);
  const unmapped = m ? m.bomLines.filter((l) => !choice[l.bomLineId]).length : 0;

  const newRevision = async () => {
    if (!m) return;
    setError(null);
    try {
      const res = (await call(() =>
        api.POST('/planning/bom-routing-maps', { body: { bomVersionId: m.bomVersionId, routingVersionId: m.routingVersionId, changeReason: null } }),
      )) as { data: { id: string } };
      void client.invalidateQueries({ queryKey: ['master'] });
      onOpenRevision(res.data.id);
    } catch (e) {
      setError(conflictMessage(e, 'No new revision was created.'));
    }
  };

  const saveMapping = async () => {
    if (!m) return;
    setError(null);
    setSaving(true);
    try {
      await call(() =>
        api.PUT('/planning/bom-routing-maps/{map}/lines', {
          params: { path: { map: m.id } },
          headers: ifMatch(m.version),
          body: { lines: Object.entries(choice).map(([bomLineId, routingOperationId]) => ({ bomLineId, routingOperationId })) },
        }),
      );
      setSaved(true);
      setEdits(null);
    } catch (e) {
      if ((e as { status?: number } | null)?.status === 412) setEdits(null);
      setError(conflictMessage(e, 'The mapping was not saved.'));
    } finally {
      setSaving(false);
      void client.invalidateQueries({ queryKey: ['master'] });
    }
  };

  const title = m ? `Map ${m.routingCode} revision ${m.mapRevision}` : 'Routing map';
  return (
    <Drawer open onOpenChange={(o) => (o ? undefined : onClose())}>
      <DrawerContent title={title} closeLabel="Close">
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
          {map.isPending ? (
            <LoadingState title="Loading map" />
          ) : map.isError || !m ? (
            <ErrorState title="The map could not be loaded" description="It does not exist, belongs to another BOM version or is outside your plants." />
          ) : (
            <>
              <dl className="m-0 grid grid-cols-[max-content_1fr] gap-x-4 gap-y-1.5 text-[13px]">
                <dt className="text-ink-2">Routing</dt>
                <dd className="m-0 text-ink">
                  {m.plantCode} / {m.routingCode}, version {m.routingVersionNo} <DomainStatusBadge domain="version" status={m.routingStatus} />
                </dd>
                <dt className="text-ink-2">Map</dt>
                <dd className="m-0 text-ink">
                  Revision {m.mapRevision} <DomainStatusBadge domain="version" status={m.status} />
                </dd>
              </dl>
              {m.status === 'REJECTED' && m.rejectReason ? (
                <InlineAlert tone="warning" title="Rejected">
                  {m.rejectReason}
                </InlineAlert>
              ) : null}
              {saved && !dirty ? <InlineAlert tone="success" title="Mapping saved" /> : null}
              {error ? (
                <InlineAlert tone="danger" title="Not saved">
                  {error}
                </InlineAlert>
              ) : null}
              <section aria-label="Lines and operations" className="flex flex-col gap-2">
                <ul className="m-0 flex list-none flex-col divide-y divide-line rounded-md border border-line p-0">
                  {m.bomLines.map((l) => (
                    <li key={l.bomLineId} className="flex flex-col gap-2 p-3">
                      <div className="text-[13.5px] text-ink">
                        <span className="font-medium">Line {l.lineNo}</span> {l.componentCode}, {qty(l.grossQtyPer)} {l.uomCode}
                        {l.linePurpose ? <span className="text-ink-2"> — {l.linePurpose}</span> : null}
                      </div>
                      <Select
                        aria-label={`Operation for line ${l.lineNo} ${l.componentCode}`}
                        value={choice[l.bomLineId] ?? ''}
                        onValueChange={(v) => {
                          setSaved(false);
                          setEdits({ ...choice, [l.bomLineId]: v });
                        }}
                        options={opOptions}
                        placeholder="Choose the operation"
                        disabled={!editable}
                      />
                    </li>
                  ))}
                </ul>
                {editable ? (
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <span className="text-[13px] text-ink-2">
                      {unmapped === 0 ? 'Every line has its operation.' : `${unmapped} of ${m.bomLines.length} lines still need an operation.`}
                    </span>
                    <Button variant="primary" disabled={saving || !dirty} onClick={() => void saveMapping()}>
                      {saving ? 'Saving…' : 'Save mapping'}
                    </Button>
                  </div>
                ) : m.status === 'DRAFT' ? null : (
                  <p className="m-0 text-[13px] text-ink-2">The mapping changes only while the map is a Draft.</p>
                )}
              </section>
              <ApprovalPanel
                label={`Map revision ${m.mapRevision}`}
                record={m}
                can={can}
                missing={unmapped > 0 || dirty ? ['an operation for every line, saved'] : []}
                approvedNote="Approved maps never change. Use New revision to change it."
                act={(action) => mapAction(m.id, action)}
                reject={(reason) =>
                  call(() => api.POST('/planning/bom-routing-maps/{map}/actions/reject', { params: { path: { map: m.id } }, body: { reason } }))
                }
                onDone={onNotice}
              />
              {m.status === 'APPROVED' && can.create ? (
                <Button onClick={() => void newRevision()}>
                  <FilePlus2 aria-hidden="true" className="size-4" />
                  New revision
                </Button>
              ) : null}
            </>
          )}
        </div>
      </DrawerContent>
    </Drawer>
  );
}
