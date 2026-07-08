import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { getSimulation, saveSimulation, speak } from './simulation';

vi.mock('./api');

// jsdom does not implement URL.createObjectURL; stub it so vi.spyOn has a
// property to replace (the app itself only runs this in real browsers).
if (typeof URL.createObjectURL !== 'function') {
  (URL as unknown as { createObjectURL: () => string }).createObjectURL = () => '';
}

describe('simulation lib', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getSimulation returns the script', async () => {
    (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { script: { turns: [], booking: {}, voices: { owner: 'shimmer', assistant: 'nova' }, thinking_ms: 1400 } } });
    const s = await getSimulation();
    expect(s.voices.owner).toBe('shimmer');
    expect(api.get).toHaveBeenCalledWith('/shop/simulation');
  });

  it('saveSimulation PUTs the script and returns it', async () => {
    const script = { turns: [{ who: 'owner', text: 'hi' }], booking: {}, voices: { owner: 'coral', assistant: 'nova' }, thinking_ms: 1000 } as never;
    (api.put as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { script } });
    const out = await saveSimulation(script);
    expect(api.put).toHaveBeenCalledWith('/shop/simulation', { script });
    expect(out.voices.owner).toBe('coral');
  });

  it('speak posts text+voice as a blob and returns an object URL', async () => {
    (api.post as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: new Blob(['x'], { type: 'audio/mpeg' }) });
    const spy = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:url');
    const url = await speak('hello', 'shimmer');
    expect(api.post).toHaveBeenCalledWith('/tts', { text: 'hello', voice: 'shimmer' }, { responseType: 'blob' });
    expect(url).toBe('blob:url');
    spy.mockRestore();
  });
});
