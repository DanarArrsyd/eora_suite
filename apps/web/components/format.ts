/**
 * One way to write numbers and dates on every screen (EORA-COMPONENT-STANDARDS §5, UI/UX audit 2026-11-19 U-7/U-8).
 * Quantities: thousands separator, kg with 3 decimals, other units with the decimals the value carries; the unit is the
 * stored UOM code. Dates: plant calendar days as "27 Nov 2026".
 */

/** Units shown with a fixed number of decimals; others keep the decimals of the value (the server trims them). */
const FIXED_DECIMALS: Record<string, number> = { KG: 3 };

function decimalsOf(value: string | number): number {
  const text = String(value);
  const dot = text.indexOf('.');
  return dot === -1 ? 0 : Math.min(6, text.length - dot - 1);
}

/** "1,000" / "44.500": the number only, for cells that render the unit separately. */
export function formatNumber(value: string | number, uom: string, decimals?: number): string {
  const n = Number(value);
  if (!Number.isFinite(n)) return String(value);
  const d = decimals ?? FIXED_DECIMALS[uom.toUpperCase()] ?? decimalsOf(value);
  return n.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}

/** "1,000 PCS" / "44.500 KG": a quantity in running text (facts, hints, messages, picker options). */
export function formatQty(value: string | number | null | undefined, uom: string, decimals?: number): string {
  if (value === null || value === undefined || value === '') return '—';
  return `${formatNumber(value, uom, decimals)} ${uom}`;
}

const DAY = new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'short', year: 'numeric', timeZone: 'UTC' });

/** Plant calendar date "2026-11-27" → "27 Nov 2026" (the date is already the plant's day: no timezone shift). */
export function formatDay(iso: string | null | undefined): string {
  if (!iso) return '—';
  const date = new Date(`${iso.slice(0, 10)}T00:00:00Z`);
  return Number.isNaN(date.getTime()) ? iso : DAY.format(date);
}

/** A moment (timestamp) in the viewer's time: "19 Nov 2026, 09:57". */
export function formatMoment(iso: string | null | undefined): string {
  if (!iso) return '';
  // Postgres text timestamps ("2026-11-19 02:57:20.170988+00") become ISO 8601 first
  const date = new Date(iso.replace(' ', 'T').replace(/([+-]\d\d)$/, '$1:00'));
  return Number.isNaN(date.getTime()) ? iso : date.toLocaleString('en-GB', { dateStyle: 'medium', timeStyle: 'short' });
}
