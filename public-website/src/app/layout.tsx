import type { Metadata } from "next";
import { headers } from "next/headers";
import "./globals.css";
import { SiteHeader } from "@/components/SiteHeader";
import {
  fetchBrand,
  pageDescription,
  pageTitle,
  type BrandConfig,
} from "@/lib/brand";

async function loadBrand(): Promise<BrandConfig> {
  const h = await headers();
  const host = h.get("x-forwarded-host") || h.get("host");
  return fetchBrand(host);
}

export async function generateMetadata(): Promise<Metadata> {
  try {
    const brand = await loadBrand();
    return {
      title: pageTitle(brand),
      description: pageDescription(brand),
    };
  } catch {
    return {
      title: "Home services",
      description: "Request a quote online.",
    };
  }
}

export default async function RootLayout({
  children,
}: Readonly<{
  children: React.ReactNode;
}>) {
  let brand: BrandConfig | null = null;
  let error: string | null = null;
  try {
    brand = await loadBrand();
  } catch (e) {
    error = e instanceof Error ? e.message : "Brand unavailable";
  }

  const accent = brand?.branding?.primary_color || "#0f766e";

  return (
    <html lang="en">
      <body style={{ ["--accent" as string]: accent }}>
        {brand ? <SiteHeader brand={brand} /> : null}
        {error ? (
          <main>
            <h1>Site unavailable</h1>
            <p className="lede">{error}. Check API_URL / brand domain mapping.</p>
          </main>
        ) : (
          children
        )}
      </body>
    </html>
  );
}
