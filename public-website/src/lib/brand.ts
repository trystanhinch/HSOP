export type BrandService = {
  key: string;
  label: string;
  keywords: string[];
};

export type BrandConfig = {
  id: number;
  slug: string;
  domain: string;
  company_name: string;
  service_categories: BrandService[];
  branding: {
    tone?: string;
    primary_color?: string | null;
    logo_url?: string | null;
    [key: string]: unknown;
  };
  contact_info: {
    email?: string | null;
    phone?: string | null;
    [key: string]: unknown;
  };
  seo_defaults: {
    title_template?: string | null;
    description?: string | null;
    og_image?: string | null;
    [key: string]: unknown;
  };
};

export function apiBaseUrl(): string {
  return (
    process.env.API_URL ||
    process.env.NEXT_PUBLIC_API_URL ||
    "http://127.0.0.1:8000"
  ).replace(/\/$/, "");
}

export function brandHeaders(host?: string | null): HeadersInit {
  const headers: Record<string, string> = {
    Accept: "application/json",
    "Content-Type": "application/json",
  };
  const envOverride = (
    process.env.BRAND_DOMAIN ||
    process.env.NEXT_PUBLIC_BRAND_DOMAIN ||
    ""
  )
    .trim()
    .toLowerCase()
    .replace(/^https?:\/\//, "")
    .replace(/^www\./, "")
    .split("/")[0]
    .split(":")[0];

  const fromHost = (host || "")
    .trim()
    .toLowerCase()
    .replace(/^https?:\/\//, "")
    .replace(/^www\./, "")
    .split("/")[0]
    .split(":")[0];

  const domain = envOverride || fromHost;
  const isLoopback = ["localhost", "127.0.0.1", "::1"].includes(domain);

  // Loopback → omit header so Laravel uses PUBLIC_LOCAL_DEFAULT_BRAND_DOMAIN.
  // Real/test brand hosts (incl. *.test) are sent through.
  if (domain && !isLoopback) {
    headers["X-Brand-Domain"] = domain;
  }
  return headers;
}

export async function fetchBrand(host?: string | null): Promise<BrandConfig> {
  const res = await fetch(`${apiBaseUrl()}/api/public/brand`, {
    headers: brandHeaders(host),
    cache: "no-store",
  });
  if (!res.ok) {
    throw new Error(`Brand lookup failed (${res.status})`);
  }
  const json = (await res.json()) as { brand: BrandConfig };
  return json.brand;
}

export function pageTitle(
  brand: BrandConfig,
  pageLabel?: string
): string {
  const template =
    brand.seo_defaults?.title_template || "{{company_name}} | Home Services";
  const base = template.replace(/\{\{\s*company_name\s*\}\}/g, brand.company_name);
  if (!pageLabel) return base;
  return `${pageLabel} | ${brand.company_name}`;
}

export function pageDescription(brand: BrandConfig, fallback?: string): string {
  return (
    brand.seo_defaults?.description ||
    fallback ||
    `${brand.company_name} — request a quote online.`
  );
}
