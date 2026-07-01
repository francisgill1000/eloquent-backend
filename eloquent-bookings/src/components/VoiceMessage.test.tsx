import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { VoiceMessage } from './VoiceMessage';

describe('VoiceMessage', () => {
  it('renders a play control', () => {
    render(<VoiceMessage src="https://x/a.mp3" />);
    expect(screen.getByRole('button', { name: /play voice message/i })).toBeInTheDocument();
  });

  it('reports speaking on play and pause', () => {
    const cb = vi.fn();
    const { container } = render(<VoiceMessage src="https://x/a.mp3" onSpeakingChange={cb} />);
    const audio = container.querySelector('audio')!;
    fireEvent.play(audio);
    expect(cb).toHaveBeenLastCalledWith(true);
    fireEvent.pause(audio);
    expect(cb).toHaveBeenLastCalledWith(false);
  });

  it('fills about half the waveform at 50% progress', () => {
    const { container } = render(<VoiceMessage src="https://x/a.mp3" />);
    const audio = container.querySelector('audio') as HTMLAudioElement;
    Object.defineProperty(audio, 'duration', { configurable: true, value: 10 });
    Object.defineProperty(audio, 'currentTime', { configurable: true, value: 5 });
    fireEvent.timeUpdate(audio);
    expect(container.querySelectorAll('.c-vm-wave span').length).toBe(28);
    const on = container.querySelectorAll('.c-vm-wave span.on').length;
    expect(on).toBeGreaterThan(10);
    expect(on).toBeLessThan(18);
  });
});
