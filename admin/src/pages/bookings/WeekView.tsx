import type { Booking } from '@/types';
import { formatLocalDate } from '@/lib/date';
import {
  groupByDay,
  hourRange,
  layoutDayEvents,
  fmtHour,
  fmtTimeMin,
  statusKind,
  isSameDay,
} from '@/lib/calendar';

// Pixel height of one hour row — drives both the grid height and event geometry.
const HOUR_H = 56;

function bookingName(b: Booking): string {
  return b.customer?.name || b.customer_name || 'Guest';
}

function serviceLabel(b: Booking): string {
  return b.services?.map((s) => s.title || s.name).filter(Boolean).join(', ') || 'Service';
}

type Props = {
  weekDates: Date[]; // 7 dates, Mon…Sun
  bookings: Booking[];
  today: Date;
  now: Date;
  onOpen: (id: number) => void;
};

export function WeekView({ weekDates, bookings, today, now, onOpen }: Props) {
  const byDay = groupByDay(bookings);
  const { startHour, endHour } = hourRange(bookings);
  const hours = Array.from({ length: endHour - startHour }, (_, i) => startHour + i);
  const gridHeight = hours.length * HOUR_H;
  const nowMin = now.getHours() * 60 + now.getMinutes();
  const nowTop = ((nowMin - startHour * 60) / 60) * HOUR_H;
  const nowVisible = nowMin >= startHour * 60 && nowMin <= endHour * 60;

  return (
    <div className="c-bk-week">
      <div className="c-bk-week-head">
        <div className="c-bk-week-corner" />
        {weekDates.map((d) => {
          const isToday = isSameDay(d, today);
          return (
            <div key={d.toISOString()} className={`c-bk-week-dh${isToday ? ' today' : ''}`}>
              <span className="c-bk-week-dow">{d.toLocaleDateString('en-US', { weekday: 'short' })}</span>
              <span className="c-bk-week-dnum">{d.getDate()}</span>
            </div>
          );
        })}
      </div>

      <div className="c-bk-week-body" style={{ height: gridHeight }}>
        <div className="c-bk-week-gutter">
          {hours.map((h) => (
            <div key={h} className="c-bk-hour" style={{ height: HOUR_H }}>
              <span>{fmtHour(h)}</span>
            </div>
          ))}
        </div>

        {weekDates.map((d) => {
          const isToday = isSameDay(d, today);
          const placed = layoutDayEvents(byDay.get(formatLocalDate(d)) ?? []);
          return (
            <div key={d.toISOString()} className={`c-bk-daycol${isToday ? ' today' : ''}`}>
              {hours.map((h) => (
                <div key={h} className="c-bk-hline" style={{ height: HOUR_H }} />
              ))}

              {placed.map((ev) => {
                const top = ((ev.startMin - startHour * 60) / 60) * HOUR_H;
                const height = ((ev.endMin - ev.startMin) / 60) * HOUR_H;
                const width = 100 / ev.cols;
                const compact = height < 44;
                return (
                  <button
                    key={ev.booking.id}
                    type="button"
                    className={`c-bk-event k-${statusKind(ev.booking.status)}${compact ? ' compact' : ''}`}
                    style={{ top, height, left: `${ev.col * width}%`, width: `calc(${width}% - 3px)` }}
                    onClick={() => onOpen(ev.booking.id)}
                    title={`${fmtTimeMin(ev.startMin)} · ${bookingName(ev.booking)} · ${serviceLabel(ev.booking)}`}
                  >
                    <span className="c-bk-event-time">{fmtTimeMin(ev.startMin)}</span>
                    <span className="c-bk-event-name">{bookingName(ev.booking)}</span>
                    {!compact && <span className="c-bk-event-svc">{serviceLabel(ev.booking)}</span>}
                  </button>
                );
              })}

              {isToday && nowVisible && <div className="c-bk-now" style={{ top: nowTop }} />}
            </div>
          );
        })}
      </div>
    </div>
  );
}
