import { useEffect, useState } from 'react';

// Desktop breakpoint mirrors the CSS layer in styles/desktop.css (≥1024px).
const DESKTOP_QUERY = '(min-width: 1024px)';

// True when the viewport is desktop-width. Guards against environments without
// matchMedia (jsdom in tests, SSR) by defaulting to false — so tests and any
// non-browser render fall back to the mobile layout.
export function useIsDesktop(): boolean {
  const read = () =>
    typeof window !== 'undefined' && typeof window.matchMedia === 'function'
      ? window.matchMedia(DESKTOP_QUERY).matches
      : false;

  const [isDesktop, setIsDesktop] = useState<boolean>(read);

  useEffect(() => {
    if (typeof window === 'undefined' || typeof window.matchMedia !== 'function') return;
    const mql = window.matchMedia(DESKTOP_QUERY);
    const onChange = () => setIsDesktop(mql.matches);
    onChange();
    mql.addEventListener('change', onChange);
    return () => mql.removeEventListener('change', onChange);
  }, []);

  return isDesktop;
}
