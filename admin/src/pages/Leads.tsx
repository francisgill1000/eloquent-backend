import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import {
  listLeads,
  saveLeads,
  searchLeads,
  updateLeadStatus,
  waLink,
  telLink,
  isUaeMobile,
  SearchLimitError,
} from '@/lib/leads';
import { LEAD_STATUSES } from '@/types';
import type { Lead, LeadFunnel, LeadResult, LeadStatus } from '@/types';

type Mode = 'find' | 'pipeline';

const STATUS_LABEL: Record<LeadStatus, string> = {
  new: 'New', sent: 'Sent', replied: 'Replied', demo: 'Demo', won: 'Won', pass: 'Pass',
};

const EMPTY_FUNNEL: LeadFunnel = { new: 0, sent: 0, replied: 0, demo: 0, won: 0, pass: 0 };

export default function Leads() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const [mode, setMode] = useState<Mode>('find');
  const [funnel, setFunnel] = useState<LeadFunnel>(EMPTY_FUNNEL);

  const funnelTotal = useMemo(
    () => Object.values(funnel).reduce((a, b) => a + b, 0),
    [funnel],
  );

  return (
    <div className="m-screen lf"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Lead Finder</h1>
        <p className="c-page-sub">Find real UAE businesses, then work them to won.</p>
      </div>

      <div className="lf-seg" role="tablist">
        <button role="tab" aria-selected={mode === 'find'} className={`lf-seg-btn${mode === 'find' ? ' on' : ''}`} onClick={() => setMode('find')}>
          <Icons.Search size={15} /> Find
        </button>
        <button role="tab" aria-selected={mode === 'pipeline'} className={`lf-seg-btn${mode === 'pipeline' ? ' on' : ''}`} onClick={() => setMode('pipeline')}>
          <Icons.Grid size={15} /> Pipeline{funnelTotal > 0 && <span className="lf-seg-count">{funnelTotal}</span>}
        </button>
      </div>

      {mode === 'find'
        ? <FindPane shopReady={!!shop?.id} onSaved={(delta) => setFunnel((f) => ({ ...f, new: f.new + delta }))} />
        : <PipelinePane shopReady={!!shop?.id} funnel={funnel} setFunnel={setFunnel} />}
    </div></div>
  );
}

// --- Find --------------------------------------------------------------------

