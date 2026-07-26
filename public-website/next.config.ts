/** @type {import('next').NextConfig} */
const nextConfig = {
  // Public multi-tenant site — talks to Laravel /api/public/*
  eslint: {
    // Lint locally when needed; App Platform production installs omit eslint.
    ignoreDuringBuilds: true,
  },
  async rewrites() {
    return [];
  },
};

export default nextConfig;
