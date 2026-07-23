/** @type {import('next').NextConfig} */
const nextConfig = {
  // Public multi-tenant site — talks to Laravel /api/public/*
  async rewrites() {
    return [];
  },
};

export default nextConfig;