function FindPane({ shopReady, onSaved }: { shopReady: boolean; onSaved: (delta: number) => void }) {
  const [category, setCategory] = useState('');
  const [area, setArea] = useState('');
  const [results, setResults] = useState<LeadResult[] | null>(null);
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [savedRefs, setSavedRefs] = useState<Record<string, boolean>>({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [limit, setLimit] = useState<{ used: number; limit: number } | null>(null);
  const [meta, setMeta] = useState<{ from_cache: boolean; remaining: number } | null>(null);

  const selectedRefs = useMemo(() => Object.keys(selected).filter((k) => selected[k]), [selected]);

  const runSearch = async () => {
    if (!category.trim() || !shopReady) return;
    setLoading(true); setError(''); setLimit(null); setResults(null); setSelected({});
    try {
      const res = await searchLeads(category.trim(), area.trim() || undefined);
      setResults(res.data);
      setMeta({ from_cache: res.meta.from_cache, remaining: res.meta.remaining });
    } catch (e) {
      if (e instanceof SearchLimitError) {
        setLimit({ used: e.used, limit: e.limit });
      } else {
        setError('Search failed. Please try again.');
      }
    } finally {
      setLoading(false);
    }
  };

  const toggle = (ref: string) => setSelected((s) => ({ ...s, [ref]: !s[ref] }));

  const saveSelected = async () => {
    if (!selectedRefs.length || !results) return;
    setSaving(true); setError('');
    const picked = results.filter((r) => selected[r.external_ref] && !savedRefs[r.external_ref]);
    try {
      await saveLeads(picked);
      const marked: Record<string, boolean> = { ...savedRefs };
      picked.forEach((p) => { marked[p.external_ref] = true; });
      setSavedRefs(marked);
      setSelected({});
      onSaved(picked.length);
    } catch {
      setError('Could not save the selected leads.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <div className="lf-search">
        <div className="lf-search-inputs">
          <div className="lf-field">
            <Icons.Search size={16} />
            <input placeholder="What? e.g. salon, car wash" value={category}
              onChange={(e) => setCategory(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') void runSearch(); }} />
          </div>
          <div className="lf-field">
            <Icons.MapPin size={16} />
            <input placeholder="Where? e.g. Dubai Marina" value={area}
              onChange={(e) => setArea(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') void runSearch(); }} />
          </div>
        </div>
        <button className="c-btn lf-search-btn" disabled={loading || !category.trim()} onClick={() => void runSearch()}>
          {loading ? 'Searching…' : <><Icons.Search size={16} /> Search</>}
        </button>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {limit && (
        <div className="lf-limit">
          <Icons.Bell size={16} />
          <div>
            <strong>Monthly search limit reached</strong>
            <span>You've used {limit.used} of {limit.limit} searches this month. Repeat searches you've run before are always free.</span>
          </div>
        </div>
      )}

      {meta && !loading && (
        <div className="lf-meta">
          {meta.from_cache ? <><Icons.Check size={13} /> From cache — no search used</> : <>{meta.remaining} searches left this month</>}
        </div>
      )}

      {loading ? (
        <Spinner label="Searching businesses…" />
      ) : results && results.length > 0 ? (
        <>
          <div className="lf-list">
            {results.map((r) => (
              <ResultCard key={r.external_ref} r={r}
                selected={!!selected[r.external_ref]}
                saved={!!savedRefs[r.external_ref]}
                onToggle={() => toggle(r.external_ref)} />
            ))}
          </div>
          <div className="lf-savebar">
            <button className="c-btn c-btn-block" disabled={saving || !selectedRefs.length} onClick={() => void saveSelected()}>
              <Icons.Plus size={16} /> {saving ? 'Saving…' : `Save ${selectedRefs.length || ''} to pipeline`.trim()}
            </button>
          </div>
        </>
      ) : results && results.length === 0 ? (
        <EmptyState title="No businesses found" subtitle="Try a broader category or a different area." />
      ) : !limit && (
        <EmptyState icon={<Icons.Search size={26} />} title="Search to find leads"
          subtitle="Enter a business type and an area to discover real UAE businesses." />
      )}
    </>
  );
}

function ResultCard({ r, selected, saved, onToggle }: { r: LeadResult; selected: boolean; saved: boolean; onToggle: () => void }) {
  const wa = isUaeMobile(r.phone) ? waLink(r.phone) : null;
  const tel = telLink(r.phone);
  return (
    <div className={`lf-card${selected ? ' sel' : ''}${saved ? ' saved' : ''}`}>
      <button className="lf-check" aria-label={selected ? 'Deselect' : 'Select'} onClick={onToggle} disabled={saved}>
        {saved ? <Icons.Check size={15} /> : selected ? <Icons.Check size={15} /> : <span className="lf-check-empty" />}
      </button>
      <div className="lf-card-body">
        <div className="lf-card-top">
          <span className="lf-name">{r.name}</span>
          {typeof r.rating === 'number' && <span className="lf-rating"><Icons.Sparkle size={12} /> {r.rating.toFixed(1)}</span>}
        </div>
        {r.address && <span className="lf-addr"><Icons.MapPin size={12} /> {r.address}</span>}
        <div className="lf-actions">
          {wa && <a className="lf-act wa" href={wa} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()}><Icons.WhatsApp size={14} /></a>}
          {tel && <a className="lf-act" href={tel} onClick={(e) => e.stopPropagation()}><Icons.Phone size={14} /></a>}
          {r.website && <a className="lf-act" href={r.website} target="_blank" rel="noreferrer" onClick={(e) => e.stopPropagation()}><Icons.ArrowRight size={14} /></a>}
          {saved && <span className="lf-saved-tag">Saved</span>}
        </div>
      </div>
    </div>
  );
}

// --- Pipeline ----------------------------------------------------------------

function PipelinePane({ shopReady, funnel, setFunnel }: { shopReady: boolean; funnel: LeadFunnel; setFunnel: (f: LeadFunnel) => void }) {
  const [leads, setLeads] = useState<Lead[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [statusFilter, setStatusFilter] = useState<LeadStatus | null>(null);
  const [dueOnly, setDueOnly] = useState(false);
  const [search, setSearch] = useState('');
  const [busyId, setBusyId] = useState<number | null>(null);

  const fetch = useCallback(async () => {
    if (!shopReady) return;
    setLoading(true); setError('');
    try {
      const res = await listLeads({
        status: statusFilter ?? undefined,
        search: search.trim() || undefined,
        followups: dueOnly ? 'due' : undefined,
      });
      setLeads(res.data);
      setFunnel(res.funnel);
    } catch {
      setError('Could not load your leads.');
    } finally {
      setLoading(false);
    }
  }, [shopReady, statusFilter, dueOnly, search, setFunnel]);

  useEffect(() => { void fetch(); }, [fetch]);

  const changeStatus = async (lead: Lead, status: LeadStatus) => {
    if (status === lead.status) return;
    setBusyId(lead.id);
    try {
      const updated = await updateLeadStatus(lead.id, status);
      setLeads((prev) => prev.map((l) => (l.id === lead.id ? { ...l, ...updated } : l)));
      setFunnel({ ...funnel, [lead.status]: Math.max(0, funnel[lead.status] - 1), [status]: funnel[status] + 1 });
    } catch {
      setError('Could not update status.');
    } finally {
      setBusyId(null);
    }
  };

  return (
    <>
      <div className="lf-funnel">
        {LEAD_STATUSES.map((s) => (
          <button key={s} className={`lf-fchip s-${s}${statusFilter === s ? ' on' : ''}`}
            onClick={() => setStatusFilter((cur) => (cur === s ? null : s))}>
            <span className="lf-fchip-n">{funnel[s]}</span>
            <span className="lf-fchip-l">{STATUS_LABEL[s]}</span>
          </button>
        ))}
      </div>

      <div className="lf-filters">
        <div className="lf-field">
          <Icons.Search size={15} />
          <input placeholder="Search saved leads" value={search}
            onChange={(e) => setSearch(e.target.value)} />
        </div>
        <button className={`lf-toggle${dueOnly ? ' on' : ''}`} onClick={() => setDueOnly((v) => !v)}>
          <Icons.Bell size={14} /> Due today
        </button>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {loading ? (
        <Spinner label="Loading pipeline…" />
      ) : leads.length > 0 ? (
        <div className="lf-list">
          {leads.map((l) => (
            <LeadCard key={l.id} lead={l} busy={busyId === l.id} onStatus={(s) => void changeStatus(l, s)} />
          ))}
        </div>
      ) : (
        <EmptyState icon={<Icons.Grid size={26} />}
          title={statusFilter || dueOnly || search ? 'No leads match' : 'No leads yet'}
          subtitle={statusFilter || dueOnly || search ? 'Try clearing the filters.' : 'Use Find to search businesses and save them here.'} />
      )}
    </>
  );
}

function LeadCard({ lead, busy, onStatus }: { lead: Lead; busy: boolean; onStatus: (s: LeadStatus) => void }) {
  const wa = lead.whatsapp_url && lead.is_mobile ? lead.whatsapp_url : null;
  return (
    <div className={`lf-card s-${lead.status}`}>
      <div className="lf-card-body">
        <div className="lf-card-top">
          <span className="lf-name">{lead.name}</span>
          <span className={`lf-status s-${lead.status}`}>{STATUS_LABEL[lead.status]}</span>
        </div>
        {lead.address && <span className="lf-addr"><Icons.MapPin size={12} /> {lead.address}</span>}
        <div className="lf-card-foot">
          <div className="lf-actions">
            {wa && <a className="lf-act wa" href={wa} target="_blank" rel="noreferrer"><Icons.WhatsApp size={14} /></a>}
            {lead.tel_url && <a className="lf-act" href={lead.tel_url}><Icons.Phone size={14} /></a>}
            {lead.map_url && <a className="lf-act" href={lead.map_url} target="_blank" rel="noreferrer"><Icons.MapPin size={14} /></a>}
            {lead.website && <a className="lf-act" href={lead.website} target="_blank" rel="noreferrer"><Icons.ArrowRight size={14} /></a>}
          </div>
          <select className="lf-select" value={lead.status} disabled={busy}
            onChange={(e) => onStatus(e.target.value as LeadStatus)} aria-label="Change status">
            {LEAD_STATUSES.map((s) => <option key={s} value={s}>{STATUS_LABEL[s]}</option>)}
          </select>
        </div>
      </div>
    </div>
  );
}
