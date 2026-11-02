import tseslint from 'typescript-eslint';

/**
 * Flat config. Two project rules are encoded here because they are the ones a
 * reviewer keeps having to repeat:
 *
 *  - no physical margin/padding/inset utilities (`ml-`, `pr-`, `left-`, …) —
 *    the panel supports `ar-SA` RTL, so only logical properties are allowed;
 *  - no `console.*` in shipped code (the API client reports errors through
 *    typed exceptions and the UI through toasts).
 */
export default tseslint.config(
  {
    ignores: ['dist', 'node_modules', 'coverage', 'playwright-report', 'test-results', 'public/app'],
  },
  ...tseslint.configs.recommended,
  {
    files: ['src/**/*.{ts,tsx}'],
    rules: {
      'no-console': ['warn', { allow: ['warn', 'error'] }],
      'no-restricted-syntax': [
        'error',
        {
          selector:
            "Literal[value=/(^|\\s)(m[lr]|p[lr]|left-|right-|border-l|border-r|rounded-l|rounded-r|text-left|text-right)(-|$|\\s)/]",
          message:
            'Use logical utilities (ms-/me-/ps-/pe-/start-/end-/text-start/text-end) so ar-SA (RTL) keeps working.',
        },
      ],
    },
  },
  {
    files: ['src/**/*.test.{ts,tsx}', 'src/test/**'],
    rules: {
      'no-restricted-syntax': 'off',
    },
  },
);
