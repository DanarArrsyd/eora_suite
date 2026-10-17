'use client';

import * as React from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, FilePlus2, Pencil } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { z } from 'zod';
import { api, call, useMe } from '@eora/features/auth';
import { DomainStatusBadge, EnterprisePage, EoraForm, FormDateField, FormTextareaField, FormTextField } from '@eora/features/patterns';
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
  Tooltip,
  type IsoDate,
} from '@eora/ui';
import {
  CONVERSION_PROFILES,
  INPUT_FORMS,
  NET_WEIGHTS,
  kgPerPiece,
  type ConversionProfileRow,
  type ItemWeightRow,
  type ProfileVersionRow,
} from './conversion-resources';
import { MasterGroupScreen, type MasterGroup } from './master-screen';
import { queryAll, querySource } from './source';
import { ApprovalPanel, VersionStrip, conflictFailure, dayRange, todayIn, when, type Can, type LifecycleAction } from './version-ui';

/*
 * Conversion data pages (doc 29 §1.5, doc 02, ADR-0005, D-080, page template §3). Net weights: one item's versions.
 * Conversion profiles: one (part, material) pair's versions with the formula inputs, each in its fixed unit. A Draft is
 * edited, submitted and validated by a second person; a validator may retire a validated version. Values are stored, not
 * calculated here.
 */

type ProfileInput = { key: string; label: string; unit: string; value: string | null };
type ProfileVersion = ProfileVersionRow & { inputs: ProfileInput[] };

const ifMatch = (version: number) => ({ 'If-Match': `"${version}"` });
const GROUP: MasterGroup = { title: 'Conversion Data', parts: [NET_WEIGHTS as never, CONVERSION_PROFILES as never] };
const OPEN = ['DRAFT', 'SUBMITTED', 'REJECTED'];
/** A number as the server returns it (exact text) shown without trailing zeros. */
const plain = (v: string) => (v.includes('.') ? v.replace(/\.?0+$/, '') : v);

/** Master Data → Engineering → Conversion Data: the two lists, or one record's page (`?weight=<item>` / `?profile=<id>`). */
export function ConversionDataScreen() {
  const params = useSearchParams();
  const weight = params?.get('weight');
  const profile = params?.get('profile');
  if (weight) return <WeightPage key={weight} itemId={weight} />;
  if (profile) return <ProfilePage key={profile} profileId={profile} />;
  return <MasterGroupScreen group={GROUP} />;
}

function useCan(): Can & { pending: boolean } {
  const me = useMe();
  const p = me.data?.permissions ?? [];
  return {
    pending: me.isPending,
    view: p.includes('master.conversion.view'),
    create: p.includes('master.conversion.create'),
    submit: p.includes('master.conversion.submit'),
    approve: p.includes('master.conversion.approve'),
  };
}

function useToday(): string {
  const me = useMe();
  return todayIn(me.data?.tenants.find((t) => t.id === me.data?.activeTenantId)?.timezone ?? 'Asia/Jakarta');
}

/**
 * Every version of one record, newest first (all pages: a link may ask for any old version), and the one to show: the
 * URL's, else the open one, else the one in use.
 */
function useVersions<T extends { id: string; status: string; inUse: boolean; versionNo: number }>(
  resource: string,
  filters: Record<string, string>,
  enabled: boolean,
) {
  const params = useSearchParams();
  const versions = useQuery({
    queryKey: ['master', resource, 'of', filters],
    queryFn: async ({ signal }) => (await queryAll<T>(resource, filters, signal, 'versionNo')).sort((a, b) => b.versionNo - a.versionNo),
    enabled,
  });
  const list = versions.data;
  const asked = params?.get('version');
  const open = list?.find((v) => OPEN.includes(v.status));
  const selectedId = !list
    ? null
    : ((asked && list.some((v) => v.id === asked) ? asked : null) ?? open?.id ?? list.find((v) => v.inUse)?.id ?? list[0]?.id ?? null);
  return { versions, list, open, selectedId };
}

function Notice({ notice, onDismiss }: { notice: string | null; onDismiss: () => void }) {
  return notice ? (
    <InlineAlert
      tone="success"
      title={notice}
      className="mb-4"
      action={
        <Button size="sm" onClick={onDismiss}>
          Dismiss
        </Button>
      }
    />
  ) : null;
}

