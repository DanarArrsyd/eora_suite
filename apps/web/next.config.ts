import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  reactStrictMode: true,
  transpilePackages: ['@eora/ui', '@eora/features', '@eora/design-tokens', '@eora/api-client'],
  poweredByHeader: false,
  // `next dev` would otherwise write apps/web/AGENTS.md (repository agent rules live in the root files).
  agentRules: false,
  // ADR-0006: the browser must see ONE origin (session cookie + CSRF, no CORS). In local development Next.js proxies
  // the API paths to Laravel; in deployed environments the reverse proxy does the same path routing.
  async rewrites() {
    const api = process.env.EORA_API_ORIGIN ?? 'http://127.0.0.1:8000';

    return [
      { source: '/api/:path*', destination: `${api}/api/:path*` },
      { source: '/sanctum/:path*', destination: `${api}/sanctum/:path*` },
    ];
  },
};

export default nextConfig;
