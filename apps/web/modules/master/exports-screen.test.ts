import { describe, expect, it } from 'vitest';
import { downloadable, exportNote, shownStatus, type ExportJob } from './exports-screen';

const NOW = Date.parse('2026-10-28T05:00:00Z');
const JOB = {
  id: 'j',
  resource: 'core.items',
  title: 'Items',
  status: 'DONE',
  failureCode: null,
  failureDetail: null,
  rowCount: 4,
  fileName: 'items.xlsx',
  fileSize: 100,
  finishedAt: '2026-10-28T04:00:00Z',
  expiresAt: '2026-10-29T04:00:00Z',
  createdAt: '2026-10-28T03:59:00Z',
} as ExportJob;

describe('exports page rules (D-092)', () => {
  it('offers a file only while the job is done and its 24 hours have not run out', () => {
    expect(downloadable(JOB, NOW)).toBe(true);
    expect(downloadable({ ...JOB, expiresAt: '2026-10-28T04:59:59Z' }, NOW)).toBe(false);
    expect(downloadable({ ...JOB, status: 'RUNNING' }, NOW)).toBe(false);
  });

  it('shows a done job past its expiry as deleted before the hourly clean-up marks it', () => {
    expect(shownStatus(JOB, NOW)).toBe('DONE');
    expect(shownStatus({ ...JOB, expiresAt: '2026-10-28T04:00:00Z' }, NOW)).toBe('EXPIRED');
    expect(shownStatus({ ...JOB, status: 'FAILED', expiresAt: null }, NOW)).toBe('FAILED');
  });

  it('says why a row cannot be downloaded', () => {
    expect(exportNote({ ...JOB, status: 'QUEUED' }, NOW)).toContain('Being written');
    expect(exportNote({ ...JOB, status: 'FAILED', failureDetail: 'Too many rows.' }, NOW)).toBe('Too many rows.');
    expect(exportNote({ ...JOB, status: 'EXPIRED', expiresAt: null }, NOW)).toContain('Export the list again');
  });
});
