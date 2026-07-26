import Link from "next/link";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import type { Metadata } from "next";
import {
  fetchLocationPage,
  pageDescription,
  pageTitle,
  resolveOgImage,
} from "@/lib/brand";

type Props = { params: Promise<{ slug: string }> };

async function host() {
  const h = await headers();
  return h.get("x-forwarded-host") || h.get("host");
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  try {
    const { location, brand } = await fetchLocationPage(slug, await host());
    const title =
      location.seo_title ||
      pageTitle(
        brand,
        `${location.city_name}${location.region ? `, ${location.region}` : ""}`
      );
    const description =
      location.seo_description ||
      pageDescription(
        brand,
        `${brand.company_name} in ${location.city_name}. Request a quote online.`
      );
    const ogImage = resolveOgImage(brand);
    return {
      title,
      description,
      ...(ogImage ? { openGraph: { images: [ogImage] } } : {}),
    };
  } catch {
    return { title: "Service area" };
  }
}

export default async function LocationPage({ params }: Props) {
  const { slug } = await params;
  let payload;
  try {
    payload = await fetchLocationPage(slug, await host());
  } catch {
    notFound();
  }

  const { location, brand } = payload;
  const headline =
    (typeof location.content?.headline === "string" && location.content.headline) ||
    `${brand.company_name} in ${location.city_name}`;
  const body =
    (typeof location.content?.body === "string" && location.content.body) ||
    `${brand.company_name} serves homeowners in ${location.city_name}${
      location.region ? `, ${location.region}` : ""
    }. Tell us what you need and get a clear next step.`;
  const cta =
    (typeof location.content?.cta_label === "string" && location.content.cta_label) ||
    brand.content?.header?.quote_cta_label ||
    "Get a quote";

  return (
    <section className="section location-page">
      <p className="crumbs">
        <Link href="/locations">Service areas</Link>
        {" / "}
        {location.city_name}
      </p>
      <h1>{headline}</h1>
      <p className="lede" style={{ whiteSpace: "pre-wrap" }}>
        {body}
      </p>
      <div className="location-page__actions">
        <Link className="btn" href="/quote">
          {cta}
        </Link>
        {brand.service_categories[0] ? (
          <Link className="ghost" href={`/services/${brand.service_categories[0].key}`}>
            See {brand.service_categories[0].label}
          </Link>
        ) : null}
      </div>
    </section>
  );
}
