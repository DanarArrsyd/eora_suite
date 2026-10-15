import { describe, expect, it } from 'vitest';
import { queryString } from './source';

describe('queryString (doc 24 §3.1 grammar)', () => {
  it('serializes filters, search, sort and paging', () => {
    const qs = new URLSearchParams(
      queryString({
        filters: { status: 'ACTIVE', locationType: ['RM_WAREHOUSE', 'QC_AREA'], plantId: undefined, empty: '' },
        search: '  rack ',
        sort: [
          { id: 'plantCode', desc: false },
          { id: 'code', desc: true },
        ],
        page: 3,
        pageSize: 50,
      }),
    );
    expect(qs.get('filter[status]')).toBe('ACTIVE');
    expect(qs.getAll('filter[locationType][]')).toEqual(['RM_WAREHOUSE', 'QC_AREA']);
    expect(qs.has('filter[plantId]')).toBe(false);
    expect(qs.has('filter[empty]')).toBe(false);
    expect(qs.get('q')).toBe('rack');
    expect(qs.get('sort')).toBe('plantCode,-code');
    expect(qs.get('page[number]')).toBe('3');
    expect(qs.get('page[size]')).toBe('50');
  });

  it('leaves out an empty search and sort', () => {
    const qs = new URLSearchParams(queryString({ filters: {}, search: '', sort: [], page: 1, pageSize: 20 }));
    expect([...qs.keys()]).toEqual(['page[number]', 'page[size]']);
  });
});
