import * as React from 'react';

/** Title, one-line purpose and the task content in a white panel (EORA card radius 8, hierarchy shadow only). */
export function AuthCard({
  title,
  description,
  children,
  footer,
}: {
  title: string;
  description?: React.ReactNode;
  children: React.ReactNode;
  footer?: React.ReactNode;
}) {
  return (
    <section aria-labelledby="auth-title" className="flex flex-col gap-6">
      <div className="flex flex-col gap-2">
        <h1 id="auth-title" className="m-0 text-2xl font-semibold text-ink">
          {title}
        </h1>
        {description ? <p className="m-0 text-sm text-ink-2">{description}</p> : null}
      </div>
      <div className="rounded-lg border border-line bg-surface p-6 shadow-[var(--shadow-card)]">{children}</div>
      {footer ? <div className="text-sm text-ink-2">{footer}</div> : null}
    </section>
  );
}
