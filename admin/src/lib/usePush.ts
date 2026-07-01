import { useEffect, useState } from 'react';
import { pushSupported, pushEnabled, enablePush, disablePush } from './push';

/**
 * Browser push state + toggle, usable from any shop screen. Enabling
 * registers a fresh subscription for the logged-in shop (so a reinstalled
 * PWA or a new browser gets re-subscribed).
 */
export function usePush() {
  const [supported] = useState(pushSupported());
  const [on, setOn] = useState(false);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    if (pushSupported()) void pushEnabled().then(setOn).catch(() => undefined);
  }, []);

  const toggle = async () => {
    setBusy(true);
    try {
      if (on) {
        await disablePush();
        setOn(false);
      } else {
        await enablePush();
        setOn(true);
      }
    } catch (e) {
      alert((e as Error)?.message || 'Could not update notifications.');
    } finally {
      setBusy(false);
    }
  };

  return { supported, on, busy, toggle };
}
