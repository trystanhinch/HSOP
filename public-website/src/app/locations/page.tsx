import Link from "next/link";
import { headers } from "next/headers";
import type { Metadata } from "next";
import { fetchBrand, pageDescription, pageTitle } from "@/lib/brand";

async function brand() {
  const h = await headers();
  return fetchBrand(h.get("x-forwarded-host") || h.get("host"));
}

export async function generateMetadata(): Promise<Metadata> {
  const b = await brand();
  return {
    title: pageTitle(b, "Service areas"),
    description: pageDescription(
      b,
      `Cities and regions served by ${b.company_name}.`
    ),
  };
}

export default async function LocationsIndexPage() {
  const b = await brand();
  const locations = b.locations || [];
  const quoteLabel = b.content?.header?.quote_cta_label || "Get a quote";

  return (
    <section className="section locations-index">
      <h1 className="section-title">Service areas</h1>
      <p className="lede">
        {locations.length > 0
          ? `${b.company_name} serves these communities. Pick yours for local details, or request a quote.`
          : `${b.company_name} serves homeowners across the area. Request a quote to confirm coverage.`}
      </p>

      {locations.length > 0 ? (
        <ul className="service-list">
          {locations.map((loc) => (
            <li key={loc.slug}>
              <Link href={`/locations/${loc.slug}`}>
                <span>
                  {loc.city_name}
                  {loc.region ? `, ${loc.region}` : ""}
                </span>
                <span className="hint">Details →</span>
              </Link>
            </li>
          ))}
        </ul>
      ) : null}

      <p style={{ marginTop: "2rem" }}>
        <Link className="btn" href="/quote">
          {quoteLabel}
        </Link>
      </p>
    </section>
  );
}
