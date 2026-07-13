import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { EmptyState } from '@/components/EmptyState';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { useHuntCredits } from '@/hooks/useHuntCredits';
import {
  listLeads,
  saveLeads,
  searchLeads,
  startAdSearch,
  pollAdSearch,
  updateLeadStatus,
  waLink,
  telLink,
  leadDigits,
  isUaeMobile,
  InsufficientCreditsError,
} from '@/lib/leads';
import { LEAD_STATUSES } from '@/types';
import type { Lead, LeadFunnel, LeadResult, LeadStatus } from '@/types';

type Mode = 'find' | 'pipeline';

const STATUS_LABEL: Record<LeadStatus, string> = {
  new: 'New', sent: 'Sent', followup: 'Follow-up', replied: 'Replied', demo: 'Demo', won: 'Won', pass: 'Not Interested',
};

const EMPTY_FUNNEL: LeadFunnel = { new: 0, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 };

// Compact follow-up label for pipeline cards. `due` flips the styling to the
// amber "chase me" treatment for anything due today or overdue.
function followupLabel(iso?: string | null): { text: string; due: boolean } | null {
  if (!iso) return null;
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return null;
  const today = new Date(); today.setHours(0, 0, 0, 0);
  const target = new Date(d.getFullYear(), d.getMonth(), d.getDate());
  const days = Math.round((target.getTime() - today.getTime()) / 86_400_000);
  if (days < 0) return { text: `Overdue ${-days}d`, due: true };
  if (days === 0) return { text: 'Due today', due: true };
  if (days === 1) return { text: 'Due tomorrow', due: false };
  return { text: `Follow up in ${days}d`, due: false };
}

// Pipeline pagination — how many cards per page. "All" opts out of paging.
const PER_PAGE_OPTIONS = [5, 10, 15] as const;
type PerPage = number | 'all';

// Compact numbered-page window with ellipses, e.g. [1, …, 4, 5, 6, …, 23].
function pageWindow(current: number, count: number): (number | '…')[] {
  if (count <= 7) return Array.from({ length: count }, (_, i) => i + 1);
  const out: (number | '…')[] = [1];
  const lo = Math.max(2, current - 1);
  const hi = Math.min(count - 1, current + 1);
  if (lo > 2) out.push('…');
  for (let p = lo; p <= hi; p++) out.push(p);
  if (hi < count - 1) out.push('…');
  out.push(count);
  return out;
}

export default function Leads() {
  const navigate = useNavigate();
  const { shop } = useShop();
  const [mode, setMode] = useState<Mode>('find');
  const [funnel, setFunnel] = useState<LeadFunnel>(EMPTY_FUNNEL);
  const [wonValue, setWonValue] = useState(0);

  const funnelTotal = useMemo(
    () => Object.values(funnel).reduce((a, b) => a + b, 0),
    [funnel],
  );

  // Load the true funnel counts on mount so the Pipeline badge is accurate
  // right away (rather than 0 until the Pipeline tab is opened).
  useEffect(() => {
    if (!shop?.id) return;
    let alive = true;
    listLeads().then((r) => { if (alive) { setFunnel(r.funnel); setWonValue(r.won_value ?? 0); } }).catch(() => {});
    return () => { alive = false; };
  }, [shop?.id]);

  return (
    <div className="m-screen lf"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
      <div className="c-page-head">
        <h1 className="c-page-title">Business Hunt</h1>
        <p className="c-page-sub">Find real UAE businesses, then win them.</p>
      </div>

      <div className="lf-seg" role="tablist">
        <button role="tab" aria-selected={mode === 'find'} className={`lf-seg-btn${mode === 'find' ? ' on' : ''}`} onClick={() => setMode('find')}>
          Find
        </button>
        <button role="tab" aria-selected={mode === 'pipeline'} className={`lf-seg-btn${mode === 'pipeline' ? ' on' : ''}`} onClick={() => setMode('pipeline')}>
          Pipeline <span className="lf-seg-count">{funnelTotal}</span>
        </button>
        {wonValue > 0 && (
          <span className="lf-seg">
            Won <span className="lf-seg-count">AED {wonValue.toLocaleString('en-AE')}</span>
          </span>
        )}
      </div>

      {mode === 'find'
        ? <FindPane shopReady={!!shop?.id} onSaved={(delta) => setFunnel((f) => ({ ...f, new: f.new + delta }))} />
        : <PipelinePane shopReady={!!shop?.id} funnel={funnel} setFunnel={setFunnel} />}
    </div></div>
  );
}

