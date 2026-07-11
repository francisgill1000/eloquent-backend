import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import {
  getMasterPricing,
  updateMasterPricing,
  getCreditPacks,
  createCreditPack,
  updateCreditPack,
  deleteCreditPack,
} from '@/lib/masterPricing';
import type { CreditPack } from '@/types';

/**
 * Master-only page for the money side: the Lens subscription price every
 * business pays, plus the Business Hunt credit packs (a separate billing model).
 */
export default function MasterPricing() {
  const navigate = useNavigate();
  const [priceMonthly, setPriceMonthly] = useState('');
  const [priceAnnual, setPriceAnnual] = useState('');
  const [busy, setBusy] = useState(false);
  const [msg, setMsg] = useState('');

  useEffect(() => {
    getMasterPricing()
      .then((p) => { setPriceMonthly(String(p.monthly / 100)); setPriceAnnual(String(p.annual / 100)); })
      .catch(() => undefined);
  }, []);

  const save = async () => {
    setBusy(true); setMsg('');
    try {
      await updateMasterPricing({
        monthly_fils: Math.round(parseFloat(priceMonthly) * 100),
        annual_fils: Math.round(parseFloat(priceAnnual) * 100),
      });
      setMsg('Saved ✓');
    } catch {
      setMsg('Could not save prices.');
    } finally {
      setBusy(false);
    }
  };

  return (
    <div className="m-screen c-master"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12, paddingTop: 18 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">Pricing</h1>
          <p className="c-page-sub">Lens subscription + Business Hunt credit packs.</p>
        </div>
        <button className="c-icon-btn" aria-label="Back to businesses" onClick={() => navigate('/master')}>
          <Icons.ChevronLeft size={18} />
        </button>
      </div>

      <div className="c-master-card">
        <div className="c-msc-top" style={{ marginBottom: 10 }}>
          <span className="c-master-name">Subscription pricing</span>
          {msg && <em style={{ fontSize: 12, color: 'var(--text-3)' }}>{msg}</em>}
        </div>
        <div style={{ display: 'flex', gap: 12, flexWrap: 'wrap' }}>
          <div style={{ flex: 1, minWidth: 120 }}>
            <label className="c-field-label" htmlFor="price-monthly">Monthly (AED)</label>
            <div className="c-input-row">
              <input id="price-monthly" type="number" min="2" step="1" value={priceMonthly}
                onChange={(e) => { setPriceMonthly(e.target.value); setMsg(''); }} />
            </div>
          </div>
          <div style={{ flex: 1, minWidth: 120 }}>
            <label className="c-field-label" htmlFor="price-annual">Annual (AED)</label>
            <div className="c-input-row">
              <input id="price-annual" type="number" min="2" step="1" value={priceAnnual}
                onChange={(e) => { setPriceAnnual(e.target.value); setMsg(''); }} />
            </div>
          </div>
        </div>
        <button className="c-btn c-btn-block" style={{ marginTop: 12 }} disabled={busy} onClick={() => void save()}>
          {busy ? 'Saving…' : 'Save prices'}
        </button>
      </div>

      <CreditPacksCard />
    </div></div>
  );
}

/** Master-editable Business Hunt credit packs. Prices/credits change here with no
 *  deploy. Kept separate from the subscription price above — a distinct meter. */