function PageGate({
  can,
  loading,
  failed,
  empty,
  retry,
}: {
  can: Can & { pending: boolean };
  loading: boolean;
  failed: boolean;
  empty: boolean;
  retry: () => void;
}) {
  const router = useRouter();
  if (can.pending || (can.view && loading)) return <LoadingState title="Loading conversion data" />;
  return (
    <div className="p-6 max-md:p-4">
      {!can.view ? (
        <PermissionDeniedState
          title="Conversion data is not available for your roles"
          description="Ask a tenant administrator for a role that includes conversion data."
        />
      ) : failed ? (
        <ErrorState
          title="The conversion data could not be loaded"
          description="Check your connection and try again."
          action={
            <Button size="sm" onClick={retry}>
              Try again
            </Button>
          }
        />
      ) : empty ? (
        <EmptyState
          title="Nothing to show"
          description="This record has no version, or it is not one you can see."
          action={
            <Button size="sm" onClick={() => router.push('/master/conversion-data')}>
              Back to conversion data
            </Button>
          }
        />
      ) : null}
    </div>
  );
}

/** Facts of the version shown: status, range, reason, people. */
function FactsCard({
  title,
  row,
  editable,
  onEdit,
  children,
}: {
  title: string;
  row: ItemWeightRow | ProfileVersion;
  editable: boolean;
  onEdit: () => void;
  children?: React.ReactNode;
}) {
  return (
    <Card>
      <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-3">
        <div className="flex flex-wrap items-center gap-2">
          <CardTitle>{title}</CardTitle>
          <DomainStatusBadge domain="version" status={row.status} />
          {row.inUse ? <span className="text-[13px] text-ink-2">In use today</span> : null}
        </div>
        {editable ? (
          <Button size="sm" onClick={onEdit}>
            <Pencil aria-hidden="true" className="size-4" />
            Edit
          </Button>
        ) : null}
      </CardHeader>
      <CardContent>
        {row.status === 'REJECTED' && row.rejectReason ? (
          <InlineAlert tone="warning" title="Rejected" className="mb-4">
            {row.rejectReason}
          </InlineAlert>
        ) : null}
        <dl className="m-0 grid grid-cols-[max-content_1fr] gap-x-6 gap-y-2 text-[13.5px] max-sm:grid-cols-1 max-sm:gap-y-0.5">
          {children}
          <dt className="text-ink-2">Effective</dt>
          <dd className="m-0 text-ink max-sm:mb-2">
            {row.status === 'RETIRED' && row.effectiveTo && row.effectiveTo < row.effectiveFrom
              ? 'Retired before it took effect'
              : dayRange(row.effectiveFrom, row.effectiveTo)}
          </dd>
          <dt className="text-ink-2">What changes</dt>
          <dd className="m-0 text-ink max-sm:mb-2">{row.changeReason ?? '—'}</dd>
          <dt className="text-ink-2">Prepared by</dt>
          <dd className="m-0 text-ink max-sm:mb-2">{row.preparedByName ?? '—'}</dd>
          {row.validatedByName ? (
            <>
              <dt className="text-ink-2">Validated by</dt>
              <dd className="m-0 text-ink max-sm:mb-2">
                {row.validatedByName}, {when(row.validatedAt)}
              </dd>
            </>
          ) : null}
          {row.retiredAt ? (
            <>
              <dt className="text-ink-2">Retired</dt>
              <dd className="m-0 text-ink max-sm:mb-2">{when(row.retiredAt)}</dd>
            </>
          ) : null}
        </dl>
      </CardContent>
    </Card>
  );
}

/** What retiring does to this version (D-080): not used after today, or never if it has not started. */
function retireEffect(row: { effectiveFrom: string }, today: string): string {
  return row.effectiveFrom > today
    ? `It has not started yet, so it never takes effect. Days from ${row.effectiveFrom} have no validated version until another one is validated.`
    : 'It stays in use until the end of today. From tomorrow there is no validated version until another one is validated.';
}

