/**
 * React Query wiring for the Assets/Systems screens.
 *
 * `/meta` and `/users` are the device endpoints on purpose: the statuses and the
 * company directory are panel-wide, and sharing the cache key means opening the
 * Assets tab after Devices costs no extra request.
 */

import { keepPreviousData, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';

import { fetchCompanyUsers, fetchMeta, type CompanyUser, type MetaPayload } from '@/features/devices/api';

import {
  createInventoryItem,
  deleteInventoryItem,
  fetchInventory,
  fetchInventoryItem,
  updateInventoryItem,
  type InventoryKind,
  type InventoryRow,
  type InventoryWriteBody,
} from './api';
import { inventoryQuery, inventoryQueryKey, type InventoryState } from './inventoryState';

/** Same key the device grid uses, so the payload is fetched once per session. */
export const inventoryMetaQueryKey = ['meta'] as const;

export function useInventoryMeta() {
  return useQuery<MetaPayload>({
    queryKey: inventoryMetaQueryKey,
    queryFn: fetchMeta,
    staleTime: 5 * 60_000,
  });
}

/** Company roster behind the assignment picker (cached like the device form's). */
export function useUserDirectory(company: string) {
  return useQuery<CompanyUser[]>({
    queryKey: ['company-users', company],
    queryFn: () => fetchCompanyUsers(company),
    staleTime: 5 * 60_000,
  });
}

/**
 * One page of items. The query key is the request query string, so every
 * filter/sort/page combination is its own cache entry and `keepPreviousData` keeps
 * the previous page on screen while the next one loads.
 */
export function useInventoryList(kind: InventoryKind, state: InventoryState, company = '') {
  const query = inventoryQuery(state, company);
  return useQuery({
    queryKey: ['inventory', kind, inventoryQueryKey(state, company)],
    queryFn: () => fetchInventory(kind, query),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });
}

export function useInventoryItem(kind: InventoryKind, id: number | null) {
  return useQuery({
    queryKey: ['inventory-item', kind, id],
    queryFn: () => fetchInventoryItem(kind, id as number),
    enabled: id !== null && Number.isFinite(id),
    staleTime: 15_000,
  });
}

/** Create/update/delete for one kind, invalidating both the list and the item. */
export function useInventoryMutations(kind: InventoryKind) {
  const queryClient = useQueryClient();

  const invalidate = async () => {
    await queryClient.invalidateQueries({ queryKey: ['inventory', kind] });
    await queryClient.invalidateQueries({ queryKey: ['inventory-item', kind] });
  };

  const create = useMutation({
    mutationFn: (body: InventoryWriteBody) => createInventoryItem(kind, body),
    onSuccess: invalidate,
  });

  const update = useMutation({
    mutationFn: ({ id, body }: { id: number; body: InventoryWriteBody }) => updateInventoryItem(kind, id, body),
    onSuccess: invalidate,
  });

  const remove = useMutation({
    mutationFn: (id: number) => deleteInventoryItem(kind, id),
    onSuccess: invalidate,
  });

  return { create, update, remove };
}

export type { InventoryRow };
