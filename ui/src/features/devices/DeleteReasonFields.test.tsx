import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';

// The component tree talks through react-i18next; this import initialises the
// singleton the way `main.tsx` does. The assertions below use roles, not copy, so
// the test does not depend on which locale is active.
import '@/i18n';

import type { MetaPayload } from './api';
import { DeleteReasonFields, deleteReasonLabelKey, EMPTY_DELETE_REASON } from './DeleteReasonFields';

const REASONS = [
  { value: 'duplicated', label: 'Duplicated' },
  { value: 'created_by_mistake', label: 'Created by mistake' },
];

function renderFields(overrides: Partial<React.ComponentProps<typeof DeleteReasonFields>> = {}) {
  const onChange = vi.fn();
  // The duplicate picker searches through react-query, so a client is part of the
  // component's contract even when nothing is typed.
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } });

  render(
    <QueryClientProvider client={client}>
      <DeleteReasonFields reasons={REASONS} value={EMPTY_DELETE_REASON} onChange={onChange} {...overrides} />
    </QueryClientProvider>,
  );

  return { onChange };
}

describe('DeleteReasonFields', () => {
  it('reports the chosen reason', async () => {
    const { onChange } = renderFields();

    await userEvent.selectOptions(screen.getByRole('combobox'), 'duplicated');

    expect(onChange).toHaveBeenCalledWith({ reason: 'duplicated', duplicateOf: null });
  });

  it('drops a duplicate reference when the reason stops being "duplicated"', async () => {
    // Keeping it would send `duplicate_of` with a reason the API refuses.
    const { onChange } = renderFields({
      value: { reason: 'duplicated', duplicateOf: { id: 7, label: 'SN-7' } },
    });

    await userEvent.selectOptions(screen.getByRole('combobox'), 'created_by_mistake');

    expect(onChange).toHaveBeenCalledWith({ reason: 'created_by_mistake', duplicateOf: null });
  });

  it('offers the duplicate picker only for a single device marked as duplicated', () => {
    renderFields({ value: { reason: 'duplicated', duplicateOf: null }, deviceId: 3 });

    expect(screen.getByRole('searchbox')).toBeInTheDocument();
  });

  it('leaves the duplicate picker out of a bulk delete', () => {
    // A batch has no single kept asset, so the API accepts no reference either.
    renderFields({ value: { reason: 'duplicated', duplicateOf: null } });

    expect(screen.queryByRole('searchbox')).toBeNull();
  });

  it('shows the error it is given and marks the select invalid', () => {
    renderFields({ error: 'Choose a reason before deleting.' });

    expect(screen.getByRole('alert')).toHaveTextContent('Choose a reason before deleting.');
    expect(screen.getByRole('combobox')).toHaveAttribute('aria-invalid', 'true');
  });
});

describe('deleteReasonLabelKey', () => {
  // Same shape `lib/panel/meta.php` publishes; the mistake label is not derived
  // from the value, which is why the mapping is worth a test.
  const meta = {
    deleteReasons: [
      { value: 'duplicated', label: 'Duplicated', label_key: 'ui_delete_reason_duplicated' },
      { value: 'created_by_mistake', label: 'Created by mistake', label_key: 'ui_delete_reason_mistake' },
    ],
  } as unknown as MetaPayload;

  it('maps a stored value to its catalogue key', () => {
    expect(deleteReasonLabelKey(meta, 'duplicated')).toBe('ui_delete_reason_duplicated');
    expect(deleteReasonLabelKey(meta, 'created_by_mistake')).toBe('ui_delete_reason_mistake');
  });

  it('returns null for a row deleted before 0014 or without meta', () => {
    expect(deleteReasonLabelKey(meta, null)).toBeNull();
    expect(deleteReasonLabelKey(meta, '')).toBeNull();
    expect(deleteReasonLabelKey(undefined, 'duplicated')).toBeNull();
  });
});
