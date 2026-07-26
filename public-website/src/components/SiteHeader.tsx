import Link from "next/link";
import type { BrandConfig } from "@/lib/brand";

export function SiteHeader({ brand }: { brand: BrandConfig }) {
  const shortName =
    (typeof brand.branding?.short_name === "string" && brand.branding.short_name) ||
    brand.company_name.split(/[&|]/)[0].trim();
  const quoteLabel = brand.content?.header?.quote_cta_label || "Get a quote";
  const logo = brand.images?.logo;
  const hasLocations = (brand.locations?.length || 0) > 0;

  return (
    <header className="site-header">
      <Link href="/" className="brand-name">
        {logo?.url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            className="brand-logo"
            src={logo.url}
            alt={logo.alt || shortName}
          />
        ) : null}
        <span>{shortName}</span>
      </Link>
      <nav aria-label="Primary">
        {brand.service_categories.map((s) => (
          <Link key={s.key} href={`/services/${s.key}`}>
            {s.label}
          </Link>
        ))}
        {hasLocations ? (
          <Link href="/locations">Service areas</Link>
        ) : null}
        <Link href="/quote" className="cta">
          {quoteLabel}
        </Link>
      </nav>
    </header>
  );
}
