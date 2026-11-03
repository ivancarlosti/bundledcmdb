/**
 * Small presentational primitives.
 *
 * Native elements on purpose: `<select>`, `<input type="checkbox">` and
 * `<button>` already have the keyboard, RTL and screen-reader behaviour we need,
 * so the panel does not re-implement it (the accessibility bugs in the legacy
 * grid came from hand-rolled widgets).
 */

import type {
  ButtonHTMLAttributes,
  ComponentPropsWithRef,
  InputHTMLAttributes,
  ReactNode,
  SelectHTMLAttributes,
  TextareaHTMLAttributes,
} from 'react';

import { cn } from '@/lib/cn';

type Variant = 'primary' | 'secondary' | 'ghost' | 'danger';

const VARIANTS: Record<Variant, string> = {
  primary: 'bg-[var(--color-accent)] text-[var(--color-accent-contrast)] hover:opacity-90 border-transparent',
  secondary:
    'bg-[var(--color-surface)] text-[var(--color-text)] border-[var(--color-border)] hover:bg-[var(--color-surface-2)]',
  ghost: 'bg-transparent text-[var(--color-text)] border-transparent hover:bg-[var(--color-surface-2)]',
  danger: 'bg-[var(--color-danger)] text-white border-transparent hover:opacity-90',
};

export type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & { variant?: Variant };

export function Button({ variant = 'secondary', className, type = 'button', ...props }: ButtonProps) {
  return (
    <button
      type={type}
      className={cn(
        'inline-flex items-center justify-center gap-1.5 rounded-[var(--radius-md)] border px-3 py-1.5 text-sm font-medium transition-colors disabled:cursor-not-allowed disabled:opacity-50',
        VARIANTS[variant],
        className,
      )}
      {...props}
    />
  );
}

export function Input({ className, ...props }: ComponentPropsWithRef<'input'>) {
  return (
    <input
      className={cn(
        'h-9 w-full rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 text-sm text-[var(--color-text)] placeholder:text-[var(--color-muted)]',
        className,
      )}
      {...props}
    />
  );
}

export function Select({ className, children, ...props }: SelectHTMLAttributes<HTMLSelectElement>) {
  return (
    <select
      className={cn(
        'h-9 rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-2 text-sm text-[var(--color-text)]',
        className,
      )}
      {...props}
    >
      {children}
    </select>
  );
}

export type TextareaProps = TextareaHTMLAttributes<HTMLTextAreaElement>;

export function Textarea({ className, rows = 3, ...props }: TextareaProps) {
  return (
    <textarea
      rows={rows}
      className={cn(
        'w-full rounded-[var(--radius-md)] border border-[var(--color-border)] bg-[var(--color-surface)] px-3 py-2 text-sm text-[var(--color-text)] placeholder:text-[var(--color-muted)]',
        className,
      )}
      {...props}
    />
  );
}

export type CheckboxProps = InputHTMLAttributes<HTMLInputElement> & { label: ReactNode };

export function Checkbox({ label, className, ...props }: CheckboxProps) {
  return (
    <label className={cn('inline-flex cursor-pointer items-center gap-2 text-sm', className)}>
      <input type="checkbox" className="size-4 accent-[var(--color-accent)]" {...props} />
      <span>{label}</span>
    </label>
  );
}

export function Field({
  label,
  htmlFor,
  children,
  className,
}: {
  label: ReactNode;
  htmlFor?: string;
  children: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn('flex flex-col gap-1', className)}>
      <label htmlFor={htmlFor} className="text-xs font-medium text-[var(--color-muted)]">
        {label}
      </label>
      {children}
    </div>
  );
}

export function Spinner({ className }: { className?: string }) {
  return (
    <span
      role="status"
      aria-hidden="true"
      className={cn(
        'inline-block size-4 animate-spin rounded-full border-2 border-[var(--color-border)] border-t-[var(--color-accent)]',
        className,
      )}
    />
  );
}

export function Alert({
  tone = 'danger',
  title,
  children,
  action,
}: {
  tone?: 'danger' | 'warning' | 'info';
  title: ReactNode;
  children?: ReactNode;
  action?: ReactNode;
}) {
  const tones = {
    danger: 'border-[var(--color-danger)] text-[var(--color-danger)]',
    warning: 'border-[var(--color-warning)] text-[var(--color-warning)]',
    info: 'border-[var(--color-border)] text-[var(--color-text)]',
  } as const;

  return (
    <div role="alert" className={cn('card border p-4', tones[tone])}>
      <p className="m-0 font-semibold">{title}</p>
      {children === undefined ? null : <div className="mt-1 text-sm text-[var(--color-text)]">{children}</div>}
      {action === undefined ? null : <div className="mt-3">{action}</div>}
    </div>
  );
}
