import type { NextConfig } from 'next';

const nextConfig: NextConfig = {
  reactStrictMode: true,
  transpilePackages: ['@eora/ui', '@eora/features', '@eora/design-tokens'],
  poweredByHeader: false,
};

export default nextConfig;
