import { useState } from 'react';
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
};

/**
 * One inline calendar that captures a from→to range: first click sets the start
 * (and clears the end), the next click sets the end (swapping if it's earlier).
 * Clicking again when a full range exists starts a fresh range. Days after `max`
 * are disabled.
 */
export function DateRangePicker({ from, to, onChange, max }: Props) {
  const fromD = parse(from);
  const toD = parse(to);
  const maxD = max ?? new Date();
  const [view, setView] = useState<Date>(() => fromD ?? new Date());

  const weeks = monthMatrix(view);
  const afterMax = (d: Date) => formatLocalDate(d) > formatLocalDate(maxD);
  const inRange = (d: Date) => !!(fromD && toD && d >= fromD && d <= toD);
  const isEnd = (d: Date) => !!((fromD && isSameDay(d, fromD)) || (toD && isSameDay(d, toD)));

  const pick = (d: Date) => {
    if (afterMax(d)) return;
    if (!fromD || (fromD && toD)) { onChange(formatLocalDate(d), ''); return; } // start a new range
    if (d < fromD) onChange(formatLocalDate(d), formatLocalDate(fromD));         // picked end before start → swap
    else onChange(formatLocalDate(fromD), formatLocalDate(d));
  };

  return (
    <div className="drp">
      <div className="drp-head">
        <button type="button" className="drp-nav" aria-label="Previous month"
          onClick={() => setView(addMonths(view, -1))}><Icons.ChevronLeft size={16} /></button>
        <span className="drp-title">{monthTitle(view)}</span>
        <button type="button" className="drp-nav" aria-label="Next month"
          onClick={() => setView(addMonths(view, 1))}><Icons.Chevron size={16} /></button>
      </div>
      <div className="drp-grid">
        {WD.map((d) => <div key={d} className="drp-wd">{d}</div>)}
        {weeks.flat().map((d) => {
          const disabled = afterMax(d);
          const cls = ['drp-day',
            d.getMonth() === view.getMonth() ? '' : 'drp-out',
            disabled ? 'drp-dis' : '',
            inRange(d) ? 'drp-in' : '',
            isEnd(d) ? 'drp-end' : '',
          ].filter(Boolean).join(' ');
          return (
            <button key={d.toISOString()} type="button" className={cls} disabled={disabled}
              onClick={() => pick(d)} aria-label={formatLocalDate(d)}>{d.getDate()}</button>
          );
        })}
      </div>
    </div>
  );
}
