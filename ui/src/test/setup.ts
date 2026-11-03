import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Testing Library only auto-registers cleanup when the test runner injects
// globals; this project runs vitest with `globals: false`, so it is explicit
// here (otherwise every render stays mounted and queries match several trees).
afterEach(() => {
  cleanup();
});
