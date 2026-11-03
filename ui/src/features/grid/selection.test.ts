import { describe, expect, it } from 'vitest';

import { selectionState, toggleAllOnPage, toggleSelection } from './selection';

describe('toggleSelection', () => {
  it('adds an id', () => {
    expect(toggleSelection([], 7, true)).toEqual([7]);
  });

  it('does not duplicate an id', () => {
    expect(toggleSelection([7], 7, true)).toEqual([7]);
  });

  it('removes an id', () => {
    expect(toggleSelection([7, 8], 7, false)).toEqual([8]);
  });

  it('keeps the selection order', () => {
    expect(toggleSelection([8], 7, true)).toEqual([8, 7]);
  });

  it('never mutates the input', () => {
    const selected = [7];
    toggleSelection(selected, 8, true);
    expect(selected).toEqual([7]);
  });
});

describe('toggleAllOnPage', () => {
  it('selects every row on the page', () => {
    expect(toggleAllOnPage([], [1, 2, 3], true)).toEqual([1, 2, 3]);
  });

  it('keeps ids selected on other pages', () => {
    expect(toggleAllOnPage([99], [1, 2], true)).toEqual([99, 1, 2]);
  });

  it('only clears the ids of the current page', () => {
    expect(toggleAllOnPage([99, 1, 2], [1, 2], false)).toEqual([99]);
  });

  it('is a no-op when the page is already selected', () => {
    expect(toggleAllOnPage([1, 2], [1, 2], true)).toEqual([1, 2]);
  });
});

describe('selectionState', () => {
  it('is none without a selection', () => {
    expect(selectionState([], [1, 2])).toBe('none');
  });

  it('is some when only part of the page is selected', () => {
    expect(selectionState([1], [1, 2])).toBe('some');
  });

  it('is all when the whole page is selected', () => {
    expect(selectionState([1, 2, 99], [1, 2])).toBe('all');
  });

  it('is none for an empty page', () => {
    expect(selectionState([1], [])).toBe('none');
  });
});
