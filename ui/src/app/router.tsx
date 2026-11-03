import { createRootRoute, createRoute, createRouter, Link, Outlet } from '@tanstack/react-router';
import { useTranslation } from 'react-i18next';

import { Alert } from '@/components/ui';
import { DeviceDetailPage } from '@/features/devices/DeviceDetailPage';
import { DeviceFormPage } from '@/features/devices/DeviceFormPage';
import { DeviceGridPage } from '@/features/devices/DeviceGridPage';

import { AppShell } from './AppShell';

function RootLayout() {
  return (
    <AppShell>
      <Outlet />
    </AppShell>
  );
}

function NotFoundPage() {
  const { t } = useTranslation();
  return (
    <Alert
      tone="info"
      title={t('asset_not_found')}
      action={
        <Link to="/devices" className="text-sm text-[var(--color-accent)]">
          {t('back_to_list')}
        </Link>
      }
    />
  );
}

const rootRoute = createRootRoute({
  component: RootLayout,
  notFoundComponent: NotFoundPage,
});

// `/app/` and `/app/devices` show the same grid; keeping both means neither a
// bookmark of the mount point nor a deep link to /devices can 404.
const indexRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/',
  component: DeviceGridPage,
});

const devicesRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/devices',
  component: DeviceGridPage,
});

const deviceRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/devices/$deviceId',
  component: DeviceDetailPage,
});

// Static segments win over the `$deviceId` param in TanStack Router, so
// `/devices/new` is never read as a device id.
const newDeviceRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/devices/new',
  component: DeviceFormPage,
});

const editDeviceRoute = createRoute({
  getParentRoute: () => rootRoute,
  path: '/devices/$deviceId/edit',
  component: DeviceFormPage,
});

const routeTree = rootRoute.addChildren([
  indexRoute,
  devicesRoute,
  newDeviceRoute,
  editDeviceRoute,
  deviceRoute,
]);

/** `basepath` matches the nginx mount point, so links stay `/app/...`. */
export const router = createRouter({
  routeTree,
  basepath: '/app',
  defaultPreload: 'intent',
});
