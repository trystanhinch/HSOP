export type BrandService = {
  key: string;
  label: string;
  keywords: string[];
  lede?: string | null;
  points?: string[];
};

export type BrandPageSeo = {
  title?: string | null;
  description?: string | null;
  og_image?: string | null;
};

export type BrandImageSlot = {
  url: string;
  alt?: string | null;
};

export type BrandImages = {
  logo?: BrandImageSlot | null;
  hero_image?: BrandImageSlot | null;
  og_image?: BrandImageSlot | null;
  services?: Record<string, BrandImageSlot>;
};

export type BrandLocationSummary = {
  slug: string;
  city_name: string;
  region?: string | null;
};

export type BrandCustomPageSummary = {
  slug: string;
  title: string;
  template_type: string;
};

export type LocationPagePayload = {
  id: number;
  slug: string;
  city_name: string;
  region?: string | null;
  content: {
    headline?: string;
    body?: string;
    cta_label?: string;
    [key: string]: unknown;
  };
  seo_title?: string | null;
  seo_description?: string | null;
  status: string;
};

export type CustomPagePayload = {
  id: number;
  slug: string;
  title: string;
  template_type: "simple" | "home" | "service" | "quote" | string;
  content: Record<string, unknown>;
  seo_title?: string | null;
  seo_description?: string | null;
  og_image?: string | null;
  status: string;
  source_key?: string | null;
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
    short_name?: string;
    hero_headline?: string;
    hero_lede?: string;
    cta_label?: string;
    services_intro?: string;
    service_area?: string;
    licensed?: boolean;
    insured?: boolean;
    theme?: {
      color_bg?: string;
      color_surface?: string;
      color_ink?: string;
      color_muted?: string;
      color_accent?: string;
      color_mud?: string;
      color_line?: string;
      font_display?: string;
      font_body?: string;
      [key: string]: unknown;
    };
    [key: string]: unknown;
  };
  contact_info: {
    email?: string | null;
    phone?: string | null;
    service_area?: string;
    licensed?: boolean;
    insured?: boolean;
    [key: string]: unknown;
  };
  seo_defaults: {
    title_template?: string | null;
    description?: string | null;
    og_image?: string | null;
    [key: string]: unknown;
  };
  content: {
    header?: {
      quote_cta_label?: string;
    };
    home?: {
      details_label?: string;
      steps?: Array<{
        eyebrow?: string;
        title?: string;
        description?: string;
      }>;
      licensed_label?: string;
      insured_label?: string;
      serving_prefix?: string;
      trust_fallback?: string;
      bottom_cta_label?: string;
    };
    service?: {
      home_label?: string;
      request_prefix?: string;
    };
    quote?: {
      heading?: string;
      lede?: string;
    };
    footer?: {
      fallback_label?: string;
    };
    [key: string]: unknown;
  };
  images?: BrandImages;
  locations?: BrandLocationSummary[];
  pages?: BrandCustomPageSummary[];
  page_seo: Record<string, BrandPageSeo>;
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

export async function fetchLocationPage(
  slug: string,
  host?: string | null
): Promise<{ location: LocationPagePayload; brand: BrandConfig }> {
  const res = await fetch(
    `${apiBaseUrl()}/api/public/locations/${encodeURIComponent(slug)}`,
    {
      headers: brandHeaders(host),
      cache: "no-store",
    }
  );
  if (!res.ok) {
    throw new Error(`Location lookup failed (${res.status})`);
  }
  return (await res.json()) as { location: LocationPagePayload; brand: BrandConfig };
}

export async function fetchCustomPage(
  slug: string,
  host?: string | null
): Promise<{ page: CustomPagePayload; brand: BrandConfig }> {
  const res = await fetch(
    `${apiBaseUrl()}/api/public/pages/${encodeURIComponent(slug)}`,
    {
      headers: brandHeaders(host),
      cache: "no-store",
    }
  );
  if (!res.ok) {
    throw new Error(`Page lookup failed (${res.status})`);
  }
  return (await res.json()) as { page: CustomPagePayload; brand: BrandConfig };
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

export function renderBrandTemplate(value: string, brand: BrandConfig): string {
  return value
    .replace(/\{\{\s*company_name\s*\}\}/g, brand.company_name)
    .replace(/\{\{\s*domain\s*\}\}/g, brand.domain);
}

export function resolveOgImage(
  brand: BrandConfig,
  pageKey?: string,
  explicit?: string | null
): string | undefined {
  if (explicit) return explicit;
  const override = pageKey ? brand.page_seo?.[pageKey]?.og_image : null;
  if (override) return override;
  if (brand.images?.og_image?.url) return brand.images.og_image.url;
  if (brand.seo_defaults?.og_image) return brand.seo_defaults.og_image;
  return undefined;
}

export function pageSeo(
  brand: BrandConfig,
  pageKey: string,
  fallbackTitle: string,
  fallbackDescription: string
) {
  const override = brand.page_seo?.[pageKey];
  const title = override?.title
    ? renderBrandTemplate(override.title, brand)
    : fallbackTitle;
  const description = override?.description
    ? renderBrandTemplate(override.description, brand)
    : fallbackDescription;
  const ogImage = resolveOgImage(brand, pageKey);

  return {
    title,
    description,
    ...(ogImage
      ? { openGraph: { images: [ogImage] } }
      : {}),
  };
}
