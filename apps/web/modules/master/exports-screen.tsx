'use client';

import * as React from 'react';
import { keepPreviousData, useQuery } from '@tanstack/react-query';
import { Download } from 'lucide-react';
import { useSearchParams } from 'next/navigation';
import { api, call, failureMessage, useMe } from '@eora/features/auth';
import { useDensityPreference, usePageSizePreference } from '@eora/features/data';
import {
  DataTable,
  DomainStatusBadge,
  EnterprisePage,
  ListWorkspace,
  Pagination,
  useListUrlState,
  type EoraColumn,
  type ListUrlConfig,
} from '@eora/features/patterns';
import { Button, EmptyState, InlineAlert, LoadingState, PermissionDeniedState } from '@eora/ui';
import { querySource } from './source';
import { when } from './version-ui';

/*
 * Master Data → Data Exchange → Exports (D-092, ADR-0018): the caller's own list exports. A ready file is fetched
 * through a signed link that lasts 15 minutes and works only in this session; files are deleted 24 hours after they
 * were written. Notifications link here with `?job=<id>` (via /system/exports).
 */

type ExportStatus = 'QUEUED' | 'RUNNING' | 'DONE' | 'FAILED' | 'EXPIRED';

/** `exchange.export_jobs` row (ExportJobsQuery). */
export type ExportJob = {
  id: string;
  resource: string;
  title: string | null;
  status: ExportStatus;
  failureCode: string | null;
  failureDetail: string | null;
  rowCount: number | null;
  fileName: string | null;
  fileSize: number | null;
  finishedAt: string | null;
  expiresAt: string | null;
  createdAt: string;
};

const RESOURCE = 'exchange.export_jobs';
const EMPTY: never[] = [];
const WORKING: readonly ExportStatus[] = ['QUEUED', 'RUNNING'];

/** A file can be fetched while the job is DONE and its 24 hours have not run out. */
export function downloadable(job: Pick<ExportJob, 'status' | 'expiresAt'>, now = Date.now()): boolean {
  return job.status === 'DONE' && !!job.expiresAt && Date.parse(job.expiresAt) > now;
}

/** The status to show: a file past its 24 hours reads as deleted even before the hourly clean-up marked it. */
export function shownStatus(job: Pick<ExportJob, 'status' | 'expiresAt'>, now = Date.now()): ExportStatus {
  return job.status === 'DONE' && !downloadable(job, now) ? 'EXPIRED' : job.status;
}

/** What the row says next to its status. */
export function exportNote(job: ExportJob, now = Date.now()): string {
  switch (job.status) {
    case 'QUEUED':
    case 'RUNNING':
      return 'Being written. This page updates by itself, and you get a notification.';
    case 'DONE':
      return downloadable(job, now) ? `Ready until ${when(job.expiresAt)}.` : 'Deleted after 24 hours. Export the list again.';
    case 'EXPIRED':
      return 'Deleted after 24 hours. Export the list again.';
    case 'FAILED':
      return job.failureDetail ?? 'The export could not be written.';
  }
}

