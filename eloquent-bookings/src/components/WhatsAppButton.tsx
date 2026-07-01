import { Icons } from './Icons';

const SUPPORT_NUMBER = '971557369629';
const SUPPORT_MESSAGE = 'Hi Eloquent Bookings team, I need some help.';

export function WhatsAppButton() {
  const href = `https://wa.me/${SUPPORT_NUMBER}?text=${encodeURIComponent(SUPPORT_MESSAGE)}`;
  return (
    <a className="c-wa" href={href} target="_blank" rel="noopener noreferrer" aria-label="WhatsApp support">
      <Icons.WhatsApp size={18} />
    </a>
  );
}
