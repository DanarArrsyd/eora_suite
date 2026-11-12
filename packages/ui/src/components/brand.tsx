import * as React from 'react';
import { cn } from '../lib/cn';

/**
 * The EORA brand mark "Stack" (design/brand, chosen by the owner 2026-11-12): three stacked plates — material,
 * production, finished product. `tone="inverse"` on the navy sidebar and sign-in panel, `"default"` on light surfaces.
 * Decorative unless `label` is given (then it is an image with that name).
 */
export function EoraMark({ tone = 'default', label, className }: { tone?: 'default' | 'inverse'; label?: string; className?: string }) {
  const inverse = tone === 'inverse';
  return (
    <svg
      viewBox="8 8 48 48"
      className={cn('shrink-0', className)}
      {...(label ? { role: 'img', 'aria-label': label } : { 'aria-hidden': true })}
      focusable="false"
    >
      {/* plates: material, production, finished product; the top plate's lit face is a light overlay */}
      <path d="M8 42 L32 30 L56 42 L32 54 Z" className={inverse ? 'fill-white/35' : 'fill-sidebar'} />
      <path d="M8 32 L32 20 L56 32 L32 44 Z" className={inverse ? 'fill-white/65' : 'fill-accent-hover'} />
      <path d="M8 22 L32 10 L56 22 L32 34 Z" className={inverse ? 'fill-white' : 'fill-accent'} />
      <path d="M32 10 L56 22 L32 34 Z" className={inverse ? 'fill-sidebar/10' : 'fill-white/20'} />
    </svg>
  );
}
