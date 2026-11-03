/**
 * Status pill.
 *
 * The palette is derived from the status value (no hard-coded colour per
 * translation), so a new status in `asset_status_values()` still renders
 * consistently.
 */

import { cn } from '@/lib/cn';

const TONES: Record<string, string> = {
  'in-use': 'bg-[color-mix(in_oklab,var(--color-success)_18%,transparent)] text-[var(--color-success)]',
  stock: 'bg-[color-mix(in_oklab,var(--color-accent)_18%,transparent)] text-[var(--color-accent)]',
  repair: 'bg-[color-mix(in_oklab,var(--color-warning)_22%,transparent)] text-[var(--color-warning)]',
  decomm: 'bg-[var(--color-surface-3)] text-[var(--color-muted)]',
  lost: 'bg-[color-mix(in_oklab,var(--color-danger)_18%,transparent)] text-[var(--color-danger)]',
};

/** Map a status label/value to a tone key (values come from the asset schema). */
export function statusTone(status: string): keyof typeof TONES {
  const normalized = status.toLowerCase();
  if (normalized.includes('repair')) {
    return 'repair';
  }
  if (normalized.includes('stock')) {
    return 'stock';
  }
  if (normalized.includes('decomm') || normalized.includes('retired')) {
    return 'decomm';
  }
  if (normalized.includes('lost') || normalized.includes('stolen')) {
    return 'lost';
  }
  return 'in-use';
}

export function StatusPill({ status, label }: { status: string; label: string }) {
  const tone = statusTone(status);
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap',
        TONES[tone],
      )}
      data-status={status}
    >
      {label}
    </span>
  );
}
