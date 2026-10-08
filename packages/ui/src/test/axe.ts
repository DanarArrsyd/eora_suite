import axe from 'axe-core';

/**
 * Run axe-core WCAG rules against a rendered container (jsdom).
 * Colour contrast is excluded here because jsdom has no layout/paint; contrast is verified in Playwright.
 */
export async function axeViolations(container: Element): Promise<string[]> {
  const result = await axe.run(container, {
    runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21a', 'wcag21aa', 'wcag22aa'] },
    rules: { 'color-contrast': { enabled: false }, region: { enabled: false } },
  });
  return result.violations.map((v) => `${v.id}: ${v.help} (${v.nodes.map((n) => n.target.join(' ')).join(', ')})`);
}
