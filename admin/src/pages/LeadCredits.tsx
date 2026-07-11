import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { useHuntCredits } from '@/hooks/useHuntCredits';
import type { CreditPack } from '@/types';

/** Support WhatsApp for shops that can't self-serve buy yet. */
const TOPUP_WA = '971557369629';
const AED = (fils: number) => `AED ${(fils / 100).toLocaleString('en-AE')}`;

// WhatsApp deep-link asking about a specific pack (non-self-serve shops).
const packWa = (p: CreditPack) =>
  `https://wa.me/${TOPUP_WA}?text=${encodeURIComponent(
    `Hi, I’d like the ${p.name} pack — ${p.credits} Hunt credits for ${AED(p.price_fils)}.`,
  )}`;

/**
 * Dedicated Business Hunt credits page (/leads/credits). Themed to match the
 * Hunt page; gated to the `leads` module via the router, so it never shows for
 * a bookings-only shop. Reached from the balance chip / "Buy credits" link on
 * the Hunt page — there is deliberately no sidebar entry.
 */
export default function LeadCredits() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const {
    balance, packs, canPurchase, buyingId, buyMsg, buyPack, checkoutUrl, setCheckoutUrl,
  } = useHuntCredits(!!shop?.id);

  return (
    <div className="m-screen lf lfc"><div className="m-scroll">
      {checkoutUrl && (
        <div className="lf-checkout-overlay" role="dialog" aria-label="Checkout">
          <div className="lf-checkout-modal">
            <div className="lf-checkout-head">
              <span>Buy Hunt credits</span>
              <button className="c-icon-btn lf-checkout-close" aria-label="Close checkout" onClick={() => setCheckoutUrl(null)}>✕</button>
            </div>
            <iframe id="ziina-checkout" title="Ziina checkout" src={checkoutUrl}
              allow="payment" className="lf-checkout-frame" />
          </div>
        </div>
      )}

      <button className="c-back" onClick={() => navigate('/leads')}><Icons.ChevronLeft size={16} /> Business Hunt</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Hunt Credits</h1>
        <p className="c-page-sub">Top up to keep hunting real UAE businesses.</p>
      </div>

      {/* Balance hero */}
      <div className="lfc-hero">
        <span className="lfc-hero-label"><Icons.Search size={14} /> Current balance</span>
        <span className="lfc-hero-value">
          <span className="lfc-hero-num">{balance === null ? '—' : balance.toLocaleString('en-AE')}</span>
          <span className="lfc-hero-unit">{balance === 1 ? 'credit' : 'credits'}</span>
        </span>
        <p className="lfc-hero-cap">1 credit = 1 new live business search.</p>
      </div>

      {buyMsg && <div className="lf-meta lf-credits"><Icons.Check size={13} /> {buyMsg}</div>}

      {/* How credits work */}
      <div className="lfc-how">
        <strong>How credits work</strong>
        <ul>
          <li>Each <em>new</em> live search spends 1 credit.</li>
          <li>Repeat searches you’ve already run are always free.</li>
          <li>Saving leads and working your pipeline never cost credits.</li>
        </ul>
      </div>

      {/* Packs */}
      <div className="lfc-packs-head">
        <strong>Top-up packs</strong>
        {canPurchase && <span className="lfc-secure"><Icons.Check size={13} /> Secure checkout via Ziina</span>}
      </div>

      {packs.length > 0 ? (
        <div className="lfc-packs">
          {packs.map((p) => (canPurchase
            ? <button key={p.id} className="lfc-pack" disabled={buyingId !== null} onClick={() => void buyPack(p)}>
                <span className="lfc-pack-credits">{p.credits.toLocaleString('en-AE')}</span>
                <span className="lfc-pack-unit">credits</span>
                <span className="lfc-pack-price">{buyingId === p.id ? 'Opening…' : AED(p.price_fils)}</span>
              </button>
            : <a key={p.id} className="lfc-pack" href={packWa(p)} target="_blank" rel="noreferrer">
                <span className="lfc-pack-credits">{p.credits.toLocaleString('en-AE')}</span>
                <span className="lfc-pack-unit">credits</span>
                <span className="lfc-pack-price">{AED(p.price_fils)}</span>
              </a>
          ))}
        </div>
      ) : (
        <p className="lf-meta">No packs available right now.</p>
      )}

      {!canPurchase && packs.length > 0 && (
        <a className="c-btn-ghost lfc-wa"
          href={`https://wa.me/${TOPUP_WA}?text=${encodeURIComponent('Hi, I’d like to top up my Business Hunt credits.')}`}
          target="_blank" rel="noreferrer">
          <Icons.WhatsApp size={15} /> Message us to top up
        </a>
      )}
    </div></div>
  );
}
