import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { VoiceAssistantFab } from './VoiceAssistantFab';

const navigate = vi.fn();
vi.mock('react-router-dom', () => ({ useNavigate: () => navigate }));

describe('VoiceAssistantFab', () => {
  it('navigates to the voice assistant page when tapped', () => {
    render(<VoiceAssistantFab />);
    fireEvent.click(screen.getByRole('button', { name: /assistant/i }));
    expect(navigate).toHaveBeenCalledWith('/ask');
  });
});
