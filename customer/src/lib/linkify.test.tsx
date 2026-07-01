import { describe, it, expect } from 'vitest';
import { render } from '@testing-library/react';
import { linkify } from './linkify';

describe('linkify', () => {
  it('wraps a url in a new-tab anchor and leaves text alone', () => {
    const { container } = render(<div>{linkify('pay here https://ziina.com/x thanks')}</div>);
    const a = container.querySelector('a')!;
    expect(a).toHaveAttribute('href', 'https://ziina.com/x');
    expect(a).toHaveAttribute('target', '_blank');
    expect(a).toHaveAttribute('rel', 'noopener noreferrer');
    expect(container.textContent).toBe('pay here https://ziina.com/x thanks');
  });
});
