import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { postText } from '@/lib/assistant';
import VoiceAssistant from './VoiceAssistant';

// This file deliberately does NOT mock react-router-dom. The blocker under
// test only reproduces with the REAL router: `adopt()` calls
// `navigate('/ask/9', { replace: true })` on the first send in a brand-new
// thread, which changes `useParams().conversationId` for real. The main
// VoiceAssistant.test.tsx mocks react-router-dom entirely (navigate is a
// vi.fn() that never touches params), so it can never exercise this path.

vi.mock('@/context/ShopContext', () => ({
  useShop: () => ({ shop: { is_master: false } }),
}));
vi.mock('@/lib/assistant', () => ({
  getConversation: vi.fn().mockResolvedValue([]),
  listConversations: vi.fn().mockResolvedValue({ conversations: [], has_more: false }),
  renameConversation: vi.fn().mockResolvedValue(undefined),
  deleteConversation: vi.fn().mockResolvedValue(undefined),
  postText: vi.fn(),
  postVoice: vi.fn(),
  confirmAction: vi.fn(),
}));
vi.mock('@/hooks/useRecorder', () => ({
  useRecorder: () => ({ recording: false, start: vi.fn(), stop: vi.fn(), supported: true }),
}));

const asMock = (fn: unknown) => fn as unknown as ReturnType<typeof vi.fn>;

beforeAll(() => {
  window.HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined);
  window.HTMLMediaElement.prototype.pause = vi.fn();
});

beforeEach(() => {
  vi.clearAllMocks();
});

// Mirrors how admin/src/App.tsx wires the two Ask routes (see lines ~148-150):
// both `/ask` and `/ask/:conversationId` render VoiceAssistant, inside the
// tabbed layout in the real app. We only need the two routes themselves —
// the surrounding shell isn't part of what's under test.
function renderAtAsk() {
  return render(
    <MemoryRouter initialEntries={['/ask']}>
      <Routes>
        <Route path="/ask" element={<VoiceAssistant />} />
        <Route path="/ask/:conversationId" element={<VoiceAssistant />} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('VoiceAssistant with the real router (adopt navigation)', () => {
  it('keeps the confirm card visible after the first message in a brand-new chat adopts a new thread id', async () => {
    // A destructive "delete Ali" as the very first message of a new /ask
    // thread — the model previews the change and asks the owner to confirm
    // in the app. `adopt()` will navigate /ask -> /ask/9 in the same batch
    // that sets the confirm action, since this is a brand-new thread.
    asMock(postText).mockResolvedValue({
      conversation_id: 9,
      title: 'delete Ali',
      reply_text: 'Delete Ali? Confirm below.',
      reply_audio_url: null,
      action: { type: 'confirm', id: 43, summary: 'Delete staff member "Ali"', changes: {}, destructive: true },
    });

    renderAtAsk();
    await screen.findByPlaceholderText(/type/i);

    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'delete Ali' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));

    // The reply lands, and the route adopts /ask/9 (a real router navigation).
    await waitFor(() => expect(screen.getByText('Delete Ali? Confirm below.')).toBeInTheDocument());

    // The confirm card must still be there — this is the whole point of the
    // feature: an owner sending a destructive instruction as their first
    // message of a new chat must see a way to confirm it, not a dead end.
    expect(screen.getByText('Delete staff member "Ali"')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: /confirm/i })).toBeInTheDocument();
  });
});
