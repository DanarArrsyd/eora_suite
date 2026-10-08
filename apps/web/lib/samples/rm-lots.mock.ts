import { SourceError, type ListQuery, type ListResult, type ListSource, type Option } from '@eora/features/data';
import type { SavedView } from '@eora/features/patterns';

/**
 * MOCK adapter for the Phase 2 enterprise list example (doc 22 §2).
 * SAMPLE data only — deterministic, never operational, never exported or persisted.
 * Simulates server-side filtering, sorting, paging, latency and failure modes.
 */
export type RmLot = {
  id: string;
  lot: string;
  item: string;
  material: string;
  materialType: 'coil' | 'sheet';
  grade: 'SPCC' | 'SPHC' | 'SGCC';
  plant: string;
  warehouse: string;
  location: string;
  uom: 'kg' | 'sheet';
  /** Disjoint warehouse buckets: available + allocated + hold + blocked = on-hand in warehouse. */
  available: number;
  allocated: number;
  hold: number;
  blocked: number;
  /** Material at Production (location type MAT_AT_PROD) — still material stock, shown separately. */
  atProduction: number;
  receivedAt: string; // ISO date
  heat: string;
  certificate: string;
};

export type SimulateMode = 'none' | 'error' | 'forbidden' | 'slow' | 'empty' | 'stale';

function rng(seed: number) {
  let s = seed;
  return () => {
    s = (s * 1103515245 + 12345) % 2147483648;
    return s / 2147483648;
  };
}

const GRADES = ['SPCC', 'SPHC', 'SGCC'] as const;
const WAREHOUSES: Record<string, string[]> = { PA: ['RM-WH1', 'RM-WH2'], PB: ['RM-WH3'] };

function build(): RmLot[] {
  const r = rng(42);
  const rows: RmLot[] = [];
  for (let i = 0; i < 214; i++) {
    const coil = r() < 0.62;
    const grade = GRADES[Math.floor(r() * 3)]!;
    const wh = r() < 0.7 ? 'RM-WH1' : 'RM-WH2';
    const uom = coil ? 'kg' : 'sheet';
    const roll = r();
    const qty = coil ? Math.round((800 + r() * 2600) * 1000) / 1000 : Math.round(20 + r() * 140);
    const lot: RmLot = {
      id: `lot-${i + 1}`,
      lot: `${coil ? 'C' : 'S'}-${String(i + 101).padStart(3, '0')}`,
      item: coil ? `RM-${grade}-${r() < 0.5 ? '12-120' : '16-150'}` : `RM-${grade}-20-SHT`,
      material: coil ? `${grade} ${r() < 0.5 ? '1.2 × 120' : '1.6 × 150'} coil` : `${grade} 2.0 × 1219 × 2438 sheet`,
      materialType: coil ? 'coil' : 'sheet',
      grade,
      plant: 'PA',
      warehouse: wh,
      location: `${wh}-${String.fromCharCode(65 + Math.floor(r() * 4))}${String(1 + Math.floor(r() * 9)).padStart(2, '0')}`,
      uom,
      available: 0,
      allocated: 0,
      hold: 0,
      blocked: 0,
      atProduction: 0,
      receivedAt: new Date(Date.UTC(2026, 8, 1) + Math.floor(r() * 37) * 86_400_000).toISOString().slice(0, 10),
      heat: `H-${77000 + Math.floor(r() * 9000)}`,
      certificate: r() < 0.1 ? 'Pending' : `MC-${54000 + Math.floor(r() * 4000)}`,
    };
    if (roll < 0.55) lot.available = qty;
    else if (roll < 0.68) {
      const part = uom === 'kg' ? Math.round(qty * 0.3 * 1000) / 1000 : Math.round(qty * 0.3);
      lot.available = Math.round((qty - part) * 1000) / 1000;
      lot.allocated = part;
    } else if (roll < 0.8) {
      lot.atProduction = qty;
      lot.location = `WC-PRESS${1 + Math.floor(r() * 3)}`;
    } else if (roll < 0.9) lot.hold = qty;
    else lot.blocked = qty;
    rows.push(lot);
  }
  return rows;
}

const DATA = build();

