import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as pub from '@/lib/publicBooking';
import PublicBooking from './PublicBooking';

vi.mock('@/lib/simulation', () => ({ speak: vi.fn().mockResolvedValue('blob:fake') }));

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/book/7']}>
      <Routes><Route path="/book/:shopId" element={<PublicBooking />} /></Routes>
    </MemoryRouter>,
  );
}

describe('PublicBooking voice', () => {
  beforeEach(() => vi.restoreAllMocks());

  it('typing to the assistant fills the form fields', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue({
      id: 7, name: 'FreshPress', catalogs: [{ id: 1, title: 'Classic Haircut', price: 30 }],
    });
    vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({
      reply_text: 'Great, what day?', ready: false,
      fields: { service: 'Classic Haircut', customer_name: 'Sara' },
    });

    renderPage();
    const user = userEvent.setup();
    await screen.findByText('Classic Haircut');

    await user.type(screen.getByPlaceholderText(/tell me what you'd like/i), 'classic haircut, I am Sara');
    await user.click(screen.getByRole('button', { name: /send/i }));

    await waitFor(() => expect((screen.getByLabelText(/your name/i) as HTMLInputElement).value).toBe('Sara'));
    expect(screen.getByText('Great, what day?')).toBeInTheDocument();
  });
});
