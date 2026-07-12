import { Link } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { usePush } from '@/lib/usePush';
import { WHATSAPP_ENABLED } from '@/lib/features';
import { shopHasModule, type Module } from '@/lib/modules';

type Option = {
  label: string;
  sub: string;
  to: string;
  icon: keyof typeof Icons;
  modules: Module[];
};

const BOTH: Module[] = ['bookings', 'leads'];

const ALL_OPTIONS: Option[] = [
  { label: 'Business Hunt', sub: 'Find UAE businesses & win them', to: '/leads', icon: 'Search', modules: ['leads'] },
  { label: 'Working Hours', sub: 'Set your open & close times', to: '/working-hours', icon: 'Clock', modules: ['bookings'] },
  { label: 'Services', sub: 'Add or edit what you offer', to: '/services', icon: 'Grid', modules: ['bookings'] },
  { label: 'Staff', sub: 'Add & manage your team', to: '/staff', icon: 'Users', modules: ['bookings'] },
  { label: 'Customers', sub: 'Your customer list & visit history', to: '/customers', icon: 'User', modules: ['bookings'] },
  { label: 'Demo simulation', sub: 'Play a scripted voice booking to record demo videos', to: '/settings/simulation', icon: 'Mic', modules: ['bookings'] },
  { label: 'Recurring booking', sub: 'Set up a regular weekly or monthly appointment', to: '/bookings/recurring', icon: 'Calendar', modules: ['bookings'] },
  { label: 'Booking notifications', sub: 'Reminders, reviews & waitlist messages', to: '/settings/notifications', icon: 'Bell', modules: ['bookings'] },
  { label: 'Reviews', sub: 'Customer ratings & feedback', to: '/reviews', icon: 'Heart', modules: ['bookings'] },
  { label: 'Insights', sub: 'No-show, repeat & rating analytics', to: '/insights', icon: 'Chart', modules: ['bookings'] },
  // WhatsApp connection — hidden temporarily behind WHATSAPP_ENABLED.
  { label: 'WhatsApp', sub: 'Chat connection settings', to: '/chats/setup', icon: 'WhatsApp', modules: BOTH },
  { label: 'AI Assistant', sub: 'What your auto-reply assistant says', to: '/assistant', icon: 'Chat', modules: BOTH },
  { label: 'Access Control', sub: 'Users, roles & permissions', to: '/settings/access', icon: 'Key', modules: BOTH },
];
const OPTIONS: Option[] = ALL_OPTIONS.filter((o) => WHATSAPP_ENABLED || o.to !== '/chats/setup');

export default function Settings() {
  const { shop, can } = useShop();
  const push = usePush();
  // Hide options for modules this shop lacks; also hide Access Control from
  // users who can neither view users nor roles.
  const visible = OPTIONS.filter(
    (o) =>
      o.modules.some((m) => shopHasModule(shop, m)) &&
      (o.to !== '/settings/access' || can('users.view') || can('roles.view')),
  );
  const options: Option[] = shop?.is_master
    ? [...visible, { label: 'All Businesses', sub: 'Master view — codes, PINs & activity', to: '/master', icon: 'Key', modules: BOTH }]
    : visible;

  return (
    <div className="m-screen c-settings"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Settings</h1>
        <p className="c-page-sub">Manage how your business runs on Business Lens.</p>
      </div>

      <div className="c-set-grid">
      {push.supported && (
        <div
          className="c-set-link"
          role="button"
          tabIndex={0}
          aria-pressed={push.on}
          style={{ cursor: push.busy ? 'default' : 'pointer', opacity: push.busy ? 0.7 : 1 }}
          onClick={() => { if (!push.busy) void push.toggle(); }}
          onKeyDown={(e) => { if ((e.key === 'Enter' || e.key === ' ') && !push.busy) { e.preventDefault(); void push.toggle(); } }}
        >
          <span className="c-set-ic"><Icons.Bell size={18} /></span>
          <span className="c-set-body">
            <span className="c-set-label">Notifications</span>
            <span className="c-set-sub">{push.busy ? 'Updating…' : push.on ? 'On — you’ll be alerted to new chats' : 'Off — tap to get alerts for new leads'}</span>
          </span>
          <span className={`c-toggle ${push.on ? 'on' : ''}`}><span className="c-toggle-knob" /></span>
        </div>
      )}

      {options.map((o) => {
        const Icon = Icons[o.icon];
        return (
          <Link key={o.to} to={o.to} className="c-set-link">
            <span className="c-set-ic"><Icon size={18} /></span>
            <span className="c-set-body">
              <span className="c-set-label">{o.label}</span>
              <span className="c-set-sub">{o.sub}</span>
            </span>
            <Icons.Chevron size={18} />
          </Link>
        );
      })}
      </div>
    </div></div>
  );
}
