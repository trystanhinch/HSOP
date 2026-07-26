import Link from "next/link";
import type { BrandConfig } from "@/lib/brand";

export function SiteFooter({ brand }: { brand: BrandConfig }) {
  const phone =
    (typeof brand.contact_info?.phone === "string" && brand.contact_info.phone) || null;
  const email =
    (typeof brand.contact_info?.email === "string" && brand.contact_info.email) || null;
  const licensed = Boolean(brand.branding?.licensed ?? brand.contact_info?.licensed);
  const insured = Boolean(brand.branding?.insured ?? brand.contact_info?.insured);
  const fallbackLabel = brand.content?.footer?.fallback_label || "Local finishing crew";
  const locations = brand.locations || [];
  const pages = brand.pages || [];
  const quoteLabel = brand.content?.header?.quote_cta_label || "Get a quote";

  return (
    <footer className="site-footer">
      <div className="site-footer-inner">
        <div>
          <strong style={{ color: "var(--color-ink)", fontFamily: "var(--font-display)" }}>
            {brand.company_name}
          </strong>
          <p className="muted" style={{ margin: "0.35rem 0 0" }}>
            {[licensed && "Licensed", insured && "Insured"].filter(Boolean).join(" · ") ||
              fallbackLabel}
          </p>
          <p style={{ margin: "0.75rem 0 0" }}>
            <Link href="/quote">{quoteLabel}</Link>
          </p>
        </div>
        <div>
          {phone ? (
            <a href={`tel:${phone.replace(/[^\d+]/g, "")}`}>{phone}</a>
          ) : null}
          {email ? (
            <div>
              <a href={`mailto:${email}`}>{email}</a>
            </div>
          ) : null}
        </div>
        {locations.length > 0 ? (
          <div>
            <strong style={{ color: "var(--color-ink)" }}>Service areas</strong>
            <ul className="footer-link-list">
              {locations.map((loc) => (
                <li key={loc.slug}>
                  <Link href={`/locations/${loc.slug}`}>
                    {loc.city_name}
                    {loc.region ? `, ${loc.region}` : ""}
                  </Link>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
        {pages.length > 0 ? (
          <div>
            <strong style={{ color: "var(--color-ink)" }}>More</strong>
            <ul className="footer-link-list">
              {pages.map((page) => (
                <li key={page.slug}>
                  <Link href={`/pages/${page.slug}`}>{page.title}</Link>
                </li>
              ))}
            </ul>
          </div>
        ) : null}
      </div>
    </footer>
  );
}
