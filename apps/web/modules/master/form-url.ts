'use client';

import { usePathname, useRouter, useSearchParams } from 'next/navigation';

/**
 * A form page opened on a document page (D-140): its mode lives in the URL (`?form=<mode>` plus any extra keys), so it can
 * be reloaded or shared and the browser's Back closes it. `closeHref` is the document page without the form.
 */
export function useFormParam(extraKeys: string[] = []) {
  const router = useRouter();
  const pathname = usePathname() ?? '/';
  const params = useSearchParams();
  const keys = ['form', ...extraKeys];
  const without = () => {
    const next = new URLSearchParams(params?.toString() ?? '');
    for (const k of keys) next.delete(k);
    const qs = next.toString();
    return qs ? `${pathname}?${qs}` : pathname;
  };
  const closeHref = without();
  return {
    form: params?.get('form') ?? null,
    get: (key: string) => params?.get(key) ?? null,
    closeHref,
    open: (form: string, extra: Record<string, string> = {}) => {
      const next = new URLSearchParams(params?.toString() ?? '');
      for (const k of keys) next.delete(k);
      next.set('form', form);
      for (const [k, v] of Object.entries(extra)) next.set(k, v);
      router.push(`${pathname}?${next.toString()}`);
    },
    close: () => router.push(closeHref),
  };
}
