/**
 * Column layout (order + visibility) persisted in `user_preferences.grid_state`.
 *
 * The server normalises and stores the arrays (`user_preferences_grid_state()`
 * whitelists the ids), so the SPA only has to merge them with the columns
 * `/meta` currently publishes: a column added to the server appears at the end,
 * a column that disappeared is simply ignored. That is what keeps a stored
 * layout from breaking the grid after a schema change.
 */

export type ColumnLayout = {
  order?: string[];
  visible?: string[];
} | null;

type WithId = { id?: unknown };

/**
 * Apply the stored layout to the published columns.
 *
 * TanStack declares `ColumnDef.id` as optional, so ids are compared as strings.
 */
export function applyColumnLayout<T extends WithId>(columns: T[], layout: ColumnLayout): T[] {
  if (layout === null) {
    return columns;
  }

  const byId = new Map(columns.map((column) => [String(column.id), column]));
  const ordered: T[] = [];

  for (const id of layout.order ?? []) {
    const column = byId.get(id);
    if (column !== undefined) {
      ordered.push(column);
      byId.delete(id);
    }
  }
  for (const column of columns) {
    const id = String(column.id);
    if (byId.has(id)) {
      ordered.push(column);
      byId.delete(id);
    }
  }

  const visible = layout.visible ?? [];
  if (visible.length === 0) {
    return ordered;
  }
  const allowed = new Set(visible);
  return ordered.filter((column) => allowed.has(String(column.id)));
}

/**
 * The layout the grid should apply.
 *
 * A layout locked by an administrator (`/meta.gridLayout.locked`) is decided for
 * everyone: the stored per-user layout is ignored for order *and* visibility, so
 * the grid falls back to the column order `/meta` publishes.
 */
export function effectiveLayout(locked: boolean, layout: ColumnLayout): ColumnLayout {
  return locked ? null : layout;
}

/** Effective order: stored order, then any column the layout does not mention. */
export function resolveOrder(ids: string[], layout: ColumnLayout): string[] {
  const stored = layout?.order ?? [];
  const known = new Set(ids);
  const order = stored.filter((id) => known.has(id));
  for (const id of ids) {
    if (!order.includes(id)) {
      order.push(id);
    }
  }
  return order;
}

/** Effective visibility: an empty stored list means "everything". */
export function resolveVisible(ids: string[], layout: ColumnLayout): string[] {
  const stored = layout?.visible ?? [];
  if (stored.length === 0) {
    return ids;
  }
  const known = new Set(ids);
  const visible = stored.filter((id) => known.has(id));
  return visible.length === 0 ? ids : visible;
}

/** Move one column up (-1) or down (+1); out-of-range moves are no-ops. */
export function moveColumn(order: string[], id: string, delta: number): string[] {
  const from = order.indexOf(id);
  const to = from + delta;
  if (from === -1 || to < 0 || to >= order.length) {
    return order;
  }
  const next = [...order];
  const [moved] = next.splice(from, 1);
  next.splice(to, 0, moved);
  return next;
}

/**
 * Toggle visibility. The last visible column cannot be hidden: an empty grid
 * would look like a bug rather than a preference.
 */
export function toggleColumn(visible: string[], id: string): string[] {
  if (visible.includes(id)) {
    return visible.length <= 1 ? visible : visible.filter((item) => item !== id);
  }
  return [...visible, id];
}
