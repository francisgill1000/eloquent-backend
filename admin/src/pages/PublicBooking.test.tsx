import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as pub from '@/lib/publicBooking';
import * as bookingsLib from '@/lib/bookings';
import * as session from '@/lib/bookingSession';
import { speak } from '@/lib/simulation';
import PublicBooking from './PublicBooking';

vi.mock('@/lib/simulation', () => ({ speak: vi.fn() }));

const rec = vi.hoisted(() => ({ onSilence: null as null | (() => void) }));
vi.mock('@/hooks/useRecorder', () => ({
  useRecorder: (opts?: { onSilence?: () => void }) => {
    rec.onSilence = opts?.onSilence ?? null;
    const [recording, setRecording] = React.useState(false);
    return {
      recording, supported: true, level: 0,
      start: async () => { setRecording(true); },
      stop: async () => { setRecording(false); return new Blob(['x'], { type: 'audio/webm' }); },
    };
  },
}));

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/book/7']}>
      <Routes><Route path="/book/:shopId" element={<PublicBooking />} /></Routes>
    </MemoryRouter>,
  );
}

const SHOP = { id: 7, name: 'FreshPress', catalogs: [{ id: 1, title: 'Classic Haircut', price: 30 }] };

describe('PublicBooking (chat)', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    vi.mocked(speak).mockResolvedValue('blob:fake');
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
    // jsdom lacks object-URL support; audio bubbles build URLs from reply_audio.
    if (!URL.createObjectURL) Object.defineProperty(URL, 'createObjectURL', { value: () => 'blob:aud', writable: true });
    else vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:aud');
    // jsdom also lacks revokeObjectURL — same shim pattern as above.
    if (!URL.revokeObjectURL) Object.defineProperty(URL, 'revokeObjectURL', { value: () => undefined, writable: true });
    else vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
  });

  it('shows a friendly error when the shop link is invalid', async () => {
    vi.spyOn(pub, 'getPublicShop').mockRejectedValue(new Error('404'));
    renderPage();
    await screen.findByText(/booking link isn't available/i);
  });

  it('auto-sends the turn when the customer goes quiet, then books when ready', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantVoice').mockResolvedValue({
      transcript: 'friday 3pm 0501234567', reply_text: 'All set!', ready: true,
      fields: { service: 'Classic Haircut', date: '2026-07-12', start_time: '15:00', customer_name: 'Sara', customer_phone: '0501234567' },
    });
    vi.spyOn(pub, 'recordBooking').mockResolvedValue({ ok: true, reference: 'BK00009' });
    const create = vi.spyOn(bookingsLib, 'createBooking').mockResolvedValue({ id: 9, booking_reference: 'BK00009' } as never);

    renderPage();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /microphone/i }));   // start recording
    await act(async () => { rec.onSilence?.(); });                                   // 1.5s silence fires

    await waitFor(() => expect(create).toHaveBeenCalledWith(7, expect.objectContaining({
      services: [{ title: 'Classic Haircut', price: 30 }], charges: 30,
      date: '2026-07-12', start_time: '15:00', customer_name: 'Sara', customer_whatsapp: '0501234567',
    })));
    await screen.findByText(/you're booked/i);
  });

  it('sends a typed message and shows both bubbles', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    const text = vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({
      reply_text: 'What day works?', ready: false, fields: { service: 'Classic Haircut' },
    });

    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'I want a haircut');
    await user.click(screen.getByRole('button', { name: /send/i }));

    await waitFor(() => expect(text).toHaveBeenCalledWith(7, 'I want a haircut', expect.anything(), expect.anything()));
    await screen.findByText('I want a haircut');   // user bubble
    await screen.findByText('What day works?');    // assistant bubble
  });

  it('disables text send while recording, so a concurrent voice turn cannot race a typed one', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    const text = vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({
      reply_text: 'What day works?', ready: false, fields: {},
    });

    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'I want a haircut');
    await user.click(await screen.findByRole('button', { name: /microphone/i }));   // start recording

    const sendBtn = screen.getByRole('button', { name: /send/i });
    expect(sendBtn).toBeDisabled();
    expect(input).toBeDisabled();

    await user.click(sendBtn);   // attempted send while recording must be a no-op
    expect(text).not.toHaveBeenCalled();
  });

  it('New booking clears the thread and rotates the session', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({ reply_text: 'What day works?', ready: false, fields: {} });
    const rotate = vi.spyOn(session, 'newBookingSession');

    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'hello');
    await user.click(screen.getByRole('button', { name: /send/i }));
    await screen.findByText('hello');

    await user.click(screen.getByRole('button', { name: /new booking/i }));

    expect(rotate).toHaveBeenCalled();
    expect(screen.queryByText('hello')).toBeNull();          // thread cleared
  });

  it('drops a stale in-flight reply after New booking resets the thread', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    let resolve!: (v: pub.AssistantReply) => void;
    const deferred = new Promise<pub.AssistantReply>((r) => { resolve = r; });
    vi.spyOn(pub, 'bookAssistantText').mockReturnValue(deferred);

    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'I want a blow-dry');
    await user.click(screen.getByRole('button', { name: /send/i }));   // request now in flight (busy)

    await user.click(screen.getByRole('button', { name: /new booking/i }));   // reset while in flight

    await act(async () => {
      resolve({ reply_text: 'What day?', ready: false, fields: { service: 'Blow-dry' } });
      await deferred;
    });

    expect(screen.queryByText('What day?')).toBeNull();   // stale response was dropped
  });

  it('revokes bubble audio URLs on New booking', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({
      reply_text: 'Here you go', ready: false, fields: {}, reply_audio: btoa('mp3'),
    });

    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'hi');
    await user.click(screen.getByRole('button', { name: /send/i }));
    await screen.findByText('Here you go');

    await user.click(screen.getByRole('button', { name: /new booking/i }));

    expect(URL.revokeObjectURL).toHaveBeenCalled();
  });

  it('renders a booking reference as plain text (no link) for customers', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({
      reply_text: 'Your reference is BK00009.', ready: false, fields: {},
    });
    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'ref?');
    await user.click(screen.getByRole('button', { name: /send/i }));

    await screen.findByText(/BK00009/);
    expect(screen.queryByRole('link', { name: 'BK00009' })).toBeNull();
  });
});
