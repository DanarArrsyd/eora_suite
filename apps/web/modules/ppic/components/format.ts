import type { Uom } from '../contracts';

/** kg 3 decimals; pcs / sheet / counts 0 decimals (EORA-COMPONENT-STANDARDS §5). */
export function fmtQty(value: number, uom: Uom | string): string {
  const d = uom === 'kg' ? 3 : 0;
  return value.toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d });
}
export function fmtSigned(value: number, uom: Uom | string): string {
  return `${value > 0 ? '+' : value < 0 ? '−' : ''}${fmtQty(Math.abs(value), uom)}`;
}
const dateFmt = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', timeZone: 'UTC' });
const dowFmt = new Intl.DateTimeFormat('en-GB', { weekday: 'short', day: '2-digit', month: 'short', timeZone: 'UTC' });
const timeFmt = new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit', timeZone: 'Asia/Jakarta' });
/** Plant-local ISO date → "13 Oct" (dates are already plant calendar dates). */
export const fmtDate = (iso: string) => dateFmt.format(new Date(`${iso}T00:00:00Z`));
export const fmtDay = (iso: string) => dowFmt.format(new Date(`${iso}T00:00:00Z`));
export const fmtDateTime = (iso: string) => `${timeFmt.format(new Date(iso))} WIB`;
