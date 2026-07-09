import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as pub from '@/lib/publicBooking';
import * as bookingsLib from '@/lib/bookings';
import { speak } from '@/lib/simulation';
import PublicBooking from './PublicBooking';

vi.mock('@/lib/simulation', () => ({ speak: vi.fn() }));

// Captures the onSilence safety-net the page wires into the recorder, so a test
// can fire it to simulate the customer going quiet without tapping.
const rec = vi.hoisted(() => ({ onSilence: null as null | (() => void) }));

// A stateful mock recorder: start() flips recording on, stop() flips it off and
// returns a fake blob — enough to drive the tap → record → send flow without a
// real MediaRecorder (absent in jsdom).
vi.mock('@/hooks/useRecorder', () => ({
  useRecorder: (opts?: { onSilence?: () => void }) => {
    rec.onSilence = opts?.onSilence ?? null;
    const [recording, setRecording] = React.useState(false);
    return {
      recording,
      supported: true,
      level: 0,
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

describe('PublicBooking (voice-only)', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    // restoreAllMocks wipes the speak module-mock implementation — re-arm it.
    vi.mocked(speak).mockResolvedValue('blob:fake');
    // jsdom has no real audio; stub play so TTS playback resolves quietly.
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
  });

  it('shows a friendly error when the shop link is invalid', async () => {
    vi.spyOn(pub, 'getPublicShop').mockRejectedValue(new Error('404'));
    renderPage();
    await screen.findByText(/booking link isn't available/i);
  });

  it('auto-books once the assistant reports it has every detail', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantVoice').mockResolvedValue({
      reply_text: 'All set!', ready: true,
      fields: { service: 'Classic Haircut', date: '2026-07-12', start_time: '15:00', customer_name: 'Sara', customer_phone: '0501234567' },
    });
    vi.spyOn(pub, 'recordBooking').mockResolvedValue({ ok: true, reference: 'BK00009' });
    const create = vi.spyOn(bookingsLib, 'createBooking').mockResolvedValue({ id: 9, booking_reference: 'BK00009' } as never);

    renderPage();
    const user = userEvent.setup();

    await user.click(await screen.findByRole('button', { name: /speak to book/i }));   // start
    await user.click(await screen.findByRole('button', { name: /stop/i }));            // stop → send → auto-book

    await waitFor(() => expect(create).toHaveBeenCalledWith(7, expect.objectContaining({
      services: [{ title: 'Classic Haircut', price: 30 }],
      charges: 30, date: '2026-07-12', start_time: '15:00',
      customer_name: 'Sara', customer_whatsapp: '0501234567',
    })));
    await screen.findByText(/you're booked/i);
  });

  it('auto-sends the turn when the customer goes quiet without tapping stop', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    const voice = vi.spyOn(pub, 'bookAssistantVoice').mockResolvedValue({
      reply_text: 'What day works?', ready: false, fields: { service: 'Classic Haircut' },
    });

    renderPage();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /speak to book/i }));   // start recording

    // The customer stops talking and forgets to tap — the recorder's silence
    // safety-net fires, which should end the turn and send it for them.
    await act(async () => { rec.onSilence?.(); });

    await waitFor(() => expect(voice).toHaveBeenCalled());
  });

  it('the End button returns the screen to the start state', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);

    renderPage();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /speak to book/i }));   // now in a live session
    await user.click(await screen.findByRole('button', { name: /^end$/i }));           // end it

    await screen.findByRole('button', { name: /speak to book/i });                     // back to idle
  });

  it('does not book while the assistant is still missing details', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantVoice').mockResolvedValue({
      reply_text: 'What day works for you?', ready: false, fields: { service: 'Classic Haircut' },
    });
    const create = vi.spyOn(bookingsLib, 'createBooking').mockResolvedValue({ id: 9 } as never);

    renderPage();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /speak to book/i }));
    await user.click(await screen.findByRole('button', { name: /stop/i }));

    await waitFor(() => expect(pub.bookAssistantVoice).toHaveBeenCalled());
    expect(create).not.toHaveBeenCalled();
  });
});
