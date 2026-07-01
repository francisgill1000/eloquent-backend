import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import * as shops from '@/lib/shops';
import ForgotPin from './ForgotPin';

const nav = vi.fn();
vi.mock('react-router-dom', async (orig) => ({
  ...(await orig() as object),
  useNavigate: () => nav,
}));

describe('ForgotPin', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); nav.mockReset(); });

  it('resets the PIN and shows the generated PIN', async () => {
    const spy = vi.spyOn(shops, 'resetPin').mockResolvedValue({ pin: '4821', shop_code: 'ACME01' });
    render(<MemoryRouter><ForgotPin /></MemoryRouter>);
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business id/i), 'ACME01');
    await user.click(screen.getByRole('button', { name: /reset pin/i }));
    expect(spy).toHaveBeenCalledWith('ACME01');
    expect(await screen.findByText('4821')).toBeInTheDocument();
  });
});