const VIEWS: Record<string, (l: RmLot) => boolean> = {
  all: () => true,
  unrestricted: (l) => l.available + l.allocated > 0,
  allocated: (l) => l.allocated > 0,
  'at-production': (l) => l.atProduction > 0,
  hold: (l) => l.hold > 0,
  blocked: (l) => l.blocked > 0,
};

const wait = (ms: number, signal?: AbortSignal) =>
  new Promise<void>((resolve, reject) => {
    const t = setTimeout(resolve, ms);
    signal?.addEventListener('abort', () => {
      clearTimeout(t);
      reject(new DOMException('Aborted', 'AbortError'));
    });
  });

function arr(v: string | string[] | undefined): string[] {
  return Array.isArray(v) ? v : v ? [v] : [];
}

function filtered(q: Pick<ListQuery, 'filters' | 'search'>): RmLot[] {
  const f = q.filters;
  const s = q.search.toLowerCase();
  const [from = '', to = ''] = (typeof f.received === 'string' ? f.received : '').split('..');
  return DATA.filter(
    (l) =>
      (!f.plant || l.plant === f.plant) &&
      (!f.warehouse || l.warehouse === f.warehouse) &&
      (!f.materialType || l.materialType === f.materialType) &&
      (arr(f.grade).length === 0 || arr(f.grade).includes(l.grade)) &&
      (!from || l.receivedAt >= from) &&
      (!to || l.receivedAt <= to) &&
      (!s || `${l.lot} ${l.material} ${l.heat} ${l.item}`.toLowerCase().includes(s)),
  );
}

const SORT: Record<string, (l: RmLot) => string | number> = {
  lot: (l) => l.lot,
  available: (l) => l.available,
  atProduction: (l) => l.atProduction,
  receivedAt: (l) => l.receivedAt,
};

export function createRmLotSource(mode: SimulateMode = 'none'): ListSource<RmLot> {
  return {
    resource: 'inventory.raw-material-lots',
    isMock: true,
    async list(q, signal): Promise<ListResult<RmLot>> {
      await wait(mode === 'slow' ? 2500 : 300, signal);
      if (mode === 'error') throw new SourceError('The server did not respond in time.', 'server', '7c1e-55a2');
      if (mode === 'forbidden') throw new SourceError('Missing permission inventory.lot.view.', 'forbidden', 'perm-3b10');
      let rows = mode === 'empty' ? [] : filtered(q).filter(VIEWS[q.view] ?? VIEWS.all!);
      const rule = q.sort[0];
      if (rule && SORT[rule.id]) {
        const key = SORT[rule.id]!;
        rows = [...rows].sort((a, b) => (key(a) > key(b) ? 1 : key(a) < key(b) ? -1 : 0) * (rule.desc ? -1 : 1));
      }
      const start = (q.page - 1) * q.pageSize;
      const asOf = mode === 'stale' ? new Date(Date.now() - 47 * 60_000) : new Date();
      return { rows: rows.slice(start, start + q.pageSize), meta: { total: rows.length, asOf: asOf.toISOString(), timezone: 'Asia/Jakarta' } };
    },
    async viewCounts(q, signal) {
      await wait(200, signal);
      const base = mode === 'empty' ? [] : filtered(q);
      return Object.fromEntries(Object.entries(VIEWS).map(([k, fn]) => [k, base.filter(fn).length]));
    },
  };
}

export const PLANT_OPTIONS: Option[] = [
  { value: 'PA', label: 'Plant A · Cikarang' },
  { value: 'PB', label: 'Plant B · Karawang' },
];

export async function loadPlants(): Promise<Option[]> {
  await wait(120);
  return PLANT_OPTIONS;
}

export async function loadWarehouses(deps: { plant?: string | string[] }): Promise<Option[]> {
  await wait(160);
  const plant = typeof deps.plant === 'string' ? deps.plant : 'PA';
  return (WAREHOUSES[plant] ?? []).map((w) => ({ value: w, label: w }));
}

export const SAMPLE_VIEWS: SavedView[] = [
  { id: 'v1', name: 'RM-WH1 default', scope: 'mine', filters: { warehouse: 'RM-WH1' } },
  { id: 'v2', name: 'Hold & blocked SPHC', scope: 'mine', filters: { grade: ['SPHC'] }, view: 'hold' },
  { id: 'v3', name: 'Coil received this month', scope: 'shared', sharedWith: 'PPIC', filters: { materialType: 'coil', received: '2026-10-01..' } },
];
