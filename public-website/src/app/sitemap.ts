import type { MetadataRoute } from "next";
import { headers } from "next/headers";
import { apiBaseUrl, brandHeaders } from "@/lib/brand";

type SitemapUrl = { path: string };

export default async function sitemap(): Promise<MetadataRoute.Sitemap> {
  const h = await headers();
  const host = h.get("x-forwarded-host") || h.get("host") || "localhost:3000";
  const protocol = host.includes("localhost") || host.startsWith("127.")
    ? "http"
    : "https";
  const origin = `${protocol}://${host}`;

  try {
    const res = await fetch(`${apiBaseUrl()}/api/public/sitemap`, {
      headers: brandHeaders(host),
      cache: "no-store",
    });
    if (!res.ok) {
      return [{ url: origin, lastModified: new Date() }];
    }
    const json = (await res.json()) as { urls?: SitemapUrl[] };
    const urls = Array.isArray(json.urls) ? json.urls : [];
    return urls.map((entry) => ({
      url: `${origin}${entry.path.startsWith("/") ? entry.path : `/${entry.path}`}`,
      lastModified: new Date(),
    }));
  } catch {
    return [{ url: origin, lastModified: new Date() }];
  }
}
