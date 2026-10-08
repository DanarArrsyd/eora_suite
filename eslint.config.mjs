import nextVitals from 'eslint-config-next/core-web-vitals';
import nextTs from 'eslint-config-next/typescript';

/** ESLint 9 flat config for all TypeScript workspaces (doc 22 §4). */
const config = [
  { ignores: ['**/node_modules/**', '**/.next/**', '**/next-env.d.ts', 'design/**', 'docs/**', '**/playwright-report/**', '**/test-results/**'] },
  ...nextVitals,
  ...nextTs,
  {
    settings: { next: { rootDir: 'apps/web/' }, react: { version: '19.3' } },
    rules: {
      'jsx-a11y/no-autofocus': 'off', // search dialog focuses its input intentionally (Radix manages focus return)
      'jsx-a11y/anchor-is-valid': 'error',
      'jsx-a11y/no-noninteractive-element-interactions': 'error',
      'jsx-a11y/click-events-have-key-events': 'error',
      'jsx-a11y/no-static-element-interactions': 'error',
      '@typescript-eslint/consistent-type-imports': ['error', { fixStyle: 'inline-type-imports' }],
      '@typescript-eslint/no-unused-vars': ['error', { argsIgnorePattern: '^_' }],
      'no-restricted-syntax': ['error', { selector: "Literal[value=/#[0-9a-fA-F]{6}\\b/]", message: 'Use design tokens instead of raw hex colours.' }],
    },
  },
  {
    files: ['packages/design-tokens/**', 'apps/web/app/layout.tsx', '**/*.test.tsx', '**/*.test.ts', 'apps/web/e2e/**'],
    rules: { 'no-restricted-syntax': 'off' },
  },
];

export default config;
