/*
 * Master Data → Data Exchange → Import (doc 29 §1.6, doc 26 §5, ADR-0017, D-081): what the screens show for an import
 * job. The server reads, checks and commits every row; nothing here decides whether a row is valid.
 */

export type ImportJobStatus = 'RESERVED' | 'VALIDATING' | 'VALIDATED' | 'SUBMITTED' | 'COMMITTING' | 'COMMITTED' | 'REJECTED' | 'FAILED';
export type ImportRowStatus = 'VALID' | 'ERROR' | 'SKIPPED' | 'COMMITTED';

/** `GET /exchange/import-templates` — a template the tenant can import, at its current version. */
export type ImportTemplateInfo = {
  code: string;
  version: number;
  title: string;
  mode: 'VALID_ROWS' | 'ALL_OR_NOTHING';
  /** Create permission of the target master; checked again when the job is committed. */
  permission: string;
  columns: { header: string; required: boolean; hint: string | null }[];
};

/** `exchange.import_jobs` row (ImportJobsQuery). */
export type ImportJob = {
  id: string;
  template: string;
  templateVersion: number;
  fileName: string;
  fileSize: number;
  status: ImportJobStatus;
  failureCode: string | null;
  failureDetail: string | null;
  rejectReason: string | null;
  retryOfJobId: string | null;
  rootJobId: string;
  hasErrorFile: boolean;
  totalRows: number;
  validRows: number;
  errorRows: number;
  skippedRows: number;
  committedRows: number;
  uploadedByName: string | null;
  submittedByName: string | null;
  submittedAt: string | null;
  committedByName: string | null;
  committedAt: string | null;
  createdAt: string;
  updatedAt: string;
  version: number;
};

/** `GET /exchange/imports/{job}/rows` — one staged row with its result. */
export type ImportRow = {
  rowNo: number;
  status: ImportRowStatus;
  entityRef: string | null;
  values: Record<string, string>;
  errors: string[];
};

/** The worker is still busy with the job: the page refreshes it until it settles. */
export const RUNNING: readonly ImportJobStatus[] = ['RESERVED', 'VALIDATING', 'COMMITTING'];

export const isRunning = (status: ImportJobStatus) => RUNNING.includes(status);

export const STEPS = ['Upload', 'Check', 'Review', 'Commit', 'Result'] as const;

/**
 * Where a job stands in the five steps: the step it is on and whether that step failed. Upload is done once a job
 * exists; a job that failed before it was submitted (e.g. wrong headers) failed at Check, one after it at Result.
 */
export function jobStep(job: Pick<ImportJob, 'status' | 'submittedAt'>): { current: number; failed: boolean } {
  switch (job.status) {
    case 'RESERVED':
    case 'VALIDATING':
      return { current: 1, failed: false };
    case 'VALIDATED':
      return { current: 2, failed: false };
    case 'SUBMITTED':
    case 'COMMITTING':
      return { current: 3, failed: false };
    case 'COMMITTED':
    case 'REJECTED':
      return { current: 4, failed: false };
    case 'FAILED':
      return job.submittedAt === null ? { current: 1, failed: true } : { current: 4, failed: true };
  }
}

/** What the job page says the job is waiting for, by status. */
export function jobSummary(job: ImportJob, template: ImportTemplateInfo | undefined): string {
  const name = template?.title ?? job.template;
  switch (job.status) {
    case 'RESERVED':
      return `Waiting for a worker to read the file.`;
    case 'VALIDATING':
      return `Checking every row against the ${name} rules.`;
    case 'VALIDATED':
      return job.validRows === 0
        ? 'No row can be imported. The error file lists each row number with its problem; fix those rows in your own file and upload it again.'
        : template?.mode === 'ALL_OR_NOTHING' && job.errorRows > 0
          ? 'This template imports everything or nothing. Fix the rows with errors in your own file and upload it again.'
          : 'Review the rows, then submit the job for commit.';
    case 'SUBMITTED':
      return 'Waiting for someone allowed to commit imports.';
    case 'COMMITTING':
      return 'Saving the valid rows.';
    case 'COMMITTED':
      return template?.mode === 'ALL_OR_NOTHING'
        ? 'Saved as Draft versions. Each one still needs approval by a second person on its own page.'
        : 'Saved. The records are in their lists now.';
    case 'REJECTED':
      return 'Rejected. Nothing was saved. The same file can be uploaded again.';
    case 'FAILED':
      return job.failureDetail ?? 'The job stopped. Upload the same file again to retry.';
  }
}

/** Templates to import first, because their rows refer to these codes (plants come from the Plants screen). */
export const NEEDS: Record<string, string[]> = {
  items: ['uoms'],
  material_specs: ['items'],
  uom_conversions: ['items', 'uoms'],
  customer_items: ['customers', 'items'],
  locations: ['warehouses'],
  machines: ['work_centers'],
  die_items: ['dies', 'items'],
  routings: ['items', 'work_centers', 'process_types'],
  boms: ['items', 'uoms'],
  bom_routing_maps: ['routings', 'boms'],
};

export const MAX_BYTES = 10 * 1024 * 1024;

/** Client-side check before uploading (type and size); the server inspects the workbook and every row again. */
export function precheckFile(file: { name: string; size: number }): string | undefined {
  if (!file.name.toLowerCase().endsWith('.xlsx')) return `Use the Excel template (.xlsx). “${file.name}” is not an .xlsx file.`;
  if (file.size === 0) return 'The file is empty.';
  if (file.size > MAX_BYTES) return 'The file is larger than 10 MB. Split the rows over several files.';
  return undefined;
}

export function fileSize(bytes: number): string {
  return bytes < 1024 * 1024 ? `${Math.max(1, Math.round(bytes / 1024)).toLocaleString('en-US')} KB` : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

/** Links that download through the same session (cookie); no data passes through the page. */
export const templateFileUrl = (code: string) => `/api/v1/exchange/import-templates/${encodeURIComponent(code)}/file`;
export const errorFileUrl = (job: string) => `/api/v1/exchange/imports/${encodeURIComponent(job)}/error-file`;
