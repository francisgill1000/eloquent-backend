import { useCallback, useEffect, useState } from 'react';
import { getLeadCredits, startPackCheckout } from '@/lib/leads';
import type { CreditPack } from '@/types';

/**
 * Business Hunt credit state + Ziina checkout, shared by the Hunt search page
 * (balance chip) and the dedicated credits page. Single source of truth so the
 * two never drift.
 *
 * `enabled` gates the initial load — pass the shop-ready flag so we don't fetch
 * before the shop context exists.
 */
export function useHuntCredits(enabled = true) {
  const [balance, setBalance] = useState<number | null>(null);
  const [packs, setPacks] = useState<CreditPack[]>([]);
  const [canPurchase, setCanPurchase] = useState(false);
  const [embeddedCheckout, setEmbeddedCheckout] = useState(false);
  // When embedded checkout is on, the Ziina iframe URL to show in the modal.
  const [checkoutUrl, setCheckoutUrl] = useState<string | null>(null);
  const [buyingId, setBuyingId] = useState<number | null>(null);
  const [buyMsg, setBuyMsg] = useState('');

  const refresh = useCallback(() => {
    getLeadCredits()
      .then((c) => {
        setBalance(c.credits); setPacks(c.packs);
        setCanPurchase(c.can_purchase); setEmbeddedCheckout(c.embedded_checkout);
      })
      .catch(() => undefined);
  }, []);

  // Initial load once the shop is ready.
  useEffect(() => {
    if (enabled) refresh();
  }, [enabled, refresh]);

  // Handle the return from Ziina's hosted page (?pay=success|cancel|failed).
  // The webhook grants the credits server-side; it can lag the redirect slightly,
  // so we re-fetch the balance now and again shortly after.
  useEffect(() => {
    const pay = new URLSearchParams(window.location.search).get('pay');
    if (!pay) return;
    if (pay === 'success') {
      setBuyMsg('Payment received — updating your balance…');
      refresh();
      const t = window.setTimeout(refresh, 3000);
      // Clean the URL so a refresh doesn't replay the message.
      window.history.replaceState({}, '', window.location.pathname);
      return () => window.clearTimeout(t);
    }
    setBuyMsg(pay === 'cancel' ? 'Checkout cancelled.' : 'Payment did not go through. Please try again.');
    window.history.replaceState({}, '', window.location.pathname);
  }, [refresh]);

  // Start checkout for a pack: inline Ziina iframe when embedded mode is on,
  // otherwise a full-page redirect to Ziina's hosted page.
  const buyPack = useCallback(async (pack: CreditPack) => {
    setBuyingId(pack.id); setBuyMsg('');
    try {
      const { redirect_url, embedded_url } = await startPackCheckout(pack.id);
      if (embeddedCheckout && embedded_url) {
        setCheckoutUrl(`${embedded_url}${embedded_url.includes('?') ? '&' : '?'}version=latest`);
        return;
      }
      if (redirect_url) { window.location.href = redirect_url; return; }
      setBuyMsg('Could not start checkout. Please try again.');
    } catch {
      setBuyMsg('Could not start checkout. Please try again.');
    } finally {
      setBuyingId(null);
    }
  }, [embeddedCheckout]);

  // Inline-checkout result: Ziina's iframe posts ZIINA_PAYMENT_STATUS_CHANGE.
  // Only trust messages from pay.ziina.com. The webhook is still the source of
  // truth for the grant; on COMPLETED we just close + refresh the balance.
  useEffect(() => {
    if (!checkoutUrl) return;
    const onMessage = (e: MessageEvent) => {
      if (e.origin !== 'https://pay.ziina.com') return;
      const data = (e.data ?? {}) as { type?: string; status?: string };
      if (data.type !== 'ZIINA_PAYMENT_STATUS_CHANGE') return;
      if (data.status === 'COMPLETED') {
        setCheckoutUrl(null);
        setBuyMsg('Payment received — updating your balance…');
        refresh();
        window.setTimeout(refresh, 3000);
      } else if (data.status === 'FAILED') {
        setCheckoutUrl(null);
        setBuyMsg('Payment did not go through. Please try again.');
      } else if (data.status === 'CANCELED') {
        setCheckoutUrl(null);
        setBuyMsg('Checkout cancelled.');
      }
    };
    window.addEventListener('message', onMessage);
    return () => window.removeEventListener('message', onMessage);
  }, [checkoutUrl, refresh]);

  return {
    balance, setBalance, packs, canPurchase,
    buyingId, buyMsg, buyPack,
    checkoutUrl, setCheckoutUrl, refresh,
  };
}
