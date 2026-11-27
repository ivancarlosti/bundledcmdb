/**
 * Route components of the Assets and Systems tabs.
 *
 * A barrel so `app/router.tsx` imports one module instead of six, and so the two
 * tabs stay symmetric: every screen exists twice, differing only in `kind`.
 */

export { AssetsPage, SystemsPage } from './InventoryGridPage';
export { AssetFormPage, SystemFormPage } from './InventoryFormPage';
export { AssetDetailPage, SystemDetailPage } from './InventoryDetailPage';
