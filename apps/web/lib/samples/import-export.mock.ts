import type { ExportSource, ImportSource } from '@eora/features/patterns';

/** MOCK import/export adapters (doc 22 §2): no file is read or produced, nothing is posted. */
const wait = (ms: number) => new Promise((r) => setTimeout(r, ms));
let seq = 5520;

export const mockExportSource: ExportSource = {
  isMock: true,
  async queue() {
    await wait(300);
    seq += 1;
    return { jobId: `EXP-${seq}` };
  },
};

const committed = new Map<string, number>();

export const mockImportSource: ImportSource = {
  isMock: true,
  templateName: 'RM lot opening balance v1',
  templateColumns: [
    { name: 'Lot', required: true },
    { name: 'Item', required: true, note: 'Must exist in item master' },
    { name: 'Quantity', required: true, note: 'Base UOM; kg up to 3 decimals' },
    { name: 'UOM', required: true },
    { name: 'Location', required: true },
    { name: 'Heat no.', required: false },
    { name: 'Mill certificate', required: false },
  ],
  async validate(file) {
    await wait(900);
    return {
      jobId: `IMP-${file.name.length}${file.size % 1000}`,
      fileName: file.name,
      totalRows: 48,
      validRows: 45,
      errors: [
        { row: 7, column: 'Item', message: 'Item RM-SPCC-09-100 does not exist' },
        { row: 19, column: 'Quantity', message: 'Use up to 3 decimals for kg (got 12.3456)' },
        { row: 33, column: 'Location', message: 'Location RM-WH9-A01 is not in Plant A' },
      ],
      requiresApproval: true,
    };
  },
  async commit(jobId, { idempotencyKey }) {
    await wait(700);
    // Same key → same result, never a second posting (doc 14 #15).
    if (!committed.has(idempotencyKey)) committed.set(idempotencyKey, 45);
    void jobId;
    return { status: 'AWAITING_APPROVAL', committedRows: committed.get(idempotencyKey)! };
  },
};
