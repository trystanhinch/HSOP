import Link from "next/link";
import { headers } from "next/headers";
import type { Metadata } from "next";
import { FinishRevealHero } from "@/components/FinishRevealHero";
import { fetchBrand, pageDescription, pageTitle } from "@/lib/brand";

async function brand() {
  const h = await headers();
  return fetchBrand(h.get("x-forwarded-host") || h.get("host"));
}

export async function generateMetadata(): Promise<Metadata> {
  const b = await brand();
  return {
    title: pageTitle(b),
    description: pageDescription(
      b,
      `${b.company_name} fixes drywall, paint, and insulation problems for homeowners. Get a clear range online.`
    ),
  };
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
                <span className="hint">Details →</span>
              </Link>
            </li>
          ))}
        </ul>

        <div className="sequence" aria-label="How a quote works">
          <article>
            <p className="num">1 — Describe</p>
            <h3>Tell us what you see</h3>
            <p>Ceiling stains, open walls, cold rooms — a short chat is enough to start.</p>
          </article>
          <article>
            <p className="num">2 — Range</p>
            <h3>Get a ballpark</h3>
            <p>We show an estimate range from your details before anyone comes out.</p>
          </article>
          <article>
            <p className="num">3 — Book</p>
            <h3>Pick a visit time</h3>
            <p>Hold a site-visit slot while you finish, or submit and we&apos;ll call you.</p>
          </article>
        </div>

        <div className="trust-row">
          {licensed ? (
            <span>
              <strong>Licensed</strong> crew
            </span>
          ) : null}
          {insured ? (
            <span>
              <strong>Insured</strong> work
            </span>
          ) : null}
          {area ? (
            <span>
              Serving <strong>{area}</strong>
            </span>
          ) : (
            <span>
              Built for homeowners who want the mess <strong>finished clean</strong>
            </span>
          )}
          <Link href="/quote" className="btn" style={{ marginLeft: "auto" }}>
            Talk through your project
          </Link>
        </div>
      </section>
    </>
  );
}
