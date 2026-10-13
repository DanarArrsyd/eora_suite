import { EoraQueryProvider } from '@eora/features/data';

/**
 * Sign-in surfaces (doc 28 §1): outside the workspace shell. Navy brand panel on wide screens continues into the primary
 * sidebar after sign-in; phones get a navy header instead. One column of task content, left aligned.
 */
export default function AuthLayout({ children }: { children: React.ReactNode }) {
  return (
    <EoraQueryProvider>
      <div className="grid min-h-dvh bg-workspace lg:grid-cols-[minmax(360px,38%)_minmax(0,1fr)]">
        <aside className="hidden flex-col justify-between bg-sidebar px-12 py-10 text-on-accent lg:flex">
          <p className="m-0 text-[15px] font-semibold tracking-tight">EORA Suite</p>
          <div>
            <p className="m-0 text-[44px] leading-[1.1] font-semibold tracking-tight" aria-label="Plan, produce, trace, deliver.">
              <span className="block">Plan.</span>
              <span className="block">Produce.</span>
              <span className="block">Trace.</span>
              <span className="block">Deliver.</span>
            </p>
            <p className="mt-6 mb-0 max-w-[34ch] text-[15px] leading-6 text-sidebar-ink">
              Manufacturing operations for discrete production, from customer order to delivered goods.
            </p>
          </div>
          <p className="m-0 max-w-[40ch] text-[13px] leading-5 text-sidebar-ink">
            Access is given per company and plant. Your administrator sends the invitation.
          </p>
        </aside>
        <div className="flex min-w-0 flex-col">
          <header className="flex h-14 items-center bg-sidebar px-4 text-[15px] font-semibold text-on-accent lg:hidden">EORA Suite</header>
          <main id="main" className="flex flex-1 justify-center px-4 py-10 sm:items-center sm:py-16">
            <div className="w-full max-w-[400px]">{children}</div>
          </main>
        </div>
      </div>
    </EoraQueryProvider>
  );
}
