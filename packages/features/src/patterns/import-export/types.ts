export type ExportFormat = 'xlsx' | 'pdf';

/** Export runs the same server QueryDefinition and permission scope as the table (ADR-0008, doc 14 #13). */
export interface ExportSource {
  readonly isMock: boolean;
  queue(format: ExportFormat, scope: { resource: string; filtersApplied: number }): Promise<{ jobId: string }>;
}

export type ImportRowError = { row: number; column: string; message: string };
export type ImportValidation = {
  jobId: string;
  fileName: string;
  totalRows: number;
  validRows: number;
  errors: ImportRowError[];
  /** Policy-driven (e.g. stock-affecting imports need independent approval). */
  requiresApproval: boolean;
};
export type ImportCommitResult = { status: 'COMMITTED' | 'PARTIALLY_COMMITTED' | 'AWAITING_APPROVAL'; committedRows: number };

/** Import job contract (doc 08, doc 16 §11): validate → preview/errors → approval → idempotent commit. */
export interface ImportSource {
  readonly isMock: boolean;
  readonly templateName: string;
  readonly templateColumns: { name: string; required: boolean; note?: string }[];
  validate(file: File, signal?: AbortSignal): Promise<ImportValidation>;
  commit(jobId: string, opts: { idempotencyKey: string; validRowsOnly: boolean }): Promise<ImportCommitResult>;
}
