import { useState, type ReactNode } from 'react';
import { Icons } from '@/components/Icons';
import { monthMatrix, addMonths, isSameDay, monthTitle } from '@/lib/calendar';
import { formatLocalDate } from '@/lib/date';
import '@/styles/daterange.css';

const WD = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const parse = (s: string): Date | null => {
  if (!s) return null;
  const [y, m, d] = s.split('-').map(Number);
  return y ? new Date(y, m - 1, d) : null;
};

type Props = {
  from: string;                 // '' or 'YYYY-MM-DD'
  to: string;                   // '' or 'YYYY-MM-DD'
  onChange: (from: string, to: string) => void;
  max?: Date;                   // latest selectable day (default: today)
  months?: number;              // panes to show side by side (default 2)
  footer?: ReactNode;           // rendered inside the box, below the calendar
};

/**
 * One inline range calendar spanning `months` panes. First click sets the start
 * (and clears the end); the next click sets the end (swapping if earlier); a
 * click when a full range exists starts fresh. While choosing the end, hovering
 * previews the in-between range. Days after `max` are disabled.
 */
export function DateRangePicker({ from, to, onChange, max, months = 2, footer }: Props) {
  const fromD = parse(from);
  const toD = parse(to);
  const maxD = max ?? new Date();
  // Default the left pane so the panes end on the current month — i.e. show the
  // recent past ([last month … this month] for 2 panes), all mostly selectable.
  const [view, setView] = useState<Date>(() => fromD ?? addMonths(new Date(), -(months - 1)));
  const [hover, setHover] = useState<Date | null>(null);

  const afterMax = (d: Date) => formatLocalDate(d) > formatLocalDate(maxD);

  // The other end of the range: the committed end, or (while choosing) the hover.
  const other = toD ?? (fromD && !toD ? hover : null);
  const lo = fromD && other ? (fromD <= other ? fromD : other) : null;
  const hi = fromD && other ? (fromD <= other ? other : fromD) : null;

  const inRange = (d: Date) => !!(lo && hi && d >= lo && d <= hi);
  const isEnd = (d: Date) =>
    !!((fromD && isSameDay(d, fromD)) || (toD && isSameDay(d, toD)) || (other && isSameDay(d, other)));

  const pick = (d: Date) => {
    if (afterMax(d)) return;
    if (!fromD || (fromD && toD)) { onChange(formatLocalDate(d), ''); return; } // start fresh
    if (d < fromD) onChange(formatLocalDate(d), formatLocalDate(fromD));         // end before start → swap
    else onChange(formatLocalDate(fromD), formatLocalDate(d));
    setHover(null);
  };

  const renderMonth = (monthDate: Date, idx: number) => (
    <div className="drp-month" key={idx}>
      <div className="drp-mhead">
        {idx === 0
          ? <button type="button" className="drp-nav" aria-label="Previous month" onClick={() => setView(addMonths(view, -1))}><Icons.ChevronLeft size={16} /></button>
          : <span className="drp-nav-sp" />}
        <span className="drp-title">{monthTitle(monthDate)}</span>
        {idx === months - 1
          ? <button type="button" className="drp-nav" aria-label="Next month" onClick={() => setView(addMonths(view, 1))}><Icons.Chevron size={16} /></button>
          : <span className="drp-nav-sp" />}
      </div>
      <div className="drp-grid">
        {WD.map((d) => <div key={d} className="drp-wd">{d}</div>)}
        {monthMatrix(monthDate).flat().map((d) => {
          const disabled = afterMax(d);
          const cls = ['drp-day',
            d.getMonth() === monthDate.getMonth() ? '' : 'drp-out',
            disabled ? 'drp-dis' : '',
            inRange(d) ? 'drp-in' : '',
            isEnd(d) ? 'drp-end' : '',
          ].filter(Boolean).join(' ');
          return (
            <button key={formatLocalDate(d)} type="button" className={cls} disabled={disabled}
              onClick={() => pick(d)} onMouseEnter={() => { if (!disabled) setHover(d); }}
              aria-label={formatLocalDate(d)}>{d.getDate()}</button>
          );
        })}
      </div>
    </div>
  );

  return (
    <div className="drp">
      <div className="drp-months" onMouseLeave={() => setHover(null)}>
        {Array.from({ length: months }, (_, i) => renderMonth(addMonths(view, i), i))}
      </div>
      {footer && <div className="drp-foot">{footer}</div>}
    </div>
  );
}
