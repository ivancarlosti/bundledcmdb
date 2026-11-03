import { apiRequest } from '@/api/client';

/**
 * Saved grid presets (`/views`, `lib/saved_views.php`).
 *
 * A view is the grid state the URL already carries plus the column layout, so
 * applying one is a normal URL update — no second source of truth for filters.
 */
export type SavedViewState = {
  q?: string;
  source?: string;
  status?: string;
  company?: string;
  sort?: string;
  dir?: 'asc' | 'desc';
  trashed?: boolean;
  order?: string[];
  visible?: string[];
};

export type SavedView = {
  id: number;
  name: string;
  state: SavedViewState;
  created_at: string | null;
  updated_at: string | null;
};

export function fetchViews(): Promise<SavedView[]> {
  return apiRequest<{ rows: SavedView[]; total: number }>('/views').then((data) => data.rows);
}

export function createView(name: string, state: SavedViewState): Promise<SavedView> {
  return apiRequest<SavedView>('/views', { method: 'POST', body: { name, state } });
}

export function updateView(id: number, patch: { name?: string; state?: SavedViewState }): Promise<SavedView> {
  return apiRequest<SavedView>(`/views/${id}`, { method: 'PATCH', body: patch });
}

export function deleteView(id: number): Promise<{ id: number; deleted: boolean }> {
  return apiRequest<{ id: number; deleted: boolean }>(`/views/${id}`, { method: 'DELETE' });
}
