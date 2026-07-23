import Link from "next/link";
import type { BrandConfig } from "@/lib/brand";

export function SiteHeader({ brand }: { brand: BrandConfig }) {
  return (
    <header className="site-header">
      <Link href="/" className="brand-name">
        {brand.company_name}
      </Link>
      <nav>
        <Link href="/">Home</Link>
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
