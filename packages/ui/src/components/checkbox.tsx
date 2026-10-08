import * as React from 'react';
import { cn } from '../lib/cn';

type CheckboxProps = Omit<React.ComponentPropsWithRef<'input'>, 'type'> & { indeterminate?: boolean };

/** EoraCheckbox — native checkbox (keyboard, forms, forced-colors for free); 18 px desktop, 24 px on phones. */
export function Checkbox({ className, indeterminate = false, ref, ...props }: CheckboxProps) {
  const inner = React.useRef<HTMLInputElement | null>(null);
  React.useEffect(() => {
    if (inner.current) inner.current.indeterminate = indeterminate;
  }, [indeterminate]);
  return (
    <input
      type="checkbox"
      ref={(el) => {
        inner.current = el;
        if (typeof ref === 'function') ref(el);
        else if (ref) ref.current = el;
      }}
      aria-checked={indeterminate ? 'mixed' : undefined}
      className={cn(
        'm-0 size-[18px] shrink-0 cursor-pointer accent-accent focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring max-md:size-6',
        className,
      )}
      {...props}
    />
  );
}
