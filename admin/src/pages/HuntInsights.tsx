import { useCallback, useEffect, useMemo, useState } from 'react';
import { Link } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { ChartCard } from '@/components/charts/ChartCard';
import { Donut } from '@/components/charts/Donut';
import { HuntAiCard } from '@/components/charts/HuntAiCard';
import { Kpi, Delta } from '@/components/charts/Kpi';
import { RangeFilterCalendar } from '@/components/charts/RangeFilterCalendar';
import { TrendChart } from '@/components/charts/TrendChart';
import { useShop } from '@/context/ShopContext';
import {
  daysBetween, fmtNum, fmtShort, pctChange, presetRange, previousRange, type PresetKey,
} from '@/lib/dateRange';
import { getHuntInsights, type HuntInsights as Data } from '@/lib/huntInsights';
import type { LeadStatus } from '@/types';
import '@/styles/insights.css';
import '@/styles/hunt-insights.css';

/* Funnel stage colours, warm at the top of the pipeline to mint at the win. */
const STAGE_COLOR: Record<LeadStatus, string> = {
  new: 'var(--info)',
  sent: 'var(--info)',
  followup: 'var(--warn)',
  replied: 'var(--mint-300)',
  demo: 'var(--mint-300)',
  won: 'var(--mint-300)',
  pass: 'var(--neutral-soft)',
};

const STAGE_LABEL: Record<LeadStatus, string> = {
  new: 'New', sent: 'Contacted', followup: 'Following up', replied: 'Replied',
  demo: 'Demo', won: 'Won', pass: 'Passed',
};

const AED = (n: number) => `AED ${fmtNum(Math.round(n))}`;

/* ---------- needs attention ------------------------------------------------ */
function Attention({ a }: { a: Data['attention'] }) {
  const chips = [
    { key: 'overdue', n: a.followups_overdue, label: 'Overdue', to: '/leads?followups=overdue', cls: 'urgent' },
    { key: 'today', n: a.followups_today, label: 'Due today', to: '/leads?followups=today', cls: 'warn' },
    { key: 'stale', n: a.stale, label: 'Going cold', to: '/leads?stale=1', cls: 'warn' },
    // Always 0 for an agent — AssignedLeadScope makes unassigned leads
    // unreachable for them — so this simply never renders on their dashboard.
    { key: 'unassigned', n: a.unassigned, label: 'Unassigned', to: '/leads?assigned_to=unassigned', cls: '' },
  ].filter((c) => c.n > 0);

  if (chips.length === 0) {
    return <div className="ins-empty"><span className="ins-empty-txt">Nothing needs chasing right now.</span></div>;
  }

  return (
    <div className="hi-attention">
      {chips.map((c) => (
        <Link key={c.key} className={`hi-chip ${c.cls}`} to={c.to}>
          <span className="hi-chip-n">{fmtNum(c.n)}</span>
          <span>{c.label}</span>
        </Link>
      ))}
    </div>
  );
}

/* ---------- funnel --------------------------------------------------------- */
function Funnel({ pipeline }: { pipeline: Record<LeadStatus, number> }) {
  const order: LeadStatus[] = ['new', 'sent', 'followup', 'replied', 'demo', 'won', 'pass'];
  const max = Math.max(1, ...order.map((s) => pipeline[s]));
  const total = order.reduce((sum, s) => sum + pipeline[s], 0);

  if (total === 0) {
    return <div className="ins-empty"><span className="ins-empty-txt">No leads saved yet.</span></div>;
  }

  return (
    <div className="hi-funnel">
      {order.map((s) => (
        <div key={s} className="hi-fn-row">
          <div className="hi-fn-head">
            <span className="hi-fn-lab">{STAGE_LABEL[s]}</span>
            <span className="hi-fn-val">{fmtNum(pipeline[s])}</span>
          </div>
          <div className="hi-fn-track">
            <div className="hi-fn-fill" style={{ width: `${(pipeline[s] / max) * 100}%`, background: STAGE_COLOR[s] }} />
          </div>
        </div>
      ))}
    </div>
  );
}

