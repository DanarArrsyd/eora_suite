/**
 * One page-wide guard for programmatic navigation (D-140): a form page with unsaved changes registers itself, and shell
 * actions that navigate in code (global search) ask it first. Links are guarded by the form page itself.
 */
type Guard = (href: string) => boolean;

let current: Guard | null = null;

/** Registers the guard; returns its release. The guard returns true when it takes the navigation over (e.g. asks first). */
export function setNavigationGuard(guard: Guard): () => void {
  current = guard;
  return () => {
    if (current === guard) current = null;
  };
}

/** Navigates through the guard: `go` runs unless a form page takes the navigation over. */
export function guardedNavigate(href: string, go: (href: string) => void): void {
  if (current?.(href)) return;
  go(href);
}
