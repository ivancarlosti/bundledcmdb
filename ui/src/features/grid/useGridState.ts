import { useCallback, useEffect, useMemo, useState } from 'react';

import { GRID_DEFAULTS, gridSearchString, parseGridSearch, type GridDefaults, type GridState } from './gridSearch';

/**
 * Bind the grid state to the address bar.
 *
 * `history.replaceState` is used on purpose: changing a filter must not push a
 * history entry per keystroke (the browser back button would become useless),
 * while `popstate` keeps back/forward working when the user navigates between
 * pages of the SPA.
 */
export function useGridState(defaults: GridDefaults = GRID_DEFAULTS) {
  const [state, setState] = useState<GridState>(() =>
    parseGridSearch(typeof window === 'undefined' ? '' : window.location.search, defaults),
  );

  useEffect(() => {
    const onPopState = () => setState(parseGridSearch(window.location.search, defaults));
    window.addEventListener('popstate', onPopState);
    return () => window.removeEventListener('popstate', onPopState);
  }, [defaults]);

  const update = useCallback(
    (next: GridState | ((current: GridState) => GridState)) => {
      setState((current) => {
        const resolved = typeof next === 'function' ? next(current) : next;
        const search = gridSearchString(resolved, defaults);
        window.history.replaceState(null, '', `${window.location.pathname}${search}`);
        return resolved;
      });
    },
    [defaults],
  );

  const reset = useCallback(() => {
    update(parseGridSearch('', defaults));
  }, [defaults, update]);

  const search = useMemo(() => gridSearchString(state, defaults), [state, defaults]);

  return { state, update, reset, search };
}
