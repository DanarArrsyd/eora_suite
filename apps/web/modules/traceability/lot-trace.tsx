'use client';

import * as React from 'react';
import { useQuery } from '@tanstack/react-query';
import { ArrowLeftToLine, ArrowRightToLine, Search } from 'lucide-react';
import { usePathname, useRouter, useSearchParams } from 'next/navigation';
import { ApiError, api, call, failureMessage, useMe } from '@eora/features/auth';
import { EnterprisePage } from '@eora/features/patterns';
import { Badge, Button, Card, CardContent, CardHeader, CardTitle, EmptyState, Field, InlineAlert, Input, LoadingState, PermissionDeniedState } from '@eora/ui';
import { when } from '@/modules/master/version-ui';

/*
 * M4 step 9 (doc 33 §1.9, D-118, doc 14 #18): Traceability → Backward / Forward Trace on the live API. One search box
 * takes a lot number or a shipment number; the answer is an indented tree read from the ledger and its genealogy:
 * how each lot was made (receipt, production posting with run and WO, split), what of it was used, who posted it and
 * when, and its shipments. Documents of plants outside the viewer's scope show only as "Outside your plants".
 */

export type Direction = 'backward' | 'forward';

type Via =
  | { outsideYourPlants: true }
  | {
      docNo: string | null;
      docType: string;
      reversed: boolean;
      runNo: string | null;
      workOrderNo: string | null;
      postedByName: string | null;
      postedAt: string | null;
      qtyUsed: string;
      uomUsed: string;
      qtyMade: string;
      uomMade: string;
    };

type Shipment = {
  docNo: string;
  reversed: boolean;
  postedByName: string | null;
  postedAt: string | null;
  customerCode: string;
  customerName: string;
  customerPoNo: string | null;
  soNo: string;
  lineNo: number;
  scheduleNo: number;
  qty: string;
  uomCode: string;
};

type TraceNode = {
  key: string;
  parentKey: string | null;
  depth: number;
  lotId: string;
  restricted: boolean;
  via: Via | null;
  lotNo: string;
  itemCode: string;
  itemName: string;
  uomCode: string;
  origin: 'RECEIPT' | 'OUTPUT' | 'SPLIT';
  supplierLotNo: string | null;
  receipt: { docNo: string; supplierCode: string | null; supplierName: string | null; postedByName: string | null; postedAt: string | null } | null;
  shipments: Shipment[];
};

type Match = { lotId: string; lotNo: string; itemCode: string; itemName: string };

type TraceAnswer =
  | {
      data: {
        direction: Direction;
        start: { kind: 'shipment'; docNo: string } | { kind: 'lot'; lotNo: string; itemCode: string };
        nodes: TraceNode[];
        truncated: boolean;
      };
    }
  | { data: { direction: Direction; matches: Match[] } };

const ORIGIN: Record<TraceNode['origin'], string> = { RECEIPT: 'Received', OUTPUT: 'Made', SPLIT: 'Split' };
const DOC: Record<string, string> = { PRODUCTION_POSTING: 'Production posting', LOT_SPLIT: 'Lot split', REWORK_POSTING: 'Rework posting' };

const COPY: Record<Direction, { title: string; description: string; empty: string; hint: string }> = {
  backward: {
    title: 'Backward Trace',
    description:
      'From a shipment or a lot back to everything it was made of: production postings with run and work order, and the material lots received from suppliers.',
    empty: 'Enter a lot number or a shipment number (SHP-…) to see what it was made of.',
    hint: 'Lot or shipment number',
  },
  forward: {
    title: 'Forward Trace',
    description: 'From a lot to every lot made of it and every shipment that carried it — the customers to contact for a recall.',
    empty: 'Enter a lot number to see where it went.',
    hint: 'Lot number',
  },
};

function refusal(e: unknown): string {
  if (e instanceof ApiError) {
    if (e.status === 404) return 'Nothing with this number in your plants. Check the lot or shipment number.';
    const errors = (e.problem as { errors?: Record<string, string[]> } | undefined)?.errors;
    const first = errors ? Object.values(errors)[0]?.[0] : undefined;
    if (first) return first;
  }
  return failureMessage(e, 'The trace could not be loaded.');
}

