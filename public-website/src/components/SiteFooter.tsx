import type { BrandConfig } from "@/lib/brand";

export function SiteFooter({ brand }: { brand: BrandConfig }) {
  const phone =
    (typeof brand.contact_info?.phone === "string" && brand.contact_info.phone) || null;
  const email =
    (typeof brand.contact_info?.email === "string" && brand.contact_info.email) || null;
  const licensed = Boolean(brand.branding?.licensed ?? brand.contact_info?.licensed);
  const insured = Boolean(brand.branding?.insured ?? brand.contact_info?.insured);

  return (
    <footer className="site-footer">
      <div className="site-footer-inner">
        <div>
          <strong style={{ color: "var(--color-ink)", fontFamily: "var(--font-display)" }}>
            {brand.company_name}
          </strong>
          <p className="muted" style={{ margin: "0.35rem 0 0" }}>
            {[licensed && "Licensed", insured && "Insured"].filter(Boolean).join(" · ") ||
              "Local finishing crew"}
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
      </div>
    </footer>
  );
}
