import { Navigate, Outlet, useLocation } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';
import { firstAccessiblePath, permAllowed, type Perm } from '@/lib/nav';

/**
 * Route gate: renders children only if the acting user holds `perm` (a single
 * name, or any-of a list). Without this, hiding a nav item only hides the link —
 * typing the URL still renders the page, and any endpoint behind it that isn't
 * itself permission-gated would answer.
 *
 * Denied users are sent to the first section they CAN see rather than to Home,
 * because Home itself is permission-gated (assistant.use) and would bounce them
 * again. Owner and untagged sessions pass — `can` returns true for them.
 */
export function RequirePerm({ perm }: { perm: Perm }) {
  const { shop, can } = useShop();
  const { pathname } = useLocation();

  if (permAllowed(perm, can)) return <Outlet />;

  // firstAccessiblePath falls back to /profile, which is itself gated — if the
  // user can't see that either, redirecting would loop. Show a dead end instead.
  const to = firstAccessiblePath(shop, can);
  if (to === pathname) {
    return (
      <div className="m-screen"><div className="m-scroll">
        <div className="c-page-head">
          <h1 className="c-page-title">No access</h1>
          <p className="c-page-sub">
            Your role doesn’t include any sections yet. Ask the business owner to grant you access.
          </p>
        </div>
      </div></div>
    );
  }

  return <Navigate to={to} replace />;
}
