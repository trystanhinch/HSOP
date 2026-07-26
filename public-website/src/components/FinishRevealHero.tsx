import Link from "next/link";
import type { BrandConfig } from "@/lib/brand";

type Props = {
  brand: BrandConfig;
  headline?: string;
  lede?: string;
  ctaLabel?: string;
  showServicesLink?: boolean;
};

function copy(brand: BrandConfig) {
  const branding = brand.branding || {};
  const services = brand.service_categories.map((s) => s.label.toLowerCase()).join(", ");
  return {
    headline:
      (typeof branding.hero_headline === "string" && branding.hero_headline) ||
      "Get the ceiling fixed right.",
    lede:
      (typeof branding.hero_lede === "string" && branding.hero_lede) ||
      `Tell us what went wrong — popcorn texture, water stains, open walls — and ${brand.company_name} will walk you through a clear next step.`,
    cta: (typeof branding.cta_label === "string" && branding.cta_label) || "Start a quote",
    servicesIntro:
      (typeof branding.services_intro === "string" && branding.services_intro) ||
      (services
        ? `We handle ${services}. Pick what you need, or jump straight into chat.`
        : "Pick a service, or jump straight into chat."),
  };
}

export function FinishRevealHero({
  brand,
  headline,
  lede,
  ctaLabel,
  showServicesLink = true,
}: Props) {
  const c = copy(brand);
  const shortName =
    (typeof brand.branding?.short_name === "string" && brand.branding.short_name) ||
    brand.company_name;
  const hero = brand.images?.hero_image;
  const hasPhoto = Boolean(hero?.url);

  return (
    <section className="finish-reveal" aria-label="Introduction">
      <div
        className={`finish-reveal__before${hasPhoto ? " finish-reveal__before--photo" : ""}`}
        aria-hidden={hasPhoto ? undefined : true}
      >
        {hasPhoto && hero ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            className="finish-reveal__photo"
            src={hero.url}
            alt={hero.alt || `${shortName} project`}
          />
        ) : null}
      </div>
      <div className="finish-reveal__after">
        <p className="finish-reveal__eyebrow">{shortName}</p>
        <h1>{headline || c.headline}</h1>
        <p className="finish-reveal__lede">{lede || c.lede}</p>
        <div className="finish-reveal__actions">
          <Link className="btn" href="/quote">
            {ctaLabel || c.cta}
          </Link>
          {showServicesLink && brand.service_categories[0] ? (
            <Link className="ghost" href={`/services/${brand.service_categories[0].key}`}>
              See {brand.service_categories[0].label}
            </Link>
          ) : null}
        </div>
      </div>
    </section>
  );
}
