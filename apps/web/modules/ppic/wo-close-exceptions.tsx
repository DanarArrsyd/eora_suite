'use client';

import { DomainStatusBadge } from '@eora/features/patterns';
import { MasterScreen } from '@/modules/master/master-screen';
import { PLANT_FILTER } from '@/modules/master/resources';
import type { MasterResource } from '@/modules/master/types';
import { when } from '@/modules/master/version-ui';

/*
 * M4 step 8 (doc 33 §1.8, D-117, BR-PLN-03): PPIC → WO Close Exceptions on the live API. Material left at production that
 * is not returned is scrapped by an exception a planner asks for; CSA or INV decides it on the work order's page.
 */

type ExceptionRow = {
  id: string;
  code: string;
  version: number;
  status: 'SUBMITTED' | 'REJECTED' | 'POSTED' | 'REVERSED';
  plantCode: string;
  workOrderId: string;
  workOrderCode: string;
  itemCode: string;
  lines: number;
  scrapped: string | null;
  reason: string | null;
  requestedByName: string | null;
  decidedByName: string | null;
  rejectReason: string | null;
  at: string;
};

const STATUS = [
  { value: 'SUBMITTED', label: 'Waiting for approval' },
  { value: 'POSTED', label: 'Posted' },
  { value: 'REJECTED', label: 'Rejected' },
  { value: 'REVERSED', label: 'Reversed' },
];

export const WO_CLOSE_EXCEPTIONS: MasterResource<ExceptionRow> = {
  id: 'wo-close-exceptions',
  resource: 'planning.wo_close_exceptions',
  permission: {
    view: 'planning.wo_close_exception.view',
    create: 'planning.wo_close_exception.request_correction',
    label: 'close exceptions',
    alsoView: ['planning.wo_close_exception.approve'],
  },
  noCreate: true,
  title: 'WO Close Exceptions',
  description:
    'Material left at production that will not be returned, asked to go to the scrap yard so its work order can close. A second person decides it on the work order.',
  itemLabel: 'exceptions',
  singular: 'exception',
  searchPlaceholder: 'Search exception…',
  rowLabel: (r) => `${r.code} ${r.workOrderCode}`,
  openHref: (r) => `/ppic/work-orders?order=${r.workOrderId}`,
  defaultSort: [{ id: 'at', desc: true }],
  filters: [PLANT_FILTER, { key: 'status', type: 'multiselect', source: 'static', label: 'Status', options: STATUS }],
  filterPermissions: { plantId: 'master.org.view' },
  columns: [
    {
      id: 'at',
      header: 'When',
      lead: true,
      sortable: true,
      cell: (r) => when(r.at),
      sub: (r) => (r.code ? <span className="font-mono">{r.code}</span> : undefined),
    },
    { id: 'workOrderCode', header: 'Work order', cell: (r) => <span className="font-mono">{r.workOrderCode}</span>, sub: (r) => r.itemCode },
    { id: 'scrapped', header: 'To the scrap yard', cell: (r) => r.scrapped ?? '—', sub: (r) => r.reason ?? undefined },
    { id: 'requestedByName', header: 'Asked by', cell: (r) => r.requestedByName ?? '—', sub: (r) => r.decidedByName ?? undefined },
    { id: 'status', header: 'Status', sortable: true, cell: (r) => <DomainStatusBadge domain="document" status={r.status} /> },
  ],
  fields: [],
  blank: {},
  toForm: () => ({}),
  create: () => Promise.reject(new Error('Close exceptions are asked on the work order.')),
  update: () => Promise.reject(new Error('A close exception is decided on the work order.')),
  readOnly: () => 'A close exception is decided on the work order.',
};

/** PPIC → WO Close Exceptions: the list; each row opens its work order. */
export function WoCloseExceptionsScreen() {
  return <MasterScreen config={WO_CLOSE_EXCEPTIONS} />;
}
