import Link from "next/link";
import type { BrandConfig } from "@/lib/brand";

export function SiteHeader({ brand }: { brand: BrandConfig }) {
  const shortName =
    (typeof brand.branding?.short_name === "string" && brand.branding.short_name) ||
    brand.company_name.split(/[&|]/)[0].trim();

  return (
    <header className="site-header">
      <Link href="/" className="brand-name">
        {shortName}
      </Link>
      <nav aria-label="Primary">
        {brand.service_categories.map((s) => (
          <Link key={s.key} href={`/services/${s.key}`}>
            {s.label}
          </Link>
        ))}
        <Link href="/quote" className="cta">
          Get a quote
        </Link>
      </nav>
    </header>
  );
}
