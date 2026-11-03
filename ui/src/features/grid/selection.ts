/**
 * Row selection for the grid's bulk actions (`DataGrid` + `BulkActions`).
 *
 * Selection is a plain array of ids rather than a `Set`: it lives in React state
 * and every helper returns a new array, so a checkbox click re-renders once and
 * the reducer stays trivially testable. Order is insertion order, which keeps
 * the request payload stable and debuggable.
 */

export type SelectionState = 'none' | 'some' | 'all';

/** Toggle one row, without duplicating it when it is already selected. */
export function toggleSelection(selected: readonly number[], id: number, checked: boolean): number[] {
  if (!checked) {
    return selected.filter((current) => current !== id);
  }
  return selected.includes(id) ? [...selected] : [...selected, id];
}

/**
 * Toggle the page's checkbox.
 *
 * Checking adds every id on the page and leaves ids picked on other pages alone;
 * unchecking removes only the ids of the current page, so "select 3 on page 1,
 * select all on page 2" behaves the way people expect.
 */
export function toggleAllOnPage(
  selected: readonly number[],
  pageIds: readonly number[],
  checked: boolean,
): number[] {
  if (!checked) {
    return selected.filter((id) => !pageIds.includes(id));
  }
  const next = [...selected];
  for (const id of pageIds) {
    if (!next.includes(id)) {
      next.push(id);
    }
  }
  return next;
}

/** `none` / `some` (indeterminate) / `all` for the page's header checkbox. */
export function selectionState(selected: readonly number[], pageIds: readonly number[]): SelectionState {
  if (pageIds.length === 0) {
    return 'none';
  }
  const onPage = pageIds.filter((id) => selected.includes(id)).length;
  if (onPage === 0) {
    return 'none';
  }
  return onPage === pageIds.length ? 'all' : 'some';
}
