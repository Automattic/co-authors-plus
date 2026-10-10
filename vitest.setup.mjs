import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Testing Library only unmounts between tests on its own when the runner
// exposes afterEach as a global, which Vitest does not by default.
afterEach( cleanup );
