import { describe, expect, it } from 'vitest';
import { fileSize, jobStep, jobSummary, precheckFile, type ImportJob, type ImportTemplateInfo } from './import-model';

const JOB = { status: 'VALIDATED', validRows: 3, errorRows: 1, failureDetail: null, template: 'routings' } as ImportJob;
const TEMPLATE = { code: 'routings', title: 'Routings', mode: 'ALL_OR_NOTHING' } as ImportTemplateInfo;

describe('import job steps', () => {
  it('places each status on its step', () => {
    expect(jobStep({ status: 'RESERVED', submittedAt: null })).toEqual({ current: 1, failed: false });
    expect(jobStep({ status: 'VALIDATED', submittedAt: null })).toEqual({ current: 2, failed: false });
    expect(jobStep({ status: 'SUBMITTED', submittedAt: '2026-10-24T02:00:00Z' })).toEqual({ current: 3, failed: false });
    expect(jobStep({ status: 'COMMITTED', submittedAt: '2026-10-24T02:00:00Z' })).toEqual({ current: 4, failed: false });
  });

  it('fails at Check before submit (e.g. wrong headers), else at Result', () => {
    expect(jobStep({ status: 'FAILED', submittedAt: null })).toEqual({ current: 1, failed: true });
    expect(jobStep({ status: 'FAILED', submittedAt: '2026-10-24T02:00:00Z' })).toEqual({ current: 4, failed: true });
  });

  it('tells an all-or-nothing job with errors to fix the file instead of submitting', () => {
    expect(jobSummary(JOB, TEMPLATE)).toContain('everything or nothing');
    expect(jobSummary({ ...JOB, errorRows: 0 }, TEMPLATE)).toContain('submit the job');
    expect(jobSummary({ ...JOB, status: 'COMMITTED' }, TEMPLATE)).toContain('Draft versions');
    expect(jobSummary({ ...JOB, validRows: 0 }, { ...TEMPLATE, mode: 'VALID_ROWS' })).toContain('fix those rows in your own file');
  });
});

describe('precheckFile', () => {
  it('accepts an .xlsx up to 10 MB only', () => {
    expect(precheckFile({ name: 'Items.XLSX', size: 2048 })).toBeUndefined();
    expect(precheckFile({ name: 'items.csv', size: 2048 })).toContain('.xlsx');
    expect(precheckFile({ name: 'items.xlsx', size: 0 })).toBe('The file is empty.');
    expect(precheckFile({ name: 'items.xlsx', size: 10 * 1024 * 1024 + 1 })).toContain('10 MB');
  });

  it('formats sizes', () => {
    expect(fileSize(300)).toBe('1 KB');
    expect(fileSize(52_000)).toBe('51 KB');
    expect(fileSize(3 * 1024 * 1024)).toBe('3.0 MB');
  });
});
