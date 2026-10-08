/**
 * Typed design-token references for TypeScript consumers (charts, canvas, tests).
 * Values live in tokens.css; components should prefer Tailwind utilities mapped in the app theme.
 */
export const color = {
  primary: 'var(--color-primary)',
  primaryHover: 'var(--color-primary-hover)',
  textPrimary: 'var(--color-text-primary)',
  textSecondary: 'var(--color-text-secondary)',
  border: 'var(--color-border)',
  success: 'var(--color-success)',
  warning: 'var(--color-warning)',
  danger: 'var(--color-danger)',
  info: 'var(--color-info)',
} as const;

/** Breakpoints used by the shell (px). Mirrors the Tailwind theme in apps/web. */
export const breakpoint = {
  md: 768,
  lg: 1024,
  wide: 1200,
  xl: 1280,
  xxl: 1440,
  '3xl': 1600,
  '4xl': 1680,
} as const;

export type Tone = 'success' | 'warning' | 'danger' | 'info' | 'neutral';
