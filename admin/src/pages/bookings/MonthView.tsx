import type { Booking } from '@/types';
import { formatLocalDate } from '@/lib/date';
import {
  groupByDay,
  monthMatrix,
  parseHM,
  fmtTimeMin,
  statusKind,
  isSameDay,
} from '@/lib/calendar';

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const MAX_CHIPS = 3;

function bookingName(b: Booking): string {
  return b.customer?.name || b.customer_name || 'Guest';
}

function chipTime(b: Booking): string {
  const min = parseHM(b.start_time);
  return min == null ? '' : fmtTimeMin(min);
}

type Props = {
  monthDate: Date;
  bookings: Booking[];
  today: Date;
  onOpen: (id: number) => void;
  onSelectDay: (day: Date) => void;
};

export function MonthView({ monthDate, bookings, today, onOpen, onSelectDay }: Props) {
  const weeks = monthMatrix(monthDate);
  const byDay = groupByDay(bookings);

  return (
    <div className="c-bk-month">
      <div className="c-bk-month-head">
        {WEEKDAYS.map((d) => (
          <div key={d} className="c-bk-wd">{d}</div>
        ))}
      </div>
      <div className="c-bk-month-grid" style={{ gridTemplateRows: `repeat(${weeks.length}, minmax(0, 1fr))` }}>
        {weeks.flat().map((day) => {
          const items = byDay.get(formatLocalDate(day)) ?? [];
          const inMonth = day.getMonth() === monthDate.getMonth();
          const isToday = isSameDay(day, today);
          const overflow = items.length - MAX_CHIPS;
          return (
            <div key={day.toISOString()} className={`c-bk-cell${inMonth ? '' : ' out'}${isToday ? ' today' : ''}`}>
              <button
                type="button"
                className="c-bk-cell-head"
                onClick={() => onSelectDay(day)}
                title="Open this week"
              >
                <span className="c-bk-daynum">{day.getDate()}</span>
                {items.length > 0 && <span className="c-bk-daycount">{items.length}</span>}
              </button>
              <div className="c-bk-chips">
                {items.slice(0, MAX_CHIPS).map((b) => (
                  <button
                    key={b.id}
                    type="button"
                    className={`c-bk-chip k-${statusKind(b.status)}`}
                    onClick={() => onOpen(b.id)}
                    title={`${bookingName(b)} · ${b.status ?? 'Booked'}`}
                  >
                    {chipTime(b) && <em>{chipTime(b)}</em>}
                    <span>{bookingName(b)}</span>
                  </button>
                ))}
                {overflow > 0 && (
                  <button type="button" className="c-bk-more" onClick={() => onSelectDay(day)}>
                    +{overflow} more
                  </button>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
