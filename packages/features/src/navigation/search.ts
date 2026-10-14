import type { LucideIcon } from 'lucide-react';
import { itemHref, moduleHref } from './selectors';
import type { NavModule } from './types';

/*
 * Page search for the global search dialog: every module and menu item the user can open (already filtered by the
 * navigation permissions). Pure and deterministic — ranked, every query word must match, matched label parts returned
 * for highlighting. Record search (PO, WO, lots…) joins when the module APIs exist.
 */

export type PageEntry = {
  id: string;
  label: string;
  /** "PPIC / Planning › Production Planning" */
  path: string;
  href: string;
  icon: LucideIcon;
  kind: 'module' | 'page';
  /** Initials of the label words, e.g. "Master Production Schedule" → "mps". */
  acronym: string;
};

export type PageHit = PageEntry & { score: number; ranges: [number, number][] };

const STOP = new Set(['and', 'of', 'the', '&', '/', '·']);

function acronymOf(label: string): string {
  return label
    .split(/[\s/&·-]+/)
    .filter((w) => w && !STOP.has(w.toLowerCase()))
    .map((w) => w[0]!.toLowerCase())
    .join('');
}

export function buildPageIndex(modules: readonly NavModule[]): PageEntry[] {
  return modules.flatMap((m) => [
    { id: m.id, label: m.label, path: 'Module', href: moduleHref(m.id), icon: m.icon, kind: 'module' as const, acronym: acronymOf(m.label) },
    ...m.groups.flatMap((g) =>
      g.items.map((i) => ({
        id: `${m.id}/${i.id}`,
        label: i.label,
        path: `${m.label} › ${g.label}`,
        href: itemHref(m.id, i.id),
        icon: i.icon,
        kind: 'page' as const,
        acronym: acronymOf(i.label),
      })),
    ),
  ]);
}

function escape(word: string): string {
  return word.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

/** Score of one query word against an entry; 0 = no match. Label matches outrank path matches. */
function wordScore(e: PageEntry, word: string): { score: number; range?: [number, number] } {
  const label = e.label.toLowerCase();
  const at = label.indexOf(word);
  if (label === word) return { score: 100, range: [0, word.length] };
  if (at === 0) return { score: 80, range: [0, word.length] };
  const wordStart = label.search(new RegExp(`(^|[\\s/&·(-])${escape(word)}`));
  if (wordStart >= 0) {
    const start = wordStart === 0 ? 0 : wordStart + 1;
    return { score: 65, range: [start, start + word.length] };
  }
  if (word.length >= 2 && e.acronym.startsWith(word)) return { score: 60 };
  if (at > 0) return { score: 40, range: [at, at + word.length] };
  // module/group names only narrow a search ("quality inspection"); short fragments there are noise ("wo" → "Workspace")
  if (word.length >= 3 && new RegExp(`(^|[\\s/&·›-])${escape(word)}`).test(e.path.toLowerCase())) return { score: 15 };
  return { score: 0 };
}

export function searchPages(index: readonly PageEntry[], query: string, limit = 12): PageHit[] {
  const words = query.trim().toLowerCase().split(/\s+/).filter(Boolean);
  if (!words.length) return [];
  const hits: PageHit[] = [];
  for (const e of index) {
    let score = 0;
    const ranges: [number, number][] = [];
    let all = true;
    for (const w of words) {
      const r = wordScore(e, w);
      if (!r.score) {
        all = false;
        break;
      }
      score += r.score;
      if (r.range) ranges.push(r.range);
    }
    if (!all) continue;
    // pages before modules on equal score; shorter labels first (closer match)
    hits.push({ ...e, score: score + (e.kind === 'page' ? 1 : 0) - e.label.length / 100, ranges: mergeRanges(ranges) });
  }
  return hits.sort((a, b) => b.score - a.score || a.label.localeCompare(b.label)).slice(0, limit);
}

function mergeRanges(ranges: [number, number][]): [number, number][] {
  const sorted = [...ranges].sort((a, b) => a[0] - b[0]);
  const out: [number, number][] = [];
  for (const r of sorted) {
    const last = out[out.length - 1];
    if (last && r[0] <= last[1]) last[1] = Math.max(last[1], r[1]);
    else out.push([r[0], r[1]]);
  }
  return out;
}