function CreditPacksCard() {
  const [packs, setPacks] = useState<CreditPack[]>([]);
  const [msg, setMsg] = useState('');
  const [busyId, setBusyId] = useState<number | 'new' | null>(null);
  const [draft, setDraft] = useState({ name: '', credits: '', price: '' });

  useEffect(() => { void reload(); }, []);
  const reload = async () => {
    try { setPacks(await getCreditPacks()); } catch { setMsg('Could not load packs.'); }
  };

  const savePack = async (p: CreditPack, credits: string, priceAed: string) => {
    const c = Math.round(parseFloat(credits));
    const fils = Math.round(parseFloat(priceAed) * 100);
    if (!c || c < 1 || !fils || fils < 100) { setMsg('Credits ≥ 1 and price ≥ AED 1.'); return; }
    setBusyId(p.id); setMsg('');
    try {
      await updateCreditPack(p.id, { credits: c, price_fils: fils });
      await reload();
      setMsg('Saved ✓');
    } catch { setMsg('Could not save pack.'); } finally { setBusyId(null); }
  };

  const toggleActive = async (p: CreditPack) => {
    setBusyId(p.id); setMsg('');
    try { await updateCreditPack(p.id, { active: !(p.active ?? true) }); await reload(); }
    catch { setMsg('Could not update pack.'); } finally { setBusyId(null); }
  };

  const removePack = async (p: CreditPack) => {
    setBusyId(p.id); setMsg('');
    try { await deleteCreditPack(p.id); await reload(); }
    catch { setMsg('Could not delete pack.'); } finally { setBusyId(null); }
  };

  const addPack = async () => {
    const c = Math.round(parseFloat(draft.credits));
    const fils = Math.round(parseFloat(draft.price) * 100);
    if (!draft.name.trim() || !c || c < 1 || !fils || fils < 100) { setMsg('Fill name, credits ≥ 1, price ≥ AED 1.'); return; }
    setBusyId('new'); setMsg('');
    try {
      await createCreditPack({ name: draft.name.trim(), credits: c, price_fils: fils, sort: packs.length + 1 });
      setDraft({ name: '', credits: '', price: '' });
      await reload();
      setMsg('Added ✓');
    } catch { setMsg('Could not add pack.'); } finally { setBusyId(null); }
  };

  return (
    <div className="c-master-card" style={{ marginTop: 14 }}>
      <div className="c-msc-top" style={{ marginBottom: 4 }}>
        <span className="c-master-name">Business Hunt credit packs</span>
        {msg && <em style={{ fontSize: 12, color: 'var(--text-3)' }}>{msg}</em>}
      </div>
      <p className="c-page-sub" style={{ margin: '0 0 12px' }}>1 credit = 1 live business search. Sold pay-as-you-go.</p>

      {packs.map((p) => (
        <PackRow key={p.id} pack={p} busy={busyId === p.id}
          onSave={savePack} onToggle={toggleActive} onDelete={removePack} />
      ))}

      <div className="c-msd-section" style={{ borderTop: '1px solid var(--line, rgba(255,255,255,0.08))', marginTop: 10, paddingTop: 12 }}>
        <label className="c-field-label">Add a pack</label>
        <div style={{ display: 'flex', gap: 8, flexWrap: 'wrap', alignItems: 'flex-end' }}>
          <div style={{ flex: 2, minWidth: 120 }}>
            <div className="c-input-row"><input placeholder="Name (e.g. Starter)" value={draft.name}
              onChange={(e) => { setDraft({ ...draft, name: e.target.value }); setMsg(''); }} /></div>
          </div>
          <div style={{ flex: 1, minWidth: 90 }}>
            <div className="c-input-row"><input type="number" min="1" placeholder="Credits" value={draft.credits}
              onChange={(e) => { setDraft({ ...draft, credits: e.target.value }); setMsg(''); }} /></div>
          </div>
          <div style={{ flex: 1, minWidth: 90 }}>
            <div className="c-input-row"><input type="number" min="1" placeholder="AED" value={draft.price}
              onChange={(e) => { setDraft({ ...draft, price: e.target.value }); setMsg(''); }} /></div>
          </div>
        </div>
        <button className="c-btn c-btn-block" style={{ marginTop: 10 }} disabled={busyId === 'new'} onClick={() => void addPack()}>
          {busyId === 'new' ? 'Adding…' : 'Add pack'}
        </button>
      </div>
    </div>
  );
}

function PackRow({ pack, busy, onSave, onToggle, onDelete }: {
  pack: CreditPack;
  busy: boolean;
  onSave: (p: CreditPack, credits: string, priceAed: string) => void;
  onToggle: (p: CreditPack) => void;
  onDelete: (p: CreditPack) => void;
}) {
  const [credits, setCredits] = useState(String(pack.credits));
  const [price, setPrice] = useState(String(pack.price_fils / 100));
  const off = pack.active === false;

  return (
    <div style={{ display: 'flex', gap: 8, alignItems: 'flex-end', flexWrap: 'wrap', marginBottom: 10, opacity: off ? 0.55 : 1 }}>
      <div style={{ flex: 2, minWidth: 90 }}>
        <label className="c-field-label">{pack.name}</label>
        <div className="c-input-row"><input type="number" min="1" value={credits}
          onChange={(e) => setCredits(e.target.value)} /></div>
      </div>
      <div style={{ flex: 1, minWidth: 80 }}>
        <label className="c-field-label">AED</label>
        <div className="c-input-row"><input type="number" min="1" value={price}
          onChange={(e) => setPrice(e.target.value)} /></div>
      </div>
      <button className="c-btn" disabled={busy} onClick={() => onSave(pack, credits, price)}>Save</button>
      <button className="c-btn-ghost" disabled={busy} onClick={() => onToggle(pack)}>{off ? 'Enable' : 'Hide'}</button>
      <button className="c-icon-btn" aria-label="Delete pack" disabled={busy} onClick={() => onDelete(pack)}>
        <Icons.Trash size={15} />
      </button>
    </div>
  );
}
