import { Outlet } from 'react-router-dom';
import { DesktopSidebar } from '@/layout/DesktopSidebar';

/**
 * Wraps every authenticated screen. At ≥1024px it lays out a persistent glass
 * sidebar beside the routed content (see desktop.css); below 1024px the sidebar
 * is hidden and this is a transparent passthrough, so the existing mobile/tablet
 * layout (single column + bottom tabs) renders exactly as before.
 */
export function AppShell() {
  return (
    <div className="app-shell">
      <DesktopSidebar />
      <div className="app-shell-main">
        <Outlet />
      </div>
    </div>
  );
}
