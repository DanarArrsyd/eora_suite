/** Server-safe list of implemented Phase 3 PPIC items (kept in sync with registry.tsx by a unit test). */
export const PPIC_IMPLEMENTED = [
  'control-tower',
  'demand-fulfillment',
  'daily-delivery',
  'shortage',
  'rm-availability',
  'conversion',
  'material-at-production',
  'wo-progress',
  'daily-results',
  'pending-quality',
  'daily-fg',
  'fg-availability',
  'material-variance',
] as const;