export function ExportsScreen() {
  const me = useMe();
  const canExport = (me.data?.permissions ?? []).includes('exchange.export.export');
  const focus = useSearchParams()?.get('job') ?? null;
  const [prefSize, setPrefSize] = usePageSizePreference();
  const [density] = useDensityPreference();
  const source = React.useMemo(() => querySource<ExportJob>(RESOURCE), []);
  const urlConfig = React.useMemo<ListUrlConfig>(
    () => ({ filterKeys: [], defaultSort: [{ id: 'createdAt', desc: true }], defaultView: 'all', defaultPageSize: Number(prefSize), keepParams: ['job'] }),
    [prefSize],
  );
  const list = useListUrlState(urlConfig);
  const { query } = list;
  const [failure, setFailure] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState<string | null>(null);

  const rows = useQuery({
    queryKey: ['exports', 'jobs', query],
    queryFn: ({ signal }) => source.list(query, signal),
    placeholderData: keepPreviousData,
    enabled: canExport,
    // a job still being written refreshes the list until it settles
    refetchInterval: (q) => (q.state.data?.rows.some((r) => WORKING.includes(r.status)) ? 2000 : false),
  });

  // the export a notification links to, read on its own: it may sit on another page of the list or another sort
  const target = useQuery({
    queryKey: ['exports', 'job', focus],
    queryFn: async ({ signal }) =>
      ((await call(() => api.GET('/exchange/exports/{job}', { params: { path: { job: focus! } }, signal }))) as { data: ExportJob }).data,
    enabled: canExport && !!focus,
    retry: false,
    refetchInterval: (q) => (q.state.data && WORKING.includes(q.state.data.status) ? 2000 : false),
  });

  const download = React.useCallback(async (job: ExportJob) => {
    setBusy(job.id);
    setFailure(null);
    try {
      const link = (await call(() => api.POST('/exchange/exports/{job}/link', { params: { path: { job: job.id } } }))) as { data: { url: string } };
      window.location.assign(link.data.url);
    } catch (e) {
      setFailure(failureMessage(e, 'The file could not be fetched.'));
    } finally {
      setBusy(null);
    }
  }, []);

  const columns = React.useMemo<EoraColumn<ExportJob>[]>(
    () => [
      { id: 'title', header: 'List', lead: true, cell: (r) => r.title ?? r.resource, sub: (r) => r.fileName ?? '' },
      { id: 'status', header: 'Status', critical: true, cell: (r) => <DomainStatusBadge domain="exportJob" status={shownStatus(r)} /> },
      { id: 'rowCount', header: 'Rows', numeric: true, cell: (r) => (r.rowCount === null ? '—' : r.rowCount.toLocaleString('en-US')) },
      { id: 'createdAt', header: 'Requested', sortable: true, cell: (r) => when(r.createdAt) },
      { id: 'note', header: 'Note', textCol: true, cell: (r) => exportNote(r), title: (r) => exportNote(r) },
    ],
    [],
  );

  if (me.isPending) return <LoadingState title="Loading exports" />;
  if (!canExport) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState title="Exports are not available for your roles" description="Ask a tenant administrator for a role that may export lists." />
      </div>
    );
  }

  const meta = rows.data?.meta;
  const status = rows.isError ? 'error' : rows.isPending ? 'loading' : 'ready';
  const focused = focus ? target.data : undefined;

  return (
    <EnterprisePage
      title="Exports"
      description="Lists you exported to Excel. A file can be downloaded for 24 hours after it was written, by you only, while your roles still cover its data."
    >
      {failure ? <InlineAlert tone="danger" title={failure} className="mb-4" /> : null}
      {focused ? (
        <InlineAlert
          tone={focused.status === 'FAILED' ? 'danger' : downloadable(focused) ? 'success' : 'info'}
          title={`${focused.title ?? focused.resource}: ${exportNote(focused)}`}
          className="mb-4"
          action={
            downloadable(focused) ? (
              <Button size="sm" variant="primary" onClick={() => void download(focused)} disabled={busy === focused.id}>
                <Download aria-hidden="true" className="size-4" />
                Download
              </Button>
            ) : undefined
          }
        />
      ) : null}
      {focus && target.isError ? (
        <InlineAlert tone="info" title="This export is not available to you. Exports are listed for the person who made them." className="mb-4" />
      ) : null}
      <ListWorkspace
        label="Exports"
        toolbar={
          <span className="text-[13px] whitespace-nowrap text-ink-2" aria-live="polite">
            <b className="font-semibold text-ink tabular-nums">{meta ? meta.total.toLocaleString('en-US') : '…'}</b> {meta?.total === 1 ? 'export' : 'exports'}
          </span>
        }
        pagination={
          status === 'ready' && meta && meta.total > 0 ? (
            <Pagination
              page={query.page}
              pageSize={query.pageSize}
              total={meta.total}
              itemLabel="exports"
              onPageChange={list.setPage}
              onPageSizeChange={(s) => {
                setPrefSize(String(s));
                list.setPageSize(s);
              }}
            />
          ) : null
        }
      >
        <DataTable
          label="Exports"
          columns={columns}
          rows={rows.data?.rows ?? (EMPTY as ExportJob[])}
          getRowId={(r) => r.id}
          rowLabel={(r) => r.title ?? r.resource}
          status={status}
          error={rows.error}
          onRetry={() => rows.refetch()}
          isFetching={rows.isFetching && !rows.isPending}
          sort={query.sort}
          onSortChange={list.setSort}
          density={density}
          skeletonRows={6}
          empty={<EmptyState title="No exports yet" description="Use Export on any Master Data list; the file appears here when it is written." />}
          rowActions={{
            primary: (r) => ({
              id: 'download',
              label: 'Download',
              icon: Download,
              onSelect: () => void download(r),
              disabledReason: downloadable(r) ? undefined : exportNote(r),
            }),
          }}
        />
      </ListWorkspace>
    </EnterprisePage>
  );
}
