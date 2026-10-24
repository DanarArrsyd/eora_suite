'use client';

import * as React from 'react';
import { keepPreviousData, useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, Download, FileSpreadsheet, FolderOpen, Plus, Upload, X } from 'lucide-react';
import { useRouter, useSearchParams } from 'next/navigation';
import { api, ApiError, call, failureMessage, useMe } from '@eora/features/auth';
import { activeFilterCount, useDensityPreference, usePageSizePreference, type FilterValues } from '@eora/features/data';
import {
  ActiveFilters,
  ConfirmDialog,
  DataTable,
  DensityToggle,
  DomainStatusBadge,
  EnterprisePage,
  FilterBar,
  FilterToggle,
  ListSearch,
  ListWorkspace,
  Pagination,
  StatusTabs,
  useFilterPanel,
  useListUrlState,
  type EoraColumn,
  type FilterField,
  type ListUrlConfig,
} from '@eora/features/patterns';
import {
  Button,
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
  EmptyState,
  ErrorState,
  Field,
  InlineAlert,
  LoadingState,
  PermissionDeniedState,
  Select,
  Tooltip,
  cn,
} from '@eora/ui';
import {
  NEEDS,
  STEPS,
  errorFileUrl,
  fileSize,
  isRunning,
  jobStep,
  jobSummary,
  precheckFile,
  templateFileUrl,
  type ImportJob,
  type ImportJobStatus,
  type ImportRow,
  type ImportRowStatus,
  type ImportTemplateInfo,
} from './import-model';
import { querySource } from './source';
import { when } from './version-ui';

/*
 * Master Data → Data Exchange → Import (doc 29 §1.6, doc 26 §5, ADR-0017, D-081): the job list, a new upload, and one
 * job's page (`?new` / `?job=<id>`). The worker reads and checks the file; the page shows each row's result and the
 * error file, then the job is submitted for commit and committed by someone allowed to (exchange.import.post plus the
 * template's master permission). Routings, BOMs and maps land as Draft and keep their second-person approval.
 */

const RESOURCE = 'exchange.import_jobs';
const EMPTY: never[] = [];
const ROWS_PER_PAGE = 100;
/** Rows stay in file order (the server pages them by row number). */
const NO_SORT: never[] = [];

/** Who may change which master data, in words (the server decides). */
const TARGET: Record<string, string> = {
  'master.part.create': 'parts and partners',
  'master.org.create': 'organisation master data',
  'master.routing.create': 'routings and BOMs',
};

const JOB_STATUSES: { value: ImportJobStatus; label: string }[] = [
  { value: 'RESERVED', label: 'Queued' },
  { value: 'VALIDATING', label: 'Checking' },
  { value: 'VALIDATED', label: 'Checked' },
  { value: 'SUBMITTED', label: 'Submitted' },
  { value: 'COMMITTING', label: 'Committing' },
  { value: 'COMMITTED', label: 'Committed' },
  { value: 'REJECTED', label: 'Rejected' },
  { value: 'FAILED', label: 'Failed' },
];

function useCan() {
  const me = useMe();
  const p = me.data?.permissions ?? [];
  return {
    pending: me.isPending,
    create: p.includes('exchange.import.create'),
    submit: p.includes('exchange.import.submit'),
    post: p.includes('exchange.import.post'),
    has: (permission: string) => p.includes(permission),
  };
}

function useTemplates(enabled: boolean) {
  return useQuery({
    queryKey: ['import', 'templates'],
    queryFn: async () => ((await call(() => api.GET('/exchange/import-templates'))) as { data: ImportTemplateInfo[] }).data,
    staleTime: 5 * 60_000,
    enabled,
  });
}

/** Master Data → Data Exchange → Import: the jobs, a new upload (`?new`), or one job (`?job=<id>`). */
export function ImportScreen() {
  const params = useSearchParams();
  const job = params?.get('job');
  const can = useCan();
  if (can.pending) return <LoadingState title="Loading imports" />;
  if (!can.create) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Import is not available for your roles"
          description="Ask a tenant administrator for a role that may upload import files."
        />
      </div>
    );
  }
  if (job) return <JobPage key={job} jobId={job} />;
  if (params?.has('new')) return <NewImport preset={params.get('template') ?? ''} />;
  return <JobList />;
}