// --- Find --------------------------------------------------------------------

// How many results to reveal per "Show more". The backend fetches + caches the
// full batch in one paid request; the user just pages through it 10 at a time.
const PAGE_SIZE = 10;

function FindPane({ shopReady, onSaved }: { shopReady: boolean; onSaved: (delta: number) => void }) {
  const navigate = useNavigate();
  const [category, setCategory] = useState('');
  const [results, setResults] = useState<LeadResult[] | null>(null);
  const [selected, setSelected] = useState<Record<string, boolean>>({});
  const [savedRefs, setSavedRefs] = useState<Record<string, boolean>>({});
  const [loading, setLoading] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  // Required name for the pipeline/list these results save into. Prefilled from
  // the searched keyword once results land; the user can rename before saving.
  const [pipeline, setPipeline] = useState('');
  // Set when a live search is refused for lack of credits (carries the balance).
  const [blocked, setBlocked] = useState<{ credits: number } | null>(null);
  const [meta, setMeta] = useState<{ from_cache: boolean; credits: number; searched_for?: string } | null>(null);
  // Hunt credit balance + purchase eligibility, for the balance chip. Actually
  // buying happens on the dedicated /leads/credits page (this hook is shared
  // with it, so the balance stays in sync after a purchase).
  const { balance, setBalance, canPurchase, buyMsg } = useHuntCredits(shopReady);
  // Background enrichment (the slow "advertising" source) runs after the fast
  // results land and quietly appends. `scanning` drives the subtle indicator;
  // `moreFound` is how many extra leads the last scan added.
  const [scanning, setScanning] = useState(false);
  const [moreFound, setMoreFound] = useState<number | null>(null);
  // We fetch + cache the full result set, but reveal it to the user PAGE_SIZE at
  // a time ("Show more"). Paging is purely client-side over already-fetched
  // data — it never triggers another Google/Apify call.
  const [visible, setVisible] = useState(PAGE_SIZE);
  const pollRef = useRef<number | null>(null);
  // Mirror of `results` so the async poll appends against the latest list
  // without capturing a stale closure.
  const resultsRef = useRef<LeadResult[] | null>(null);
  useEffect(() => { resultsRef.current = results; }, [results]);

  // Cancel any in-flight background scan when leaving the pane.
  useEffect(() => () => { if (pollRef.current) window.clearTimeout(pollRef.current); }, []);

  const selectedRefs = useMemo(() => Object.keys(selected).filter((k) => selected[k]), [selected]);

  // Refs that can still be picked (already-saved ones are locked).
  const selectableRefs = useMemo(
    () => (results ?? []).filter((r) => !savedRefs[r.external_ref]).map((r) => r.external_ref),
    [results, savedRefs],
  );
  const allSelected = selectableRefs.length > 0 && selectableRefs.every((ref) => selected[ref]);

  const toggleAll = () => {
    if (allSelected) {
      setSelected({});
    } else {
      const next: Record<string, boolean> = {};
      selectableRefs.forEach((ref) => { next[ref] = true; });
      setSelected(next);
    }
  };

  const runSearch = async () => {
    const q = category.trim();
    if (!q || !shopReady) return;
    setError(''); setBlocked(null); setResults(null); setSelected({}); setMeta(null);
    setScanning(false); setMoreFound(null); setVisible(PAGE_SIZE);
    resultsRef.current = null;
    if (pollRef.current) window.clearTimeout(pollRef.current);

    // 1. Fast source — real business listings, returned in ~1s.
    setLoading(true);
    let gotFast = false;
    let searchedFor = q; // the AI-interpreted keyword the search actually used
    try {
      const res = await searchLeads(q);
      setResults(res.data);
      setMeta({ from_cache: res.meta.from_cache, credits: res.meta.credits, searched_for: res.meta.searched_for });
      setBalance(res.meta.credits);
      searchedFor = res.meta.searched_for || q;
      // Suggest a pipeline name from the searched keyword, but never clobber a
      // name the user has already typed for this batch.
      setPipeline((prev) => (prev.trim() ? prev : `${searchedFor} pipeline`));
      gotFast = true;
    } catch (e) {
      if (e instanceof InsufficientCreditsError) { setBlocked({ credits: e.credits }); setBalance(e.credits); }
      else setError('Search failed. Please try again.');
    } finally {
      setLoading(false);
    }

    // 2. Slow source — quietly scan for businesses running ads and append them.
    // Uses the AI-interpreted keyword (not the raw text) so the ad scan targets
    // the same business type. Skipped if the fast search failed or hit the
    // allowance (never charges on its own; the fast search is the billable point).
    if (gotFast) void scanForMore(searchedFor);
  };

  // De-dupe key across sources: same phone (or, lacking one, same name) means
  // the same business — keep the first (fast-source) copy.
  const keyOf = (r: LeadResult) => leadDigits(r.phone) || r.name.trim().toLowerCase();

  const appendResults = (extra: LeadResult[]) => {
    if (!extra.length) return;
    const base = resultsRef.current ?? [];
    const seen = new Set(base.map(keyOf));
    const fresh = extra.filter((r) => {
      const k = keyOf(r);
      if (seen.has(k)) return false;
      seen.add(k);
      return true;
    });
    if (!fresh.length) return;
    // Put the (few) ad-sourced businesses at the FRONT so they land inside the
    // first page of 10, mixed in with Google — the user can't tell them apart.
    // Google's larger batch fills out the rest of every page.
    const merged = [...fresh, ...base];
    resultsRef.current = merged;
    setResults(merged);
    setMoreFound(fresh.length);
  };

  // Background enrichment via the slow "advertising" source. A repeat query is
  // served instantly from cache; otherwise it kicks off a scrape and polls
  // every 4s (~1-2 min). Failures are swallowed — this must never disrupt the
  // fast results already on screen.
  const scanForMore = async (q: string) => {
    setScanning(true);

    let started: Awaited<ReturnType<typeof startAdSearch>>;
    try {
      started = await startAdSearch(q);
    } catch {
      setScanning(false);
      return;
    }

    if (started.cached) {
      appendResults(started.data);
      setScanning(false);
      return;
    }

    const runId = started.runId;
    const tick = async () => {
      try {
        const res = await pollAdSearch(runId, q);
        if (res.status === 'running') {
          pollRef.current = window.setTimeout(tick, 4000);
          return; // keep scanning
        }
        if (res.status === 'done') appendResults(res.data);
      } catch {
        // swallow — background enrichment
      }
      setScanning(false);
    };
    void tick();
  };

  const toggle = (ref: string) => setSelected((s) => ({ ...s, [ref]: !s[ref] }));

  const saveSelected = async () => {
    if (!selectedRefs.length || !results) return;
    const listName = pipeline.trim();
    if (!listName) { setError('Enter a pipeline name to save these leads under.'); return; }
    setSaving(true); setError('');
    const picked = results.filter((r) => selected[r.external_ref] && !savedRefs[r.external_ref]);
    try {
      const res = await saveLeads(picked, listName);
      const marked: Record<string, boolean> = { ...savedRefs };
      picked.forEach((p) => { marked[p.external_ref] = true; });
      setSavedRefs(marked);
      setSelected({});
      onSaved(res.created); // only bump the funnel by rows actually created
    } catch {
      setError('Could not save the selected leads.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <>
      <div className="lf-panel lf-search">
        <div className="lf-search-inputs single">
          <div className="lf-field">
            <Icons.Search size={16} />
            <input
              placeholder="What & where? e.g. car wash in Dubai Marina"
              value={category}
              onChange={(e) => setCategory(e.target.value)}
              onKeyDown={(e) => { if (e.key === 'Enter') void runSearch(); }} />
          </div>
        </div>
        <button className="c-btn lf-search-btn" disabled={loading || !category.trim()} onClick={() => void runSearch()}>
          {loading ? 'Searching…' : <><Icons.Search size={16} /> Search</>}
        </button>
      </div>

      {/* Credit balance chip. Low (<10) turns amber as a gentle top-up nudge. */}
      {balance !== null && (
        <div className={`lf-meta lf-credits${balance <= 10 ? ' low' : ''}`}>
          <Icons.Search size={13} /> {balance.toLocaleString('en-AE')} Hunt {balance === 1 ? 'credit' : 'credits'} left
          {/* Both self-serve and low-balance shops go to the dedicated credits
              page, which handles buying (Ziina) or the WhatsApp fallback. */}
          {(canPurchase || balance <= 10) && (
            <button className="lf-topup-link" onClick={() => navigate('/leads/credits')}>
              {canPurchase ? 'Buy credits' : 'Top up'}
            </button>
          )}
        </div>
      )}

      {buyMsg && <div className="lf-meta lf-credits"><Icons.Check size={13} /> {buyMsg}</div>}

      {error && <div className="c-error-box">{error}</div>}

      {blocked && (
        <div className="lf-limit">
          <Icons.Bell size={16} />
          <div style={{ width: '100%' }}>
            <strong>{blocked.credits > 0 ? 'Top up Business Hunt credits' : 'Out of Business Hunt credits'}</strong>
            <span>
              Each new business search uses 1 credit. Repeat searches you've already run are always free.
            </span>
            <button className="c-btn-ghost lf-topup-btn" onClick={() => navigate('/leads/credits')}>
              <Icons.Search size={15} /> {canPurchase ? 'Buy credits' : 'Top up credits'}
            </button>
          </div>
        </div>
      )}

      {meta && !loading && (
        <div className="lf-meta">
          {meta.from_cache ? <><Icons.Check size={13} /> From cache — no credit used</> : <>Live search — 1 credit used</>}
        </div>
      )}

      {/* Non-clickable caption: what the AI actually searched, shown only when it
          differs from what the user typed (so clean keyword searches stay quiet). */}
      {meta?.searched_for && !loading && meta.searched_for.toLowerCase() !== category.trim().toLowerCase() && (
        <div className="lf-meta"><Icons.Search size={13} /> Showing: {meta.searched_for}</div>
      )}

      {!loading && (scanning || moreFound) && (
        <div className="lf-scanmore">
          {scanning
            ? <><span className="lf-scandot" /> Scanning for more businesses…</>
            : <><Icons.Check size={13} /> Added {moreFound} more</>}
        </div>
      )}

      {loading ? (
        <Spinner label="Searching businesses…" />
      ) : results && results.length > 0 ? (
        <>
          <div className="lf-listhead">
            <span>
              Showing {Math.min(visible, results.length)} of {results.length}
              {selectedRefs.length > 0 && ` · ${selectedRefs.length} selected`}
            </span>
            {selectableRefs.length > 0 && (
              <button className="lf-selall" onClick={toggleAll}>
                {allSelected ? 'Clear all' : 'Select all'}
              </button>
            )}
          </div>
          <div className="lf-list">
            {results.slice(0, visible).map((r) => (
              <ResultCard key={r.external_ref} r={r}
                selected={!!selected[r.external_ref]}
                saved={!!savedRefs[r.external_ref]}
                onToggle={() => toggle(r.external_ref)} />
            ))}
          </div>
          {results.length > visible && (
            <button className="lf-showmore" onClick={() => setVisible((v) => v + PAGE_SIZE)}>
              Show more ({results.length - visible} more)
            </button>
          )}
          {selectedRefs.length > 0 && (
            <div className="lf-savebar">
              <label className="lf-pipeline">
                <span className="lf-pipeline-label">Pipeline name</span>
                <div className="lf-field">
                  <Icons.List size={16} />
                  <input
                    placeholder="e.g. digital media pipeline"
                    value={pipeline}
                    onChange={(e) => setPipeline(e.target.value)}
                    onKeyDown={(e) => { if (e.key === 'Enter' && pipeline.trim()) void saveSelected(); }} />
                </div>
              </label>
              <button className="c-btn c-btn-block" disabled={saving || !pipeline.trim()} onClick={() => void saveSelected()}>
                <Icons.Plus size={16} /> {saving ? 'Saving…' : `Save ${selectedRefs.length} to pipeline`}
              </button>
            </div>
          )}
        </>
      ) : results && results.length === 0 && !scanning ? (
        <div className="lf-panel">
          <EmptyState title="No businesses found" subtitle="Try a broader category or a different area." />
        </div>
      ) : results && results.length === 0 ? null : !blocked && (
        <div className="lf-panel">
          <EmptyState icon={<Icons.Search size={26} />} title="Search to find businesses"
            subtitle="Enter a business type and area to discover real UAE businesses." />
        </div>
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
  const navigate = useNavigate();
  const [leads, setLeads] = useState<Lead[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [statusFilter, setStatusFilter] = useState<LeadStatus | null>(null);
  const [dueOnly, setDueOnly] = useState(false);
  const [search, setSearch] = useState('');
  const [pipelines, setPipelines] = useState<string[]>([]);
  const [pipelineFilter, setPipelineFilter] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<number | null>(null);
  const [perPage, setPerPage] = useState<PerPage>(15);
  const [page, setPage] = useState(1);
  const [view, setView] = useState<'cards' | 'list'>(
    () => (localStorage.getItem('lf_view') === 'list' ? 'list' : 'cards'),
  );
  useEffect(() => { localStorage.setItem('lf_view', view); }, [view]);

  // Client-side paging over the already-loaded pipeline.
  const total = leads.length;
  const pageSize = perPage === 'all' ? Math.max(total, 1) : perPage;
  const pageCount = Math.max(1, Math.ceil(total / pageSize));
  const safePage = Math.min(page, pageCount);
  const start = (safePage - 1) * pageSize;
  const pageLeads = perPage === 'all' ? leads : leads.slice(start, start + pageSize);

  // Snap back to the first page whenever the result set or page size changes.
  useEffect(() => { setPage(1); }, [perPage, statusFilter, dueOnly, search, pipelineFilter]);

  const fetch = useCallback(async () => {
    if (!shopReady) return;
    setLoading(true); setError('');
    try {
      const res = await listLeads({
        status: statusFilter ?? undefined,
        pipeline: pipelineFilter ?? undefined,
        search: search.trim() || undefined,
        followups: dueOnly ? 'due' : undefined,
      });
      setLeads(res.data);
      setFunnel(res.funnel);
      setPipelines(res.pipelines);
      // The active filter may have vanished (last lead in it re-filed/deleted).
      if (pipelineFilter && !res.pipelines.includes(pipelineFilter)) setPipelineFilter(null);
    } catch {
      setError('Could not load your leads.');
    } finally {
      setLoading(false);
    }
  }, [shopReady, statusFilter, pipelineFilter, dueOnly, search, setFunnel]);

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
          <button key={s} className={`lf-fchip s-${s}${statusFilter === s ? ' on' : ''}${funnel[s] === 0 ? ' zero' : ''}`}
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
        {pipelines.length > 0 && (
          <label className="lf-pipefilter">
            <Icons.List size={14} />
            <select value={pipelineFilter ?? ''} onChange={(e) => setPipelineFilter(e.target.value || null)} aria-label="Filter by pipeline">
              <option value="">All pipelines</option>
              {pipelines.map((p) => <option key={p} value={p}>{p}</option>)}
            </select>
          </label>
        )}
        <button className={`lf-toggle${dueOnly ? ' on' : ''}`} onClick={() => setDueOnly((v) => !v)}>
          <Icons.Bell size={14} /> Due today
        </button>
      </div>

      {error && <div className="c-error-box">{error}</div>}

      {loading ? (
        <Spinner label="Loading pipeline…" />
      ) : leads.length > 0 ? (
        <>
          <div className="lf-pager lf-pager-top">
            <span className="lf-pager-count">
              Showing <strong>{start + 1}–{Math.min(start + pageSize, total)}</strong> of {total}
            </span>
            <div className="lf-pager-tools">
              <div className="lf-viewtog" role="group" aria-label="View">
                <button className={`lf-viewbtn${view === 'cards' ? ' on' : ''}`} aria-pressed={view === 'cards'}
                  onClick={() => setView('cards')} aria-label="Card view" title="Cards"><Icons.Grid size={15} /></button>
                <button className={`lf-viewbtn${view === 'list' ? ' on' : ''}`} aria-pressed={view === 'list'}
                  onClick={() => setView('list')} aria-label="List view" title="List"><Icons.List size={15} /></button>
              </div>
              <label className="lf-perpage">
                <span>Per page</span>
                <select value={String(perPage)}
                  onChange={(e) => setPerPage(e.target.value === 'all' ? 'all' : Number(e.target.value))}>
                  {PER_PAGE_OPTIONS.map((n) => <option key={n} value={n}>{n}</option>)}
                  <option value="all">All</option>
                </select>
              </label>
            </div>
          </div>

          {view === 'cards' ? (
            <div className="lf-list">
              {pageLeads.map((l) => (
                <LeadCard key={l.id} lead={l} busy={busyId === l.id} onStatus={(s) => void changeStatus(l, s)}
                  onOpen={() => navigate(`/leads/${l.id}`)} />
              ))}
            </div>
          ) : (
            <div className="lf-table-wrap">
              <table className="lf-table">
                <thead>
                  <tr>
                    <th>Name</th>
                    <th>Status</th>
                    <th>Follow-up</th>
                    <th className="ta-r">Actions</th>
                  </tr>
                </thead>
                <tbody>
                  {pageLeads.map((l) => (
                    <LeadRow key={l.id} lead={l} busy={busyId === l.id} onStatus={(s) => void changeStatus(l, s)}
                      onOpen={() => navigate(`/leads/${l.id}`)} />
                  ))}
                </tbody>
              </table>
            </div>
          )}

          {pageCount > 1 && (
            <div className="lf-pager lf-pager-bottom">
              <button className="lf-page-btn" disabled={safePage <= 1} onClick={() => setPage(safePage - 1)}>
                <Icons.ChevronLeft size={15} /> Prev
              </button>
              <div className="lf-page-nums">
                {pageWindow(safePage, pageCount).map((p, i) =>
                  p === '…'
                    ? <span key={`e${i}`} className="lf-page-ellipsis">…</span>
                    : <button key={p} className={`lf-page-num${p === safePage ? ' on' : ''}`} onClick={() => setPage(p)}>{p}</button>,
                )}
              </div>
              <button className="lf-page-btn" disabled={safePage >= pageCount} onClick={() => setPage(safePage + 1)}>
                Next <Icons.Chevron size={15} />
              </button>
            </div>
          )}
        </>
      ) : (
        <div className="lf-panel">
          <EmptyState icon={<Icons.Grid size={26} />}
            title={statusFilter || dueOnly || search || pipelineFilter ? 'No matches' : 'Nothing saved yet'}
            subtitle={statusFilter || dueOnly || search || pipelineFilter ? 'Try clearing the filters.' : 'Use Find to search businesses and save them here.'} />
        </div>
      )}
    </>
  );
}

function LeadRow({ lead, busy, onStatus, onOpen }: { lead: Lead; busy: boolean; onStatus: (s: LeadStatus) => void; onOpen: () => void }) {
  const wa = lead.whatsapp_url && lead.is_mobile ? lead.whatsapp_url : null;
  const follow = followupLabel(lead.next_followup_at);
  return (
    <tr className={`lf-row lf-clickable s-${lead.status}`} onClick={onOpen}>
      <td className="lf-row-name">
        <span className="lf-name">{lead.name}</span>
        {lead.address && <span className="lf-addr"><Icons.MapPin size={11} /> {lead.address}</span>}
        {lead.pipeline && <span className="lf-pipe-tag"><Icons.List size={10} /> {lead.pipeline}</span>}
      </td>
      <td onClick={(e) => e.stopPropagation()}>
        <select className={`lf-select s-${lead.status}`} value={lead.status} disabled={busy}
          onChange={(e) => onStatus(e.target.value as LeadStatus)} aria-label="Change status">
          {LEAD_STATUSES.map((s) => <option key={s} value={s}>{STATUS_LABEL[s]}</option>)}
        </select>
      </td>
      <td>
        {follow
          ? <span className={`lf-follow${follow.due ? ' due' : ''}`}><Icons.Bell size={11} /> {follow.text}</span>
          : <span className="lf-dash">—</span>}
      </td>
      <td className="lf-row-actions" onClick={(e) => e.stopPropagation()}>
        <div className="lf-actions">
          {wa && <a className="lf-act wa" href={wa} target="_blank" rel="noreferrer"><Icons.WhatsApp size={14} /></a>}
          {lead.tel_url && <a className="lf-act" href={lead.tel_url}><Icons.Phone size={14} /></a>}
          {lead.map_url && <a className="lf-act" href={lead.map_url} target="_blank" rel="noreferrer"><Icons.MapPin size={14} /></a>}
          {lead.website && <a className="lf-act" href={lead.website} target="_blank" rel="noreferrer"><Icons.ArrowRight size={14} /></a>}
        </div>
      </td>
    </tr>
  );
}

function LeadCard({ lead, busy, onStatus, onOpen }: { lead: Lead; busy: boolean; onStatus: (s: LeadStatus) => void; onOpen: () => void }) {
  const wa = lead.whatsapp_url && lead.is_mobile ? lead.whatsapp_url : null;
  const follow = followupLabel(lead.next_followup_at);
  return (
    <div className={`lf-card lf-clickable s-${lead.status}`} onClick={onOpen}>
      <div className="lf-card-body">
        <div className="lf-card-top">
          <span className="lf-name">{lead.name}</span>
          <span className={`lf-status s-${lead.status}`}>{STATUS_LABEL[lead.status]}</span>
        </div>
        {lead.address && <span className="lf-addr"><Icons.MapPin size={12} /> {lead.address}</span>}
        {lead.pipeline && <span className="lf-pipe-tag"><Icons.List size={11} /> {lead.pipeline}</span>}
        {follow && (
          <span className={`lf-follow${follow.due ? ' due' : ''}`}>
            <Icons.Bell size={11} /> {follow.text}
          </span>
        )}
        <div className="lf-card-foot" onClick={(e) => e.stopPropagation()}>
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
