'use client';

import * as React from 'react';
import { Toast as T } from 'radix-ui';
import { CircleAlert, CircleCheck, Info, TriangleAlert, X } from 'lucide-react';
import { cn } from '@eora/ui';

type Tone = 'info' | 'success' | 'warning' | 'danger';
export type FeedbackMessage = { tone?: Tone; title: string; description?: string };
type Item = FeedbackMessage & { id: number };

class FeedbackStore {
  private items: Item[] = [];
  private seq = 0;
  private listeners = new Set<() => void>();
  subscribe = (l: () => void) => {
    this.listeners.add(l);
    return () => {
      this.listeners.delete(l);
    };
  };
  snapshot = () => this.items;
  push(m: FeedbackMessage) {
    this.seq += 1;
    this.set([...this.items.slice(-2), { ...m, id: this.seq }]);
  }
  remove(id: number) {
    this.set(this.items.filter((x) => x.id !== id));
  }
  private set(items: Item[]) {
    this.items = items;
    this.listeners.forEach((l) => l());
  }
}
type Store = FeedbackStore;
const createStore = () => new FeedbackStore();

// The context value is a stable store: providers above the page's Suspense boundary must never change value during
// hydration, or React client-renders the streamed page segment (observed with Radix Toast.Provider wrapping children).
const Ctx = React.createContext<Store | null>(null);

const ICON: Record<Tone, React.ReactNode> = { info: <Info />, success: <CircleCheck />, warning: <TriangleAlert />, danger: <CircleAlert /> };
const BAR: Record<Tone, string> = { info: 'border-l-stat-info', success: 'border-l-stat-ok', warning: 'border-l-stat-warn', danger: 'border-l-stat-err' };
const ICO: Record<Tone, string> = { info: 'text-stat-info-fg', success: 'text-stat-ok-fg', warning: 'text-stat-warn-fg', danger: 'text-stat-err-fg' };

/**
 * Transient feedback (toasts) for completed background actions ("Export queued", "View saved").
 * Never the only record of a posting result — postings show a persistent result on the page / inbox.
 */
export function FeedbackProvider({ children }: { children: React.ReactNode }) {
  const [store] = React.useState(createStore);
  return (
    <Ctx.Provider value={store}>
      {children}
      <FeedbackViewport store={store} />
    </Ctx.Provider>
  );
}

const NO_ITEMS: Item[] = [];

/** Sibling of the page tree (not a wrapper), so its internal state never re-renders the page. */
function FeedbackViewport({ store }: { store: Store }) {
  const items = React.useSyncExternalStore(store.subscribe, store.snapshot, () => NO_ITEMS);
  return (
    <T.Provider swipeDirection="right" duration={6000} label="Notifications">
      {items.map((it) => {
        const tone = it.tone ?? 'info';
        return (
          <T.Root
            key={it.id}
            type={tone === 'danger' ? 'foreground' : 'background'}
            onOpenChange={(open) => {
              if (!open) store.remove(it.id);
            }}
            className={cn('flex items-start gap-3 rounded-lg border border-l-4 border-line bg-surface p-3 pr-2 shadow-[var(--shadow-popover)]', BAR[tone])}
          >
            <span aria-hidden="true" className={cn('mt-0.5 [&_svg]:size-4', ICO[tone])}>
              {ICON[tone]}
            </span>
            <div className="min-w-0 flex-1">
              <T.Title className="text-[13.5px] font-semibold">{it.title}</T.Title>
              {it.description ? <T.Description className="mt-0.5 text-[13px] text-ink-2">{it.description}</T.Description> : null}
            </div>
            <T.Close
              aria-label="Dismiss"
              className="grid size-8 place-items-center rounded-md text-ink-2 hover:bg-hover focus-visible:outline-2 focus-visible:outline-ring max-md:size-11"
            >
              <X aria-hidden="true" className="size-4" />
            </T.Close>
          </T.Root>
        );
      })}
      <T.Viewport className="fixed right-4 bottom-4 z-90 m-0 flex w-[380px] max-w-[calc(100vw-32px)] list-none flex-col gap-2 p-0 outline-none max-md:right-4 max-md:bottom-[calc(16px+env(safe-area-inset-bottom))]" />
    </T.Provider>
  );
}

export function useFeedback() {
  const store = React.useContext(Ctx);
  if (!store) throw new Error('useFeedback must be used inside <FeedbackProvider>');
  return React.useCallback((m: FeedbackMessage) => store.push(m), [store]);
}
