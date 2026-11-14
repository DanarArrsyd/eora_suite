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

/** PPIC items on the live API (M4, doc 33 §1.1–1.2): Sales Orders (same screen as Demand → Sales Orders) and Work Orders. */
export const PPIC_LIVE = ['sales-orders', 'work-orders', 'wo-close-exceptions'] as const;
