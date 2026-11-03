import { RouterProvider } from '@tanstack/react-router';

import { SessionGate } from '@/app/SessionGate';
import { router } from '@/app/router';

/**
 * Composition root: the session gate decides between boot / failure / login /
 * app, and the router owns everything that lives at `/app/...`.
 */
export function App() {
  return (
    <SessionGate>
      <RouterProvider router={router} />
    </SessionGate>
  );
}
