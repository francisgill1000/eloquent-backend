import { Link } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { usePush } from '@/lib/usePush';

type Option = {
  label: string;
  sub: string;
  to: string;
  icon: keyof typeof Icons;
};

const OPTIONS: Option[] = [
  { label: 'Working Hours', sub: 'Set your open & close times', to: '/working-hours', icon: 'Clock' },
  { label: 'Services', sub: 'Add or edit what you offer', to: '/services', icon: 'Grid' },
  { label: 'Staff', sub: 'Add & manage your team', to: '/staff', icon: 'Users' },
  { label: 'WhatsApp', sub: 'Chat connection settings', to: '/chats/setup', icon: 'WhatsApp' },
  { label: 'AI Assistant', sub: 'What your auto-reply assistant says', to: '/assistant', icon: 'Chat' },
];

export default function Settings() {
  const { shop } = useShop();
  const push = usePush();
  const options: Option[] = shop?.is_master
    ? [...OPTIONS, { label: 'All Businesses', sub: 'Master view — codes, PINs & activity', to: '/master', icon: 'Key' }]
    : OPTIONS;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head">
        <h1 className="c-page-title">Settings</h1>
        <p className="c-page-sub">Manage how your business runs on Admin.</p>
      </div>

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
          <span className="c-set-ic" style={push.on ? { color: '#22c55e' } : undefined}><Icons.Bell size={18} /></span>
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
    </div></div>
  );
}
