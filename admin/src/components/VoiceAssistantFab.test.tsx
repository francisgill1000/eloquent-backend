import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { VoiceAssistantFab } from './VoiceAssistantFab';

const navigate = vi.fn();
let pathname = '/settings';
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
  useLocation: () => ({ pathname }),
}));

describe('VoiceAssistantFab', () => {
  beforeEach(() => { vi.clearAllMocks(); pathname = '/settings'; });

  it('navigates to the voice assistant page when tapped', () => {
    render(<VoiceAssistantFab />);
    fireEvent.click(screen.getByRole('button', { name: /assistant/i }));
    expect(navigate).toHaveBeenCalledWith('/ask');
  });

  it.each(['/', '/ask', '/ask/5', '/booking/12'])('is hidden on %s (overlap-prone page)', (p) => {
    pathname = p;
    render(<VoiceAssistantFab />);
    expect(screen.queryByRole('button', { name: /assistant/i })).not.toBeInTheDocument();
  });
});
