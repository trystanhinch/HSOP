import Link from "next/link";
import { headers } from "next/headers";
import { fetchBrand, pageDescription, pageTitle } from "@/lib/brand";
import type { Metadata } from "next";

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
      `${b.company_name} serves homeowners with quality craftsmanship. Get a quote online.`
    ),
  };
}

export default async function HomePage() {
  const b = await brand();

  return (
    <main>
      <section className="hero">
        <h1>{b.company_name}</h1>
        <p>
          Tell us about your project — we&apos;ll guide you through a quick
          conversation and get your request to the right team.
        </p>
        <Link className="btn" href="/quote">
          Start a quote
        </Link>
      </section>

      <section className="service-grid" aria-label="Services">
        {b.service_categories.map((s) => (
          <Link key={s.key} href={`/services/${s.key}`} className="service-card">
            <h2>{s.label}</h2>
            <p className="muted">Learn more and request this service.</p>
          </Link>
        ))}
      </section>
    </main>
  );
}