function NewVersionButton({
  title,
  reason,
  note,
  withWeight,
  create,
}: {
  title: string;
  reason: string | undefined;
  note: string;
  withWeight?: boolean;
  create: (v: { effectiveFrom: string; changeReason: string; netWeightKg?: string }) => Promise<void>;
}) {
  const [open, setOpen] = React.useState(false);
  const today = useToday();
  const schema = z.object({
    effectiveFrom: z.string().min(1, 'Choose the day the new version takes effect.'),
    changeReason: z.string().trim().min(1, 'Say what changes, e.g. "Drawing rev C".').max(500, 'Use at most 500 characters.'),
    netWeightKg: z
      .string()
      .trim()
      .regex(/^$|^\d{1,12}(\.\d{1,6})?$/, 'Enter a weight with at most 6 decimals.'),
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
        <DrawerContent title={title} closeLabel="Close">
          <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
            <p className="m-0 text-[13.5px] text-ink-2">{note}</p>
            <EoraForm
              label={title}
              schema={schema}
              defaultValues={{ effectiveFrom: '', changeReason: '', netWeightKg: '' }}
              submitLabel="Create version"
              secondary={
                <Button type="button" onClick={() => setOpen(false)}>
                  Cancel
                </Button>
              }
              onSubmit={async (v) => {
                try {
                  await create(v);
                  setOpen(false);
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
              {withWeight ? (
                <FormTextField
                  name="netWeightKg"
                  label="Net weight (kg per piece)"
                  inputMode="decimal"
                  autoComplete="off"
                  hint="Leave empty to start from the current weight."
                />
              ) : null}
              <FormTextareaField name="changeReason" label="What changes" required maxLength={500} rows={3} />
            </EoraForm>
          </div>
        </DrawerContent>
      </Drawer>
    </>
  );
}

// ——— Net weights ———

function WeightPage({ itemId }: { itemId: string }) {
  const can = useCan();
  const router = useRouter();
  const client = useQueryClient();
  const today = useToday();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [editing, setEditing] = React.useState(false);
  const { versions, list, open, selectedId } = useVersions<ItemWeightRow>('planning.item_weights', { itemId }, can.view);
  const row = list?.find((v) => v.id === selectedId) ?? null;
  const refresh = () => client.invalidateQueries({ queryKey: ['master'] });
  const hrefFor = (id: string) => `/master/conversion-data?weight=${itemId}&version=${id}`;

  if (can.pending || !can.view || versions.isPending || versions.isError || !list?.length || !row) {
    return <PageGate can={can} loading={versions.isPending} failed={versions.isError} empty={!list?.length || !row} retry={() => void versions.refetch()} />;
  }
  const head = list[0]!;
  const done = (m: string) => {
    setNotice(m);
    void refresh();
  };
  const act = (action: LifecycleAction) => {
    const params = { path: { weight: row.id } };
    return call(() =>
      action === 'submit'
        ? api.POST('/planning/item-weights/{weight}/actions/submit', { params })
        : action === 'withdraw'
          ? api.POST('/planning/item-weights/{weight}/actions/withdraw', { params })
          : action === 'reopen'
            ? api.POST('/planning/item-weights/{weight}/actions/reopen', { params })
            : api.POST('/planning/item-weights/{weight}/actions/validate', { params }),
    );
  };

  return (
    <EnterprisePage
      title={`${head.itemCode} net weight`}
      description={`${head.itemName}. The weight of one finished piece; it applies to every plant.`}
      actions={
        <>
          <Button onClick={() => router.push('/master/conversion-data?show=net-weights')}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All net weights
          </Button>
          <NewVersionButton
            title={`New net weight version of ${head.itemCode}`}
            reason={
              !can.create
                ? 'Your roles do not allow changes to conversion data.'
                : open
                  ? `Version ${open.versionNo} is still open. Finish or withdraw it first.`
                  : undefined
            }
            note="Starts as a Draft. The current version stays in use until the new one is validated and takes effect."
            withWeight
            create={async (v) => {
              const res = (await call(() =>
                api.POST('/planning/item-weights', {
                  body: { itemId, effectiveFrom: v.effectiveFrom, changeReason: v.changeReason, netWeightKg: v.netWeightKg ? Number(v.netWeightKg) : null },
                }),
              )) as { data: { id: string } };
              void refresh();
              router.replace(hrefFor(res.data.id));
            }}
          />
        </>
      }
    >
      <Notice notice={notice} onDismiss={() => setNotice(null)} />
      <VersionStrip
        items={list.map((v) => ({
          id: v.id,
          label: `Version ${v.versionNo}`,
          status: v.status,
          detail: `${kgPerPiece(v.netWeightKg)}, ${dayRange(v.effectiveFrom, v.effectiveTo)}`,
          inUse: v.inUse,
        }))}
        selectedId={selectedId}
        hrefFor={hrefFor}
      />
      <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
        <FactsCard title={`Version ${row.versionNo}`} row={row} editable={row.status === 'DRAFT' && can.create} onEdit={() => setEditing(true)}>
          <dt className="text-ink-2">Net weight</dt>
          <dd className="m-0 text-[15px] font-semibold text-ink max-sm:mb-2">{kgPerPiece(row.netWeightKg)}</dd>
        </FactsCard>
        <ApprovalPanel
          label={`Version ${row.versionNo}`}
          record={{ status: row.status, preparedByName: row.preparedByName, approvedByName: row.validatedByName, approvedAt: row.validatedAt }}
          can={can}
          verb="validate"
          missing={row.netWeightKg ? [] : ['the net weight']}
          approvedNote="Validated versions never change. Use New version to change the weight."
          act={act}
          reject={(reason) =>
            call(() => api.POST('/planning/item-weights/{weight}/actions/reject', { params: { path: { weight: row.id } }, body: { reason } }))
          }
          retire={{
            effect: retireEffect(row, today),
            run: () => call(() => api.POST('/planning/item-weights/{weight}/actions/retire', { params: { path: { weight: row.id } } })),
          }}
          onDone={done}
        />
      </div>
      <DetailsDrawer
        key={`${row.id}:${row.version}`}
        row={row}
        weight={row.netWeightKg ? plain(row.netWeightKg) : ''}
        open={editing}
        onOpenChange={setEditing}
        onSaved={() => void refresh()}
        save={(body) =>
          call(() =>
            api.PATCH('/planning/item-weights/{weight}', {
              params: { path: { weight: row.id } },
              headers: ifMatch(row.version),
              body: { ...body, netWeightKg: body.netWeightKg ? Number(body.netWeightKg) : null },
            }),
          )
        }
      />
    </EnterprisePage>
  );
}

/** Start day and reason of a Draft version (If-Match); a net weight version also edits its weight. */
function DetailsDrawer({
  row,
  weight,
  open,
  onOpenChange,
  onSaved,
  save,
}: {
  row: { versionNo: number; effectiveFrom: string; changeReason: string | null };
  /** Current weight text, or undefined for a version without one (a conversion profile). */
  weight?: string;
  open: boolean;
  onOpenChange: (o: boolean) => void;
  onSaved: () => void;
  save: (body: { effectiveFrom: string; changeReason: string | null; netWeightKg?: string }) => Promise<unknown>;
}) {
  const today = useToday();
  const schema = z.object({
    netWeightKg: z
      .string()
      .trim()
      .regex(/^$|^\d{1,12}(\.\d{1,6})?$/, 'Enter a weight with at most 6 decimals.')
      .refine((v) => v === '' || Number(v) > 0, 'Enter more than 0.'),
    effectiveFrom: z.string().min(1, 'Choose the day this version takes effect.'),
    changeReason: z.string().trim().max(500, 'Use at most 500 characters.'),
  });
  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent title={`Version ${row.versionNo}`} closeLabel="Close">
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
          <EoraForm
            label={`Version ${row.versionNo}`}
            schema={schema}
            defaultValues={{ netWeightKg: weight ?? '', effectiveFrom: row.effectiveFrom, changeReason: row.changeReason ?? '' }}
            submitLabel="Save changes"
            secondary={
              <Button type="button" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
            }
            onSubmit={async (v) => {
              try {
                await save({
                  effectiveFrom: v.effectiveFrom,
                  changeReason: v.changeReason || null,
                  ...(weight === undefined ? {} : { netWeightKg: v.netWeightKg }),
                });
                onOpenChange(false);
                onSaved();
                return { ok: true };
              } catch (e) {
                if ((e as { status?: number } | null)?.status === 412) onSaved();
                return conflictFailure(e, 'Nothing was saved.');
              }
            }}
          >
            {weight === undefined ? null : (
              <FormTextField
                name="netWeightKg"
                label="Net weight (kg per piece)"
                inputMode="decimal"
                autoComplete="off"
                hint="Up to 6 decimals, e.g. 0.2905."
              />
            )}
            <FormDateField name="effectiveFrom" label="Takes effect" required min={today as IsoDate} today={today as IsoDate} hint="Today or later." />
            <FormTextareaField name="changeReason" label="What changes" maxLength={500} rows={3} />
          </EoraForm>
        </div>
      </DrawerContent>
    </Drawer>
  );
}

// ——— Conversion profiles ———

function ProfilePage({ profileId }: { profileId: string }) {
  const can = useCan();
  const router = useRouter();
  const client = useQueryClient();
  const today = useToday();
  const [notice, setNotice] = React.useState<string | null>(null);
  const [editing, setEditing] = React.useState(false);
  const [detailsOpen, setDetailsOpen] = React.useState(false);
  const [statusError, setStatusError] = React.useState<string | null>(null);
  const profile = useQuery({
    queryKey: ['master', 'planning.conversion_profiles', 'one', profileId],
    queryFn: async ({ signal }) =>
      (
        await querySource<ConversionProfileRow>('planning.conversion_profiles').list(
          { filters: { id: profileId }, search: '', sort: [], page: 1, pageSize: 1, view: 'all' },
          signal,
        )
      ).rows[0] ?? null,
    enabled: can.view,
  });
  const { versions, list, open, selectedId } = useVersions<ProfileVersionRow>('planning.conversion_profile_versions', { profileId }, can.view);
  const detail = useQuery({
    queryKey: ['master', 'conversion-profile-version', selectedId],
    queryFn: async () =>
      (
        (await call(() => api.GET('/planning/conversion-profile-versions/{version}', { params: { path: { version: selectedId! } } }))) as {
          data: ProfileVersion;
        }
      ).data,
    select: (v: ProfileVersion) => (v.profileId === profileId ? v : null),
    enabled: can.view && !!selectedId,
  });
  const refresh = () => client.invalidateQueries({ queryKey: ['master'] });
  const hrefFor = (id: string) => `/master/conversion-data?profile=${profileId}&version=${id}`;
  const p = profile.data;

  if (can.pending || !can.view || versions.isPending || profile.isPending || versions.isError || profile.isError || !list?.length || !p) {
    return (
      <PageGate
        can={can}
        loading={versions.isPending || profile.isPending}
        failed={versions.isError || profile.isError}
        empty={!list?.length || !p}
        retry={() => void (versions.refetch(), profile.refetch())}
      />
    );
  }
  const form = INPUT_FORMS.find((f) => f.value === p.inputForm)?.label ?? p.inputForm;
  const done = (m: string) => {
    setNotice(m);
    void refresh();
  };
  const setStatus = async (status: 'ACTIVE' | 'INACTIVE') => {
    setStatusError(null);
    try {
      await call(() =>
        api.PATCH('/planning/conversion-profiles/{profile}', { params: { path: { profile: p.id } }, headers: ifMatch(p.version), body: { status } }),
      );
      done(status === 'ACTIVE' ? 'Profile activated' : 'Profile deactivated');
    } catch (e) {
      setStatusError(conflictFailure(e, 'The status did not change.').message ?? 'The status did not change.');
      void refresh();
    }
  };

  return (
    <EnterprisePage
      title={`${p.itemCode} from ${p.inputItemCode}`}
      description={`${p.itemName}, made from ${p.inputItemName} (${form.toLowerCase()}). Applies to every plant.`}
      actions={
        <>
          <Button onClick={() => router.push('/master/conversion-data?show=profiles')}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All profiles
          </Button>
          {can.create ? (
            <Button onClick={() => void setStatus(p.status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE')}>{p.status === 'ACTIVE' ? 'Deactivate' : 'Activate'}</Button>
          ) : null}
          <NewVersionButton
            title={`New version of ${p.itemCode} from ${p.inputItemCode}`}
            reason={
              !can.create
                ? 'Your roles do not allow changes to conversion data.'
                : p.status !== 'ACTIVE'
                  ? 'The profile is inactive. Activate it first.'
                  : open
                    ? `Version ${open.versionNo} is still open. Finish or withdraw it first.`
                    : undefined
            }
            note="Starts as a Draft with the inputs of the latest validated version. That version stays in use until the new one is validated and takes effect."
            create={async (v) => {
              const res = (await call(() =>
                api.POST('/planning/conversion-profile-versions', {
                  body: { itemId: p.itemId, inputItemId: p.inputItemId, effectiveFrom: v.effectiveFrom, changeReason: v.changeReason },
                }),
              )) as { data: { id: string } };
              void refresh();
              router.replace(hrefFor(res.data.id));
            }}
          />
        </>
      }
    >
      <Notice notice={notice} onDismiss={() => setNotice(null)} />
      {statusError ? (
        <InlineAlert tone="danger" title="Not done" className="mb-4">
          {statusError}
        </InlineAlert>
      ) : null}
      {p.status !== 'ACTIVE' ? (
        <InlineAlert tone="warning" title="Inactive profile" className="mb-4">
          BOM lines cannot use it and it takes no new version. Versions already validated stay as history.
        </InlineAlert>
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
        <div className="flex min-w-0 flex-col gap-5">
          <div className="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
            <FactsCard
              title={`Version ${detail.data.versionNo}`}
              row={detail.data}
              editable={detail.data.status === 'DRAFT' && can.create}
              onEdit={() => setDetailsOpen(true)}
            >
              <dt className="text-ink-2">Formula</dt>
              <dd className="m-0 text-ink max-sm:mb-2">
                {form} ({detail.data.formulaCode})
              </dd>
            </FactsCard>
            <ApprovalPanel
              label={`Version ${detail.data.versionNo}`}
              record={{
                status: detail.data.status,
                preparedByName: detail.data.preparedByName,
                approvedByName: detail.data.validatedByName,
                approvedAt: detail.data.validatedAt,
              }}
              can={can}
              verb="validate"
              missing={detail.data.inputs.some((i) => i.value === null) ? ['every input'] : []}
              approvedNote="Validated versions never change. Use New version to change the inputs."
              act={(action) => profileAction(detail.data!.id, action)}
              reject={(reason) =>
                call(() =>
                  api.POST('/planning/conversion-profile-versions/{version}/actions/reject', {
                    params: { path: { version: detail.data!.id } },
                    body: { reason },
                  }),
                )
              }
              retire={{
                effect: retireEffect(detail.data, today),
                run: () =>
                  call(() => api.POST('/planning/conversion-profile-versions/{version}/actions/retire', { params: { path: { version: detail.data!.id } } })),
              }}
              onDone={done}
            />
          </div>
          <InputsSection version={detail.data} editable={detail.data.status === 'DRAFT' && can.create} onEdit={() => setEditing(true)} />
          <InputsDrawer
            key={`${detail.data.id}:${detail.data.version}`}
            version={detail.data}
            open={editing}
            onOpenChange={setEditing}
            onSaved={() => void refresh()}
          />
          <DetailsDrawer
            key={`details:${detail.data.id}:${detail.data.version}`}
            row={detail.data}
            open={detailsOpen}
            onOpenChange={setDetailsOpen}
            onSaved={() => void refresh()}
            save={(body) =>
              call(() =>
                api.PATCH('/planning/conversion-profile-versions/{version}', {
                  params: { path: { version: detail.data!.id } },
                  headers: ifMatch(detail.data!.version),
                  body,
                }),
              )
            }
          />
        </div>
      ) : null}
    </EnterprisePage>
  );
}

function profileAction(version: string, action: LifecycleAction) {
  const params = { path: { version } };
  return call(() =>
    action === 'submit'
      ? api.POST('/planning/conversion-profile-versions/{version}/actions/submit', { params })
      : action === 'withdraw'
        ? api.POST('/planning/conversion-profile-versions/{version}/actions/withdraw', { params })
        : action === 'reopen'
          ? api.POST('/planning/conversion-profile-versions/{version}/actions/reopen', { params })
          : api.POST('/planning/conversion-profile-versions/{version}/actions/validate', { params }),
  );
}

/** The formula inputs of the version, in the formula's order, each with its unit. */
function InputsSection({ version, editable, onEdit }: { version: ProfileVersion; editable: boolean; onEdit: () => void }) {
  return (
    <section aria-labelledby="inputs-heading" className="flex min-w-0 flex-col gap-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <h2 id="inputs-heading" className="m-0 text-[15px] font-semibold text-ink">
          Formula inputs
        </h2>
        {editable ? (
          <Button size="sm" onClick={onEdit}>
            <Pencil aria-hidden="true" className="size-4" />
            Edit inputs
          </Button>
        ) : null}
      </div>
      <p className="m-0 text-[13px] text-ink-2">
        {version.status === 'DRAFT'
          ? 'Stored as entered, in the unit shown. Every input is needed before submitting. Actual lot measurements take precedence when production is reconciled.'
          : 'Inputs change only in a Draft version. Use New version to change them.'}
      </p>
      <Card>
        <CardContent className="p-0">
          <dl className="m-0 grid grid-cols-[minmax(0,1fr)_max-content] text-[13.5px]">
            {version.inputs.map((i) => (
              <React.Fragment key={i.key}>
                <dt className="border-b border-line px-4 py-2.5 text-ink-2 last-of-type:border-b-0">{i.label}</dt>
                <dd className="m-0 border-b border-line px-4 py-2.5 text-right text-ink tabular-nums last-of-type:border-b-0">
                  {i.value === null ? <span className="text-ink-2">Missing</span> : `${plain(i.value)} ${i.unit}`}
                </dd>
              </React.Fragment>
            ))}
          </dl>
        </CardContent>
      </Card>
    </section>
  );
}

function InputsDrawer({
  version,
  open,
  onOpenChange,
  onSaved,
}: {
  version: ProfileVersion;
  open: boolean;
  onOpenChange: (o: boolean) => void;
  onSaved: () => void;
}) {
  const number = z
    .string()
    .trim()
    .regex(/^$|^(0|[1-9]\d{0,11})(\.\d{1,6})?$/, 'Enter a number with at most 6 decimals.');
  const schema = z.object(Object.fromEntries(version.inputs.map((i) => [i.key, number])));
  const title = `Inputs of version ${version.versionNo}`;
  return (
    <Drawer open={open} onOpenChange={onOpenChange}>
      <DrawerContent title={title} closeLabel="Close">
        <div className="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto p-5">
          <p className="m-0 text-[13.5px] text-ink-2">Enter each value in the unit shown. You can save an incomplete set and finish it later.</p>
          <EoraForm
            label={title}
            schema={schema}
            defaultValues={Object.fromEntries(version.inputs.map((i) => [i.key, i.value === null ? '' : plain(i.value)]))}
            submitLabel="Save inputs"
            secondary={
              <Button type="button" onClick={() => onOpenChange(false)}>
                Cancel
              </Button>
            }
            onSubmit={async (v: Record<string, string>) => {
              const inputs = Object.fromEntries(version.inputs.filter((i) => (v[i.key] ?? '') !== '').map((i) => [i.key, { value: v[i.key]!, unit: i.unit }]));
              try {
                await call(() =>
                  api.PATCH('/planning/conversion-profile-versions/{version}', {
                    params: { path: { version: version.id } },
                    headers: ifMatch(version.version),
                    body: { inputs } as never,
                  }),
                );
                onOpenChange(false);
                onSaved();
                return { ok: true };
              } catch (e) {
                if ((e as { status?: number } | null)?.status === 412) onSaved();
                // the server names fields `inputs.<key>`; the form names them `<key>`
                const failure = conflictFailure(e, 'The inputs were not saved.');
                if (failure.ok || !('fieldErrors' in failure) || !failure.fieldErrors) return failure;
                return { ...failure, fieldErrors: Object.fromEntries(Object.entries(failure.fieldErrors).map(([k, m]) => [k.replace(/^inputs\./, ''), m])) };
              }
            }}
          >
            {version.inputs.map((i) => (
              <FormTextField key={i.key} name={i.key} label={`${i.label} (${i.unit})`} inputMode="decimal" autoComplete="off" />
            ))}
          </EoraForm>
        </div>
      </DrawerContent>
    </Drawer>
  );
}