/** Traceability → Backward or Forward Trace: the search is kept in the URL (`?q=`, `&lotId=` once an item is chosen). */
export function LotTraceScreen({ direction }: { direction: Direction }) {
  const me = useMe();
  const router = useRouter();
  const pathname = usePathname();
  const params = useSearchParams();
  const q = params?.get('q') ?? '';
  const lotId = params?.get('lotId') ?? '';
  const canView = (me.data?.permissions ?? []).includes('traceability.lot_trace.view');
  const copy = COPY[direction];

  const trace = useQuery({
    queryKey: ['traceability', 'lot-trace', direction, q, lotId],
    queryFn: async () =>
      (await call(() => api.GET('/traceability/lot-trace', { params: { query: { q, direction, ...(lotId ? { lotId } : {}) } } }))) as unknown as TraceAnswer,
    enabled: canView && q !== '',
    retry: false,
  });

  const go = (next: { q: string; lotId?: string }) => {
    const search = new URLSearchParams();
    if (next.q) search.set('q', next.q);
    if (next.lotId) search.set('lotId', next.lotId);
    router.replace(search.size ? `${pathname}?${search.toString()}` : (pathname ?? ''));
  };

  if (me.isPending) return <LoadingState title="Loading" />;
  if (!canView) {
    return (
      <div className="p-6 max-md:p-4">
        <PermissionDeniedState
          title="Lot trace is not available for your roles"
          description="Ask a tenant administrator for an administrator, inventory control or auditor role."
        />
      </div>
    );
  }

  const other: Direction = direction === 'backward' ? 'forward' : 'backward';
  const answer = trace.data?.data;

  return (
    <EnterprisePage
      title={copy.title}
      description={copy.description}
      actions={
        <Button
          onClick={() =>
            router.push(
              `/traceability/${other}-trace${q && !q.toUpperCase().startsWith('SHP-') ? `?${new URLSearchParams({ q, ...(lotId ? { lotId } : {}) })}` : ''}`,
            )
          }
        >
          {other === 'forward' ? <ArrowRightToLine aria-hidden="true" className="size-4" /> : <ArrowLeftToLine aria-hidden="true" className="size-4" />}
          {other === 'forward' ? 'Forward trace' : 'Backward trace'}
        </Button>
      }
    >
      <Card className="mb-4">
        <CardContent className="pt-5">
          <SearchForm key={q} initial={q} label={copy.hint} onTrace={(next) => go({ q: next })} />
        </CardContent>
      </Card>

      {q === '' ? (
        <EmptyState title="Nothing traced yet" description={copy.empty} />
      ) : trace.isPending ? (
        <LoadingState title={`Tracing ${q}`} />
      ) : trace.isError ? (
        <InlineAlert tone="danger" title={`${q} could not be traced`}>
          {refusal(trace.error)}
        </InlineAlert>
      ) : answer && 'matches' in answer ? (
        <Card>
          <CardHeader>
            <CardTitle>{`${q} is the number of several lots`}</CardTitle>
          </CardHeader>
          <CardContent>
            <p className="mt-0 mb-3 text-[13.5px] text-ink-2">Lot numbers count per item. Choose the item.</p>
            <ul className="m-0 flex list-none flex-col gap-2 p-0">
              {answer.matches.map((m) => (
                <li key={m.lotId}>
                  <Button onClick={() => go({ q, lotId: m.lotId })}>
                    <span className="font-mono">{m.lotNo}</span> {m.itemCode} · {m.itemName}
                  </Button>
                </li>
              ))}
            </ul>
          </CardContent>
        </Card>
      ) : answer ? (
        <TraceTree
          direction={direction}
          nodes={answer.nodes}
          truncated={answer.truncated}
          start={answer.start.kind === 'shipment' ? answer.start.docNo : `${answer.start.lotNo} ${answer.start.itemCode}`}
        />
      ) : null}
    </EnterprisePage>
  );
}

/** The search box; remounted (key) when the URL's search changes, so it shows what is traced. */
function SearchForm({ initial, label, onTrace }: { initial: string; label: string; onTrace: (q: string) => void }) {
  const [draft, setDraft] = React.useState(initial);
  return (
    <form
      role="search"
      className="flex items-end gap-3 max-md:flex-col max-md:items-stretch"
      onSubmit={(e) => {
        e.preventDefault();
        onTrace(draft.trim().toUpperCase());
      }}
    >
      <Field label={label} className="w-full max-w-md">
        {(control) => (
          <Input
            {...control}
            value={draft}
            maxLength={60}
            autoComplete="off"
            spellCheck={false}
            className="font-mono"
            onChange={(e) => setDraft(e.target.value)}
          />
        )}
      </Field>
      <Button type="submit" variant="primary" disabled={draft.trim() === ''}>
        <Search aria-hidden="true" className="size-4" />
        Trace
      </Button>
    </form>
  );
}

