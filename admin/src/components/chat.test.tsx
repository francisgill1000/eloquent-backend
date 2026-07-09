import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { renderContent } from './chat';

describe('renderContent', () => {
  it('links BK references by default', () => {
    render(<MemoryRouter>{renderContent('See BK00042 soon')}</MemoryRouter>);
    const link = screen.getByRole('link', { name: 'BK00042' });
    expect(link).toHaveAttribute('href', '/booking/42');
  });

  it('renders BK references as plain text when linkifyRefs is false', () => {
    render(<MemoryRouter>{renderContent('See BK00042 soon', { linkifyRefs: false })}</MemoryRouter>);
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByText(/BK00042/)).toBeInTheDocument();
  });
});