/* ---------- leaderboard ---------------------------------------------------- */
function Leaderboard({ rows }: { rows: Data['agents'] }) {
  return (
    <div className="hi-board-scroll">
      <table className="hi-board">
        <thead>
          <tr><th>Agent</th><th>Leads</th><th>Won</th><th>Value</th></tr>
        </thead>
        <tbody>
          {rows.map((r) => (
            <tr key={r.id}>
              <td className="hi-board-name">{r.name}</td>
              <td>{fmtNum(r.leads)}</td>
              <td>{fmtNum(r.won)}</td>
              <td>{AED(r.won_value)}</td>
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

/* ---------- skeleton ------------------------------------------------------- */
function Skeleton() {
  return (
    <>
      <div className="ins-kpis">{[0, 1, 2, 3].map((i) => <div key={i} className="ins-skel" style={{ height: 92 }} />)}</div>
      <div className="ins-skel" style={{ height: 240 }} />
      <div className="ins-grid">
        <div className="ins-skel" style={{ height: 200 }} />
        <div className="ins-skel" style={{ height: 200 }} />
      </div>
    </>
  );
}

/* ---------- page ----------------------------------------------------------- */
export default function HuntInsights() {
  const { shop } = useShop();
  const today = useMemo(() => new Date(), []);

  const [preset, setPreset] = useState<PresetKey>('30d');
  const initial = useMemo(() => presetRange('30d', today), [today]);
  const [from, setFrom] = useState(initial.from);
  const [to, setTo] = useState(initial.to);

  const [data, setData] = useState<Data | null>(null);
  const [prev, setPrev] = useState<Data | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');

  const choosePreset = (key: Exclude<PresetKey, 'custom'>) => {
    const r = presetRange(key, today);
    setPreset(key); setFrom(r.from); setTo(r.to);
  };

  // Normalised, so an inverted custom range still behaves.
  const nf = from <= to ? from : to;
  const nt = from <= to ? to : from;

  const fetchData = useCallback(async () => {
    if (!shop?.id) return;
    setLoading(true); setError('');
    const p = previousRange(nf, nt);
    try {
      const [cur, previous] = await Promise.allSettled([
        getHuntInsights(nf, nt),
        getHuntInsights(p.from, p.to),
      ]);
      if (cur.status === 'rejected') throw cur.reason;
      setData(cur.value);
      setPrev(previous.status === 'fulfilled' ? previous.value : null);
    } catch {
      setError('Could not load your overview.');
      setData(null); setPrev(null);
    } finally {
      setLoading(false);
    }
  }, [shop?.id, nf, nt]);

  useEffect(() => { void fetchData(); }, [fetchData]);

  const rangeLen = daysBetween(nf, nt);

  /** Delta node for a plain count/amount KPI. */
  const delta = (cur: number, prior: number | undefined) => {
    const change = prior === undefined ? null : pctChange(cur, prior);
    return <Delta change={change} display={change === null ? '' : `${Math.abs(Math.round(change))}%`} goodDir="up" />;
  };

  const s = data?.summary;
  const ps = prev?.summary;

  // The one honest ratio available: of leads that actually reached a decision,
  // how many went our way. A period-wins ÷ period-leads figure would be
  // nonsense, since most wins come from leads created before the period.
  const decided = s ? s.pipeline.won + s.pipeline.pass : 0;
  const winRate = s && decided > 0 ? Math.round((s.pipeline.won / decided) * 100) : null;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Overview</h1>
        <p className="c-page-sub">Your pipeline, your wins and what needs chasing.</p>
      </div>

      <div className="ins-wrap">
        <RangeFilterCalendar
          preset={preset} from={from} to={to}
          onPreset={choosePreset}
          onCustom={(f, t) => { setFrom(f); setTo(t); setPreset('custom'); }}
        />

        {error && <div className="c-error-box">{error}</div>}

        {loading ? <Skeleton /> : data && s ? (
          <>
            <div className="ins-kpis">
              <Kpi label="New leads" value={fmtNum(s.new_leads)} delta={delta(s.new_leads, ps?.new_leads)} />
              <Kpi label="Deals won" value={fmtNum(s.won)} delta={delta(s.won, ps?.won)} />
              <Kpi label="Won value" value={AED(s.won_value)} delta={delta(s.won_value, ps?.won_value)} />
              <Kpi label="MRR won" value={AED(s.mrr_won)} delta={delta(s.mrr_won, ps?.mrr_won)} />
            </div>

            <ChartCard icon="Bell" title="Needs attention" sub="Right now — not affected by the date range">
              <Attention a={data.attention} />
            </ChartCard>

            <HuntAiCard shopId={shop!.id} from={nf} to={nt}
              rangeLabel={`${fmtShort(nf)} – ${fmtShort(nt)}`} />

            <ChartCard icon="Chart" title="Leads & wins over time"
              sub={rangeLen > 62 ? 'Weekly totals' : 'Daily totals'} span2>
              <TrendChart
                emptyText="No lead activity in this range yet."
                series={[
                  { key: 'leads', label: 'new leads', color: 'var(--info)', points: data.daily.map((d) => ({ date: d.date, value: d.leads })) },
                  { key: 'won', label: 'deals won', color: 'var(--mint-300)', points: data.daily.map((d) => ({ date: d.date, value: d.won })) },
                ]}
              />
            </ChartCard>

            <div className="ins-grid">
              <ChartCard icon="List" title="Pipeline"
                sub={winRate === null ? 'No decided leads yet' : `${winRate}% of decided leads won`}>
                <Funnel pipeline={s.pipeline} />
              </ChartCard>

              <ChartCard icon="Tag" title="Deal mix" sub="Where the won value came from">
                <Donut cap="Won" emptyText="No won value in this range yet." segments={[
                  { key: 'recurring', label: 'Recurring', value: Math.round(s.won_value_recurring), color: 'var(--mint-300)' },
                  { key: 'one_off', label: 'One-off', value: Math.round(s.won_value_one_off), color: 'var(--info)' },
                ]} />
              </ChartCard>

              {data.agents.length > 0 && (
                <ChartCard icon="Users" title="Agent leaderboard" sub="Wins in this range, best first">
                  <Leaderboard rows={data.agents} />
                </ChartCard>
              )}

              <ChartCard icon="Search" title="Credits" sub="1 credit = one live search">
                <div className="hi-credits">
                  <span className="hi-cr-big">
                    <span className="hi-cr-num">{fmtNum(data.credits.balance)}</span>
                    <span className="hi-cr-cap">credits left</span>
                  </span>
                  <span className="hi-cr-meta">
                    <span>{fmtNum(data.credits.used)} used in this range</span>
                    <span>{fmtNum(data.credits.searches)} searches run</span>
                  </span>
                  <Link className="hi-cr-buy" to="/leads/credits">Buy credits <Icons.ArrowRight size={13} /></Link>
                </div>
              </ChartCard>
            </div>
          </>
        ) : null}
      </div>
    </div></div>
  );
}