function TraceTree({ direction, nodes, truncated, start }: { direction: Direction; nodes: TraceNode[]; truncated: boolean; start: string }) {
  const lots = new Set(nodes.map((n) => n.lotId)).size;
  const shipments = new Set(nodes.flatMap((n) => n.shipments.map((s) => s.docNo))).size;
  return (
    <Card>
      <CardHeader>
        <CardTitle>{direction === 'backward' ? `What ${start} was made of` : `Where ${start} went`}</CardTitle>
      </CardHeader>
      <CardContent>
        <p className="mt-0 mb-3 text-[13px] text-ink-2 tabular-nums">
          {lots} {lots === 1 ? 'lot' : 'lots'}
          {direction === 'forward' ? ` · ${shipments} ${shipments === 1 ? 'shipment' : 'shipments'}` : ''}
        </p>
        {truncated ? (
          <InlineAlert tone="warning" title="The trace is cut" className="mb-3">
            It reaches more lots than one page shows. Trace one of the lots below on its own.
          </InlineAlert>
        ) : null}
        <ol aria-label={direction === 'backward' ? 'Backward trace' : 'Forward trace'} className="m-0 flex list-none flex-col divide-y divide-line p-0">
          {nodes.map((n) => (
            <TraceRow key={n.key} node={n} direction={direction} />
          ))}
        </ol>
      </CardContent>
    </Card>
  );
}

function TraceRow({ node: n, direction }: { node: TraceNode; direction: Direction }) {
  const via = n.via && !('outsideYourPlants' in n.via) ? n.via : null;
  return (
    <li className="py-3 first:pt-0" style={{ paddingLeft: `${Math.min(n.depth, 12) * 24}px` }}>
      <div className={n.depth > 0 ? 'border-l-2 border-line pl-3' : undefined}>
        <span className="sr-only">{`Level ${n.depth + 1}. `}</span>
        <div className="flex flex-wrap items-center gap-2">
          <span className="font-mono text-[14px] font-semibold text-ink">{n.lotNo}</span>
          <span className="text-[13.5px] text-ink">
            {n.itemCode} · {n.itemName}
          </span>
          <Badge tone={n.origin === 'RECEIPT' ? 'info' : 'neutral'}>{ORIGIN[n.origin]}</Badge>
          {via?.reversed ? <Badge tone="warning">Reversed posting</Badge> : null}
        </div>
        {n.restricted ? <p className="mt-1 mb-0 text-[13px] text-ink-2">Outside your plants — the trace does not continue here.</p> : null}
        {via ? (
          <p className="mt-1 mb-0 text-[13px] text-ink-2 tabular-nums">
            {direction === 'backward'
              ? `${via.qtyUsed} ${via.uomUsed} used for ${via.qtyMade} ${via.uomMade}`
              : `${via.qtyMade} ${via.uomMade} made with ${via.qtyUsed} ${via.uomUsed}`}{' '}
            · {DOC[via.docType] ?? via.docType} <span className="font-mono text-ink">{via.docNo ?? '—'}</span>
            {via.runNo ? (
              <>
                {' '}
                · run <span className="font-mono text-ink">{via.runNo}</span>
              </>
            ) : null}
            {via.workOrderNo ? (
              <>
                {' '}
                · <span className="font-mono text-ink">{via.workOrderNo}</span>
              </>
            ) : null}
            {via.postedByName ? ` · ${via.postedByName}` : ''}
            {via.postedAt ? `, ${when(via.postedAt)}` : ''}
          </p>
        ) : null}
        {n.receipt ? (
          <p className="mt-1 mb-0 text-[13px] text-ink-2">
            Received on <span className="font-mono text-ink">{n.receipt.docNo}</span>
            {n.receipt.supplierCode ? ` from ${n.receipt.supplierCode} · ${n.receipt.supplierName ?? ''}` : ''}
            {n.supplierLotNo ? (
              <>
                {' '}
                · supplier lot <span className="font-mono text-ink">{n.supplierLotNo}</span>
              </>
            ) : null}
            {n.receipt.postedByName ? ` · ${n.receipt.postedByName}` : ''}
            {n.receipt.postedAt ? `, ${when(n.receipt.postedAt)}` : ''}
          </p>
        ) : null}
        {n.shipments.length > 0 ? (
          <ul aria-label={`Shipments of ${n.lotNo}`} className="mt-2 mb-0 flex list-none flex-col gap-1 p-0">
            {n.shipments.map((s) => (
              <li key={`${s.docNo}|${s.soNo}|${s.lineNo}|${s.scheduleNo}`} className="flex flex-wrap items-center gap-2 text-[13px] text-ink-2 tabular-nums">
                <span>
                  Shipped <span className="font-mono text-ink">{s.docNo}</span> to {s.customerCode} · {s.customerName} · {s.soNo} {s.lineNo}/{s.scheduleNo}
                  {s.customerPoNo ? ` · customer PO ${s.customerPoNo}` : ''} · {s.qty} {s.uomCode}
                  {s.postedByName ? ` · ${s.postedByName}` : ''}
                  {s.postedAt ? `, ${when(s.postedAt)}` : ''}
                </span>
                {s.reversed ? <Badge tone="warning">Reversed</Badge> : null}
              </li>
            ))}
          </ul>
        ) : null}
      </div>
    </li>
  );
}
