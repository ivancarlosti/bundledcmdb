import { keepPreviousData, useQuery } from '@tanstack/react-query';

import type { GridState } from '@/features/grid/gridSearch';
import { devicesPageQuery } from '@/features/grid/gridSearch';

import { fetchCompanyUsers, fetchDevice, fetchDeviceEvents, fetchDevices, fetchMeta } from './api';

export const metaQueryKey = ['meta'] as const;

/** Column definitions, statuses, sources and page sizes (changes rarely). */
export function useMeta() {
  return useQuery({
    queryKey: metaQueryKey,
    queryFn: fetchMeta,
    staleTime: 5 * 60_000,
  });
}

/**
 * One page of devices for the current grid state.
 *
 * The query key is the request query string, so sort/filter/page changes are
 * separate cache entries and `keepPreviousData` keeps the old rows visible while
 * the next page loads (no layout jump, no toolbar/table overlap).
 */
export function useDevices(state: GridState) {
  const query = devicesPageQuery(state);
  return useQuery({
    queryKey: ['devices', query],
    queryFn: () => fetchDevices(state),
    placeholderData: keepPreviousData,
    staleTime: 15_000,
  });
}

export function useDevice(id: number | null) {
  return useQuery({
    queryKey: ['device', id],
    queryFn: () => fetchDevice(id as number),
    enabled: id !== null && Number.isFinite(id),
    staleTime: 15_000,
  });
}

/** Audit trail of one device; refreshed with the device itself. */
export function useDeviceEvents(id: number | null) {
  return useQuery({
    queryKey: ['device-events', id],
    queryFn: () => fetchDeviceEvents(id as number),
    enabled: id !== null && Number.isFinite(id),
    staleTime: 15_000,
  });
}

/**
 * Directory behind the "user e-mail" dropdown.
 *
 * `company === ''` asks for the whole directory, which the server only honours
 * for an unbound (superadmin) account — a company-bound caller gets its own
 * company back. Five minutes of cache: a roster does not change per keystroke.
 */
export function useCompanyUsers(company: string) {
  return useQuery({
    queryKey: ['company-users', company],
    queryFn: () => fetchCompanyUsers(company),
    staleTime: 5 * 60_000,
  });
}
