import Link from "next/link";
import { headers } from "next/headers";
import type { Metadata } from "next";
import { FinishRevealHero } from "@/components/FinishRevealHero";
import { fetchBrand, pageDescription, pageSeo, pageTitle } from "@/lib/brand";

async function brand() {
  const h = await headers();
  return fetchBrand(h.get("x-forwarded-host") || h.get("host"));
}

export async function generateMetadata(): Promise<Metadata> {
  const b = await brand();
  return pageSeo(
    b,
    "home",
    pageTitle(b),
    pageDescription(
      b,
      `${b.company_name} fixes drywall, paint, and insulation problems for homeowners. Get a clear range online.`
    )
  );
}

export default async function HomePage() {
  const b = await brand();
  const licensed = Boolean(b.branding?.licensed ?? b.contact_info?.licensed ?? true);
  const insured = Boolean(b.branding?.insured ?? b.contact_info?.insured ?? true);
  const area =
    (typeof b.contact_info?.service_area === "string" && b.contact_info.service_area) ||
    (typeof b.branding?.service_area === "string" && b.branding.service_area) ||
    null;

  const servicesIntro =
    (typeof b.branding?.services_intro === "string" && b.branding.services_intro) ||
    `What ${b.company_name} fixes`;
  const homeContent = b.content?.home || {};
  const fallbackSteps = [
    {
      eyebrow: "1 — Describe",
      title: "Tell us what you see",
      description: "Ceiling stains, open walls, cold rooms — a short chat is enough to start.",
    },
    {
      eyebrow: "2 — Range",
      title: "Get a ballpark",
      description: "We show an estimate range from your details before anyone comes out.",
    },
    {
      eyebrow: "3 — Book",
      title: "Pick a visit time",
      description: "Hold a site-visit slot while you finish, or submit and we'll call you.",
    },
  ];
  const steps =
    Array.isArray(homeContent.steps) && homeContent.steps.length === 3
      ? homeContent.steps
      : fallbackSteps;

  return (
    <>
      <FinishRevealHero brand={b} />

      <section className="section" aria-labelledby="services-heading">
        <h2 id="services-heading" className="section-title">
          {servicesIntro}
        </h2>
        <ul className="service-list">
          {b.service_categories.map((s) => (
            <li key={s.key}>
              <Link href={`/services/${s.key}`}>
                <span>{s.label}</span>
                <span className="hint">{homeContent.details_label || "Details →"}</span>
              </Link>
            </li>
          ))}
        </ul>

        <div className="sequence" aria-label="How a quote works">
          {steps.map((step, index) => (
            <article key={index}>
              <p className="num">{step.eyebrow || fallbackSteps[index].eyebrow}</p>
              <h3>{step.title || fallbackSteps[index].title}</h3>
              <p>{step.description || fallbackSteps[index].description}</p>
            </article>
          ))}
        </div>

        <div className="trust-row">
          {licensed ? (
            <span>
              <strong>{homeContent.licensed_label || "Licensed crew"}</strong>
            </span>
          ) : null}
          {insured ? (
            <span>
              <strong>{homeContent.insured_label || "Insured work"}</strong>
            </span>
          ) : null}
          {area ? (
            <span>
              {homeContent.serving_prefix || "Serving"} <strong>{area}</strong>
            </span>
          ) : (
            <span>
              {homeContent.trust_fallback ||
                "Built for homeowners who want the mess finished clean"}
            </span>
          )}
          <Link href="/quote" className="btn" style={{ marginLeft: "auto" }}>
            {homeContent.bottom_cta_label || "Talk through your project"}
          </Link>
        </div>
      </section>
    </>
  );
}
