/*
 * Calendar-date helpers on `YYYY-MM-DD` strings. A calendar date has no time zone: all arithmetic runs on UTC midnight so
 * the browser's zone never shifts a day. Weeks are ISO (Monday–Sunday, week 1 holds the first Thursday; D-044).
 */

export type IsoDate = string;

const RE = /^(\d{4})-(\d{2})-(\d{2})$/;

export function isIsoDate(s: string | undefined | null): s is IsoDate {
  const m = s ? RE.exec(s) : null;
  if (!m) return false;
  const d = new Date(Date.UTC(+m[1]!, +m[2]! - 1, +m[3]!));
  return d.getUTCFullYear() === +m[1]! && d.getUTCMonth() === +m[2]! - 1 && d.getUTCDate() === +m[3]!;
}

export function toUtc(d: IsoDate): Date {
  const [y, m, day] = d.split('-').map(Number) as [number, number, number];
  return new Date(Date.UTC(y, m - 1, day));
}

export function fromUtc(d: Date): IsoDate {
  return d.toISOString().slice(0, 10);
}

export function addDays(d: IsoDate, n: number): IsoDate {
  const x = toUtc(d);
  x.setUTCDate(x.getUTCDate() + n);
  return fromUtc(x);
}

/** Same day `n` months later, clamped to the month's last day (31 Jan + 1 month = 28/29 Feb). */
export function addMonths(d: IsoDate, n: number): IsoDate {
  const x = toUtc(d);
  const day = x.getUTCDate();
  x.setUTCDate(1);
  x.setUTCMonth(x.getUTCMonth() + n);
  const last = new Date(Date.UTC(x.getUTCFullYear(), x.getUTCMonth() + 1, 0)).getUTCDate();
  x.setUTCDate(Math.min(day, last));
  return fromUtc(x);
}

export function startOfMonth(d: IsoDate): IsoDate {
  return `${d.slice(0, 7)}-01`;
}

/** ISO weekday: Monday 1 … Sunday 7. */
export function isoWeekday(d: IsoDate): number {
  return toUtc(d).getUTCDay() || 7;
}

export function startOfIsoWeek(d: IsoDate): IsoDate {
  return addDays(d, 1 - isoWeekday(d));
}

/** ISO 8601 week number (1–53). */
export function isoWeek(d: IsoDate): number {
  const thursday = toUtc(addDays(d, 4 - isoWeekday(d)));
  const yearStart = Date.UTC(thursday.getUTCFullYear(), 0, 1);
  return Math.floor((thursday.getTime() - yearStart) / 86_400_000 / 7) + 1;
}

/** Six ISO weeks (42 days) covering the month of `month`, Monday first. */
export function monthGrid(month: IsoDate): IsoDate[][] {
  const first = startOfIsoWeek(startOfMonth(month));
  return Array.from({ length: 6 }, (_, w) => Array.from({ length: 7 }, (_, i) => addDays(first, w * 7 + i)));
}

export function clampDate(d: IsoDate, min?: IsoDate, max?: IsoDate): IsoDate {
  if (min && d < min) return min;
  if (max && d > max) return max;
  return d;
}

/* Fixed English month abbreviations: ICU versions differ ("Sep" vs "Sept"); the UI shows one form everywhere. */
const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
const FULL = new Intl.DateTimeFormat('en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric', timeZone: 'UTC' });
const MONTH = new Intl.DateTimeFormat('en-GB', { month: 'long', year: 'numeric', timeZone: 'UTC' });

function dayMonth(d: IsoDate): string {
  return `${d.slice(8)} ${MONTHS[Number(d.slice(5, 7)) - 1]}`;
}

/** "09 Oct 2026" */
export function formatDate(d: IsoDate): string {
  return `${dayMonth(d)} ${d.slice(0, 4)}`;
}

/** "Friday 9 October 2026" — accessible names of day cells. */
export function formatDateLong(d: IsoDate): string {
  return FULL.format(toUtc(d));
}

export function formatMonth(d: IsoDate): string {
  return MONTH.format(toUtc(d));
}

/** "01 – 09 Oct 2026", "28 Sep – 09 Oct 2026", "from 01 Oct 2026", "until 09 Oct 2026" */
export function formatDateRange(from: IsoDate | '', to: IsoDate | ''): string {
  if (from && to) {
    if (from === to) return formatDate(from);
    if (from.slice(0, 7) === to.slice(0, 7)) return `${from.slice(8)} – ${formatDate(to)}`;
    if (from.slice(0, 4) === to.slice(0, 4)) return `${dayMonth(from)} – ${formatDate(to)}`;
    return `${formatDate(from)} – ${formatDate(to)}`;
  }
  if (from) return `from ${formatDate(from)}`;
  if (to) return `until ${formatDate(to)}`;
  return '';
}

/** Today's calendar date in an IANA zone (tenant zone, D-044) or the browser's when none is given. */
export function todayIn(timeZone?: string): IsoDate {
  return new Intl.DateTimeFormat('en-CA', { timeZone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date());
}