/* ---------------------------------------------------------------- job list */

function JobList() {
  const router = useRouter();
  const templates = useTemplates(true);
  const source = React.useMemo(() => querySource<ImportJob>(RESOURCE), []);
  const [density, setDensity] = useDensityPreference();
  const [prefSize, setPrefSize] = usePageSizePreference();
  const titles = React.useMemo(() => new Map((templates.data ?? []).map((t) => [t.code, t.title])), [templates.data]);
  const filters = React.useMemo<FilterField[]>(
    () => [
      { key: 'status', type: 'select', source: 'static', label: 'Status', options: JOB_STATUSES },
      { key: 'template', type: 'select', source: 'static', label: 'Template', options: (templates.data ?? []).map((t) => ({ value: t.code, label: t.title })) },
    ],
    [templates.data],
  );
  const urlConfig = React.useMemo<ListUrlConfig>(
    () => ({ filterKeys: ['status', 'template'], defaultSort: [{ id: 'createdAt', desc: true }], defaultView: 'all', defaultPageSize: Number(prefSize) }),
    [prefSize],
  );
  const list = useListUrlState(urlConfig);
  const { query } = list;
  const panel = useFilterPanel();
  const rows = useQuery({
    queryKey: ['import', 'jobs', query],
    queryFn: ({ signal }) => source.list(query, signal),
    placeholderData: keepPreviousData,
    // a job still being read or saved refreshes the list until it settles
    refetchInterval: (q) => (q.state.data?.rows.some((r) => isRunning(r.status)) ? 3000 : false),
  });

  const columns = React.useMemo<EoraColumn<ImportJob>[]>(
    () => [
      {
        id: 'fileName',
        header: 'File',
        lead: true,
        textCol: true,
        cell: (r) => r.fileName,
        title: (r) => r.fileName,
        sub: (r) => titles.get(r.template) ?? r.template,
      },
      { id: 'status', header: 'Status', critical: true, sortable: true, cell: (r) => <DomainStatusBadge domain="importJob" status={r.status} /> },
      { id: 'totalRows', header: 'Rows', numeric: true, cell: (r) => r.totalRows.toLocaleString('en-US') },
      { id: 'errorRows', header: 'With errors', numeric: true, cell: (r) => r.errorRows.toLocaleString('en-US') },
      { id: 'committedRows', header: 'Committed', numeric: true, cell: (r) => r.committedRows.toLocaleString('en-US') },
      { id: 'createdAt', header: 'Uploaded', sortable: true, cell: (r) => when(r.createdAt), sub: (r) => r.uploadedByName ?? '' },
    ],
    [titles],
  );

  const meta = rows.data?.meta;
  const status = rows.isError ? 'error' : rows.isPending ? 'loading' : 'ready';
  const hasFilters = activeFilterCount(query.filters) > 0 || !!query.search;

  return (
    <EnterprisePage
      title="Import"
      description="Load master data from the Excel templates. Every job keeps its file, the result of each row and who submitted and committed it."
      actions={
        <Button variant="primary" onClick={() => router.push('/master/import?new')}>
          <Plus aria-hidden="true" className="size-4" />
          New import
        </Button>
      }
    >
      <ListWorkspace
        label="Import jobs"
        toolbar={
          <>
            <div className="flex min-w-0 flex-wrap items-center gap-2 max-md:w-full">
              <ListSearch label="Search import jobs" placeholder="Search file name…" value={query.search} onChange={list.setSearch} />
              <FilterToggle panel={panel} count={activeFilterCount(query.filters)} controlsId="import-filters" />
              <span className="text-[13px] whitespace-nowrap text-ink-2" aria-live="polite">
                <b className="font-semibold text-ink tabular-nums">{meta ? meta.total.toLocaleString('en-US') : '…'}</b> {meta?.total === 1 ? 'job' : 'jobs'}
              </span>
            </div>
            <DensityToggle value={density} onChange={setDensity} />
          </>
        }
        filterBar={<FilterBar id="import-filters" fields={filters} values={query.filters} onApply={(v: FilterValues) => list.applyFilters(v)} panel={panel} />}
        activeFilters={
          <ActiveFilters
            fields={filters}
            values={query.filters}
            search={query.search}
            onRemove={list.removeFilter}
            onClearSearch={() => list.setSearch('')}
            onClearAll={list.clearFilters}
          />
        }
        pagination={
          status === 'ready' && meta && meta.total > 0 ? (
            <Pagination
              page={query.page}
              pageSize={query.pageSize}
              total={meta.total}
              itemLabel="jobs"
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
          label="Import jobs"
          columns={columns}
          rows={rows.data?.rows ?? (EMPTY as ImportJob[])}
          getRowId={(r) => r.id}
          rowLabel={(r) => r.fileName}
          status={status}
          error={rows.error}
          onRetry={() => rows.refetch()}
          isFetching={rows.isFetching && !rows.isPending}
          sort={query.sort}
          onSortChange={list.setSort}
          density={density}
          skeletonRows={Math.min(query.pageSize, 8)}
          empty={
            hasFilters ? (
              <EmptyState
                title="No import jobs match these filters"
                action={
                  <Button size="sm" onClick={list.clearFilters}>
                    Clear filters
                  </Button>
                }
              />
            ) : (
              <EmptyState title="No imports yet" description="Start with New import: pick a template, fill it in Excel and upload it." />
            )
          }
          rowActions={{ primary: (r) => ({ id: 'open', label: 'Open', icon: FolderOpen, onSelect: () => router.push(`/master/import?job=${r.id}`) }) }}
        />
      </ListWorkspace>
    </EnterprisePage>
  );
}

/* ---------------------------------------------------------------- new upload */

function NewImport({ preset }: { preset: string }) {
  const router = useRouter();
  const client = useQueryClient();
  const can = useCan();
  const templates = useTemplates(true);
  const [code, setCode] = React.useState(preset);
  const [file, setFile] = React.useState<File | null>(null);
  const [fileError, setFileError] = React.useState<string>();
  const [failure, setFailure] = React.useState<string>();
  const [busy, setBusy] = React.useState(false);
  const [dragging, setDragging] = React.useState(false);
  const inputRef = React.useRef<HTMLInputElement>(null);
  const fileId = React.useId();
  const template = templates.data?.find((t) => t.code === code);

  const choose = (f: File | null) => {
    const err = f ? precheckFile(f) : undefined;
    setFile(err ? null : f);
    setFileError(err);
    setFailure(undefined);
  };

  const upload = async () => {
    if (!template) return setFailure('Choose a template first.');
    if (!file) return setFileError('Choose the filled-in template file.');
    setBusy(true);
    setFailure(undefined);
    try {
      const body = new FormData();
      body.set('template', template.code);
      body.set('file', file);
      const result = (await call(() => api.POST('/exchange/imports', { body: body as never, bodySerializer: (b: unknown) => b as FormData }))) as {
        data: ImportJob;
      };
      void client.invalidateQueries({ queryKey: ['import', 'jobs'] });
      router.push(`/master/import?job=${result.data.id}`);
    } catch (e) {
      if (e instanceof ApiError && e.fieldErrors.file) setFileError(e.fieldErrors.file);
      else setFailure(failureMessage(e, 'The file could not be uploaded.'));
      setBusy(false);
    }
  };

  const needs = (NEEDS[code] ?? []).map((c) => templates.data?.find((t) => t.code === c)?.title ?? c);

  return (
    <EnterprisePage
      title="New import"
      description="Pick the template, fill it in Excel without changing the header row, and upload it. Nothing is saved until the job is committed."
      actions={
        <Button onClick={() => router.push('/master/import')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All imports
        </Button>
      }
    >
      <div className="grid max-w-5xl gap-4">
        <Card>
          <CardHeader>
            <div>
              <CardTitle>Template</CardTitle>
              <CardDescription>Columns are matched by their header; their order may change.</CardDescription>
            </div>
            {template ? (
              <Button asChild size="sm">
                <a href={templateFileUrl(template.code)} download>
                  <Download aria-hidden="true" className="size-4" />
                  Download empty template
                </a>
              </Button>
            ) : null}
          </CardHeader>
          <CardContent className="flex flex-col gap-4">
            {templates.isError ? (
              <ErrorState
                title="Templates could not be loaded"
                action={
                  <Button size="sm" onClick={() => void templates.refetch()}>
                    Try again
                  </Button>
                }
              />
            ) : (
              <Field label="Template" required>
                {(control) => (
                  <Select
                    {...control}
                    className="max-w-md"
                    value={code}
                    onValueChange={(v) => setCode(v)}
                    placeholder={templates.isPending ? 'Loading templates…' : 'Choose a template'}
                    disabled={templates.isPending}
                    options={(templates.data ?? []).map((t) => ({ value: t.code, label: t.title }))}
                  />
                )}
              </Field>
            )}
            {template ? <TemplateFacts template={template} needs={needs} canCommit={can.has(template.permission)} /> : null}
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <div>
              <CardTitle>File</CardTitle>
              <CardDescription>Excel workbook (.xlsx) up to 10 MB, without macros or links to other files.</CardDescription>
            </div>
          </CardHeader>
          <CardContent className="flex flex-col gap-3">
            {/* The whole area takes a dropped file (pointer shortcut); keyboard and touch use the Choose file button. */}
            {/* eslint-disable-next-line jsx-a11y/no-static-element-interactions */}
            <div
              onDragOver={(e) => {
                e.preventDefault();
                setDragging(true);
              }}
              onDragLeave={() => setDragging(false)}
              onDrop={(e) => {
                e.preventDefault();
                setDragging(false);
                choose(e.dataTransfer.files[0] ?? null);
              }}
              className={cn(
                'flex flex-wrap items-center gap-4 rounded-lg border border-dashed px-5 py-6 max-md:px-4',
                dragging ? 'border-accent bg-accent-subtle' : fileError ? 'border-stat-err-fg' : 'border-line-control',
              )}
            >
              <FileSpreadsheet aria-hidden="true" className="size-8 shrink-0 text-ink-2" />
              <div className="min-w-0 flex-1">
                {file ? (
                  <p className="m-0 truncate font-medium" title={file.name}>
                    {file.name}
                    <span className="font-normal text-ink-2"> · {fileSize(file.size)}</span>
                  </p>
                ) : (
                  <p className="m-0 text-ink-2">Drop the file here, or choose it.</p>
                )}
              </div>
              <input
                ref={inputRef}
                id={fileId}
                type="file"
                accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                // the Choose file button opens it: one control for keyboard and screen readers
                className="hidden"
                onChange={(e) => {
                  choose(e.target.files?.[0] ?? null);
                  e.target.value = '';
                }}
              />
              <div className="flex gap-2">
                {file ? (
                  <Button size="sm" onClick={() => choose(null)} aria-label={`Remove ${file.name}`}>
                    <X aria-hidden="true" className="size-4" />
                    Remove
                  </Button>
                ) : null}
                <Button size="sm" onClick={() => inputRef.current?.click()} aria-describedby={fileError ? `${fileId}-error` : undefined}>
                  <Upload aria-hidden="true" className="size-4" />
                  {file ? 'Choose another file' : 'Choose file'}
                </Button>
              </div>
            </div>
            {fileError ? (
              <p id={`${fileId}-error`} role="alert" className="m-0 text-xs font-medium text-stat-err-fg">
                {fileError}
              </p>
            ) : null}
            {failure ? <InlineAlert tone="danger" title={failure} /> : null}
            <div>
              <Button variant="primary" onClick={() => void upload()} disabled={busy}>
                {busy ? 'Uploading…' : 'Upload and check'}
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>
    </EnterprisePage>
  );
}

function TemplateFacts({ template, needs, canCommit }: { template: ImportTemplateInfo; needs: string[]; canCommit: boolean }) {
  return (
    <>
      <InlineAlert tone="info" title={template.mode === 'ALL_OR_NOTHING' ? 'Everything or nothing' : 'Valid rows are saved'}>
        {template.mode === 'ALL_OR_NOTHING'
          ? 'Nothing is saved unless every row is valid. The result lands as Draft versions that still need approval by a second person.'
          : 'Rows with errors are skipped. The error file lists each row number with its problem, for reading only: fix those rows in your own file and upload it again.'}
        {needs.length ? ` Codes used here must exist first: import ${needs.join(', ')} before this one.` : ''}
        {canCommit
          ? ''
          : ` You can upload and check this file; committing it needs a role that may change ${TARGET[template.permission] ?? 'this master data'}.`}
      </InlineAlert>
      <div className="overflow-x-auto rounded-md border border-line">
        <table className="w-full border-separate border-spacing-0 text-[13px]">
          <caption className="sr-only">Columns of the {template.title} template</caption>
          <thead>
            <tr>
              {['Column', 'Required', 'What to enter'].map((h) => (
                <th key={h} scope="col" className="border-b border-line bg-subtle px-3 py-2 text-left text-xs font-semibold text-ink-2">
                  {h}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {template.columns.map((c) => (
              <tr key={c.header}>
                <th scope="row" className="border-b border-line px-3 py-2 text-left font-medium whitespace-nowrap">
                  {c.header}
                </th>
                <td className="border-b border-line px-3 py-2">{c.required ? 'Yes' : 'No'}</td>
                <td className="border-b border-line px-3 py-2 text-ink-2">{c.hint ?? ''}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </>
  );
}

/* ---------------------------------------------------------------- one job */

type RowView = 'ALL' | ImportRowStatus;

function JobPage({ jobId }: { jobId: string }) {
  const router = useRouter();
  const client = useQueryClient();
  const can = useCan();
  const templates = useTemplates(true);
  const [notice, setNotice] = React.useState<string | null>(null);
  const [failure, setFailure] = React.useState<string | null>(null);
  const [busy, setBusy] = React.useState(false);
  const [rejecting, setRejecting] = React.useState(false);

  const job = useQuery({
    queryKey: ['import', 'job', jobId],
    queryFn: async ({ signal }) =>
      ((await call(() => api.GET('/exchange/imports/{job}', { params: { path: { job: jobId } }, signal }))) as { data: ImportJob }).data,
    refetchInterval: (q) => (q.state.data && isRunning(q.state.data.status) ? 1500 : false),
  });

  if (job.isPending) return <LoadingState title="Loading import job" />;
  if (job.isError) {
    const missing = job.error instanceof ApiError && job.error.status === 404;
    return (
      <div className="p-6 max-md:p-4">
        <ErrorState
          title={missing ? 'This import job does not exist' : 'The import job could not be loaded'}
          description={missing ? 'It may belong to another workspace.' : failureMessage(job.error)}
          action={
            missing ? undefined : (
              <Button size="sm" onClick={() => void job.refetch()}>
                Try again
              </Button>
            )
          }
        />
        <Button className="mt-4" onClick={() => router.push('/master/import')}>
          <ArrowLeft aria-hidden="true" className="size-4" />
          All imports
        </Button>
      </div>
    );
  }

  const j = job.data;
  const template = templates.data?.find((t) => t.code === j.template);
  const allOrNothing = template?.mode === 'ALL_OR_NOTHING';

  const act = async (action: 'submit' | 'commit', done: string) => {
    setBusy(true);
    setFailure(null);
    setNotice(null);
    try {
      const path = { params: { path: { job: j.id } } };
      const result = (await call(() =>
        action === 'submit' ? api.POST('/exchange/imports/{job}/actions/submit', path) : api.POST('/exchange/imports/{job}/actions/commit', path),
      )) as { data: ImportJob };
      client.setQueryData(['import', 'job', jobId], result.data);
      void client.invalidateQueries({ queryKey: ['import', 'jobs'] });
      setNotice(done);
    } catch (e) {
      setFailure(failureMessage(e, `The job could not be ${action === 'submit' ? 'submitted' : 'committed'}.`));
      void job.refetch();
    } finally {
      setBusy(false);
    }
  };

  const submitBlock =
    j.status !== 'VALIDATED'
      ? undefined
      : !can.submit
        ? 'Your roles do not allow submitting imports.'
        : j.validRows === 0
          ? 'No row is valid.'
          : allOrNothing && j.errorRows > 0
            ? 'Every row must be valid for this template.'
            : undefined;
  const commitBlock =
    j.status !== 'SUBMITTED'
      ? undefined
      : !can.post
        ? 'Your roles do not allow committing imports.'
        : template && !can.has(template.permission)
          ? `Committing needs a role that may change ${TARGET[template.permission] ?? 'this master data'}.`
          : undefined;
  const canReject = can.post && (j.status === 'VALIDATED' || j.status === 'SUBMITTED');
  // a file with rows to fix can be uploaded again, also after its valid rows were committed
  const uploadAgain = ['FAILED', 'REJECTED'].includes(j.status) || (['VALIDATED', 'COMMITTED'].includes(j.status) && j.errorRows > 0);

  return (
    <EnterprisePage
      title={j.fileName}
      description={`${template?.title ?? j.template} template, version ${j.templateVersion}. ${jobSummary(j, template)}`}
      meta={<DomainStatusBadge domain="importJob" status={j.status} />}
      actions={
        <>
          <Button onClick={() => router.push('/master/import')}>
            <ArrowLeft aria-hidden="true" className="size-4" />
            All imports
          </Button>
          {j.hasErrorFile ? (
            <Button asChild>
              <a href={errorFileUrl(j.id)} download>
                <Download aria-hidden="true" className="size-4" />
                Error file
              </a>
            </Button>
          ) : null}
          {uploadAgain ? (
            <Button onClick={() => router.push(`/master/import?new&template=${encodeURIComponent(j.template)}`)}>
              <Upload aria-hidden="true" className="size-4" />
              Upload corrected file
            </Button>
          ) : null}
          {canReject ? (
            <Button onClick={() => setRejecting(true)} disabled={busy}>
              Reject
            </Button>
          ) : null}
          {j.status === 'VALIDATED' ? (
            <Gated reason={submitBlock} label="Submit for commit" busy={busy} onClick={() => void act('submit', 'Submitted for commit')} />
          ) : null}
          {j.status === 'SUBMITTED' ? <Gated reason={commitBlock} label="Commit" busy={busy} onClick={() => void act('commit', 'Commit started')} /> : null}
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
      {failure ? <InlineAlert tone="danger" title={failure} className="mb-4" /> : null}
      {j.status === 'FAILED' ? (
        <InlineAlert tone="danger" title="The job stopped" className="mb-4">
          {j.failureDetail ?? 'Upload the same file again to retry.'}
        </InlineAlert>
      ) : null}
      {j.status === 'REJECTED' && j.rejectReason ? (
        <InlineAlert tone="warning" title="Rejected" className="mb-4">
          {j.rejectReason}
        </InlineAlert>
      ) : null}

      <JobSteps job={j} />
      <JobFacts job={j} onOpen={(id) => router.push(`/master/import?job=${id}`)} />
      {j.status === 'RESERVED' || j.status === 'VALIDATING' ? (
        <LoadingState title="Checking every row against the template rules. This page updates by itself." />
      ) : j.totalRows > 0 && template ? (
        <JobRows job={j} template={template} />
      ) : null}

      <ConfirmDialog
        open={rejecting}
        onOpenChange={setRejecting}
        title="Reject this import"
        description="Nothing from this file is saved. The uploader can fix the file and upload it again."
        confirmLabel="Reject import"
        tone="danger"
        reason={{ label: 'Reason', minLength: 3 }}
        onConfirm={async (reason) => {
          const result = (await call(() =>
            api.POST('/exchange/imports/{job}/actions/reject', { params: { path: { job: j.id } }, body: { reason: reason ?? '' } }),
          )) as { data: ImportJob };
          client.setQueryData(['import', 'job', jobId], result.data);
          void client.invalidateQueries({ queryKey: ['import', 'jobs'] });
          setRejecting(false);
          setNotice('Import rejected');
        }}
      />
    </EnterprisePage>
  );
}

/** A primary action that stays focusable with its reason when the user's roles or the job do not allow it. */
function Gated({ reason, label, busy, onClick }: { reason: string | undefined; label: string; busy: boolean; onClick: () => void }) {
  if (reason) {
    return (
      <Tooltip content={reason}>
        <Button variant="primary" aria-disabled="true" aria-label={`${label}. ${reason}`} onClick={(e) => e.preventDefault()}>
          {label}
        </Button>
      </Tooltip>
    );
  }
  return (
    <Button variant="primary" onClick={onClick} disabled={busy}>
      {busy ? 'Working…' : label}
    </Button>
  );
}

function JobSteps({ job }: { job: ImportJob }) {
  const { current, failed } = jobStep(job);
  const finished = current === STEPS.length - 1 && !failed && !isRunning(job.status);
  return (
    <ol aria-label="Import steps" className="m-0 mb-4 flex list-none flex-wrap gap-x-6 gap-y-2 p-0 text-[13px]">
      {STEPS.map((s, i) => {
        const done = i < current || (finished && i === current);
        const state = failed && i === current ? 'failed' : done ? 'done' : i === current ? 'current' : 'todo';
        return (
          <li
            key={s}
            aria-current={i === current ? 'step' : undefined}
            className={cn('flex items-center gap-1.5', state === 'current' ? 'font-semibold text-accent' : state === 'todo' ? 'text-ink-2' : 'text-ink')}
          >
            <span
              aria-hidden="true"
              className={cn(
                'grid size-5 place-items-center rounded-full text-[11px] font-semibold',
                state === 'done' && 'bg-stat-ok-bg text-stat-ok-fg',
                state === 'failed' && 'bg-stat-err-bg text-stat-err-fg',
                state === 'current' && 'bg-accent text-on-accent',
                state === 'todo' && 'bg-stat-neutral-bg text-ink-2',
              )}
            >
              {state === 'done' ? <Check className="size-3" /> : state === 'failed' ? <X className="size-3" /> : i + 1}
            </span>
            {s}
            {state === 'done' ? <span className="sr-only"> (done)</span> : state === 'failed' ? <span className="sr-only"> (failed)</span> : null}
          </li>
        );
      })}
    </ol>
  );
}

function JobFacts({ job, onOpen }: { job: ImportJob; onOpen: (id: string) => void }) {
  const counts: [string, number, string?][] = [
    ['Rows in file', job.totalRows],
    ['Valid', job.validRows],
    ['With errors', job.errorRows],
    ['Committed', job.committedRows],
  ];
  if (job.skippedRows > 0) counts.push(['Already committed earlier', job.skippedRows]);
  const people: [string, string][] = [
    ['Uploaded', `${when(job.createdAt)}${job.uploadedByName ? ` by ${job.uploadedByName}` : ''} · ${fileSize(job.fileSize)}`],
    ...(job.submittedAt ? ([['Submitted', `${when(job.submittedAt)}${job.submittedByName ? ` by ${job.submittedByName}` : ''}`]] as [string, string][]) : []),
    ...(job.committedAt ? ([['Committed', `${when(job.committedAt)}${job.committedByName ? ` by ${job.committedByName}` : ''}`]] as [string, string][]) : []),
  ];
  return (
    <div className="mb-4 flex flex-col gap-3">
      <dl className="m-0 grid grid-cols-[repeat(auto-fill,minmax(150px,1fr))] gap-2">
        {counts.map(([k, v]) => (
          <div key={k} className="rounded-md border border-line bg-surface px-3 py-2">
            <dt className="text-xs text-ink-2">{k}</dt>
            <dd className="m-0 text-lg font-semibold tabular-nums">{v.toLocaleString('en-US')}</dd>
          </div>
        ))}
      </dl>
      <dl className="m-0 flex flex-wrap gap-x-6 gap-y-1 text-[13px]">
        {people.map(([k, v]) => (
          <div key={k} className="flex gap-1.5">
            <dt className="text-ink-2">{k}</dt>
            <dd className="m-0">{v}</dd>
          </div>
        ))}
        {job.retryOfJobId ? (
          <div className="flex gap-1.5">
            <dt className="text-ink-2">Retry of</dt>
            <dd className="m-0">
              <button
                type="button"
                className="text-accent underline-offset-2 hover:underline focus-visible:outline-2 focus-visible:outline-ring"
                onClick={() => onOpen(job.retryOfJobId!)}
              >
                the previous job
              </button>
            </dd>
          </div>
        ) : null}
      </dl>
    </div>
  );
}

function JobRows({ job, template }: { job: ImportJob; template: ImportTemplateInfo }) {
  const [view, setView] = React.useState<RowView>(job.errorRows > 0 ? 'ERROR' : 'ALL');
  const [page, setPage] = React.useState(1);
  const [density, setDensity] = useDensityPreference();
  const rows = useQuery({
    // the job's state is part of the key: rows change when it is checked or committed
    queryKey: ['import', 'rows', job.id, job.status, job.committedRows, view, page],
    queryFn: async ({ signal }) =>
      (await call(() =>
        api.GET('/exchange/imports/{job}/rows', {
          params: { path: { job: job.id }, query: { status: view === 'ALL' ? undefined : view, page } },
          signal,
        }),
      )) as { data: ImportRow[]; meta: { total: number } },
    placeholderData: keepPreviousData,
  });

  const columns = React.useMemo<EoraColumn<ImportRow>[]>(
    () => [
      { id: 'rowNo', header: 'Row', lead: true, cell: (r) => `Row ${r.rowNo}` },
      { id: 'status', header: 'Result', critical: true, cell: (r) => <DomainStatusBadge domain="importRow" status={r.status} /> },
      {
        id: 'errors',
        header: 'Problems',
        critical: true,
        minWidth: 280,
        cell: (r) =>
          r.errors.length ? (
            <ul className="m-0 list-none p-0 text-stat-err-fg">
              {r.errors.map((e) => (
                <li key={e}>{e}</li>
              ))}
            </ul>
          ) : (
            ''
          ),
        emptyOnPhone: (r) => r.errors.length === 0,
      },
      ...template.columns.map<EoraColumn<ImportRow>>((c) => ({
        id: `v:${c.header}`,
        header: c.header,
        textCol: true,
        cell: (r) => r.values[c.header] ?? '',
        title: (r) => r.values[c.header] || undefined,
        emptyOnPhone: (r) => !r.values[c.header],
      })),
    ],
    [template.columns],
  );

  const tabs = [
    { value: 'ALL', label: 'All rows', count: job.totalRows },
    { value: 'ERROR', label: 'With errors', count: job.errorRows },
    { value: 'VALID', label: 'Valid', count: job.validRows },
    { value: 'COMMITTED', label: 'Committed', count: job.committedRows },
    ...(job.skippedRows > 0 ? [{ value: 'SKIPPED', label: 'Already committed earlier', count: job.skippedRows }] : []),
  ];
  const total = rows.data?.meta.total ?? 0;
  const pages = Math.max(1, Math.ceil(total / ROWS_PER_PAGE));

  return (
    <Card>
      <StatusTabs
        label="Rows"
        items={tabs}
        value={view}
        onValueChange={(v) => {
          setView(v as RowView);
          setPage(1);
        }}
      >
        <div className="flex items-center justify-end border-b border-line px-5 py-2 max-md:hidden">
          <DensityToggle value={density} onChange={setDensity} />
        </div>
        <DataTable
          label="Rows of this file"
          columns={columns}
          rows={rows.data?.data ?? (EMPTY as ImportRow[])}
          getRowId={(r) => String(r.rowNo)}
          rowLabel={(r) => `Row ${r.rowNo}`}
          status={rows.isError ? 'error' : rows.isPending ? 'loading' : 'ready'}
          error={rows.error}
          onRetry={() => rows.refetch()}
          isFetching={rows.isFetching && !rows.isPending}
          density={density}
          skeletonRows={8}
          empty={<EmptyState title="No rows in this view" />}
          sort={NO_SORT}
          onSortChange={() => undefined}
        />
        {total > ROWS_PER_PAGE ? (
          <nav
            aria-label="Pages of rows"
            className="flex flex-wrap items-center justify-between gap-3 border-t border-line px-5 py-3 text-[13px] text-ink-2 max-md:px-4"
          >
            <p className="m-0" aria-live="polite">
              Rows{' '}
              <b className="font-semibold text-ink tabular-nums">
                {((page - 1) * ROWS_PER_PAGE + 1).toLocaleString('en-US')}–{Math.min(total, page * ROWS_PER_PAGE).toLocaleString('en-US')}
              </b>{' '}
              of <b className="font-semibold text-ink tabular-nums">{total.toLocaleString('en-US')}</b>
            </p>
            <div className="flex gap-2">
              <Button size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>
                Previous
              </Button>
              <Button size="sm" disabled={page >= pages} onClick={() => setPage(page + 1)}>
                Next
              </Button>
            </div>
          </nav>
        ) : null}
      </StatusTabs>
    </Card>
  );
}
