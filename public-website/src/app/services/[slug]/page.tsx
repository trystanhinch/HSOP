import Link from "next/link";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { fetchBrand, pageDescription, pageSeo, pageTitle } from "@/lib/brand";

type Props = { params: Promise<{ slug: string }> };

async function brand() {
  const h = await headers();
  return fetchBrand(h.get("x-forwarded-host") || h.get("host"));
}

function serviceCopy(
  service: { label: string; lede?: string | null; points?: string[] },
  company: string
) {
  return {
    lede:
      service.lede ||
      `${company} handles ${service.label.toLowerCase()} for homeowners who want the job finished properly — not patched and forgotten.`,
    points:
      service.points && service.points.length > 0
        ? service.points
        : [
            `Clear scope for ${service.label.toLowerCase()}`,
            "Ballpark range before a site visit",
            "Book a time online when you are ready",
          ],
  };
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const b = await brand();
  const service = b.service_categories.find((s) => s.key === slug);
  if (!service) return { title: b.company_name };
  const fallbackDescription = pageDescription(
    b,
    `${service.label} from ${b.company_name}. Get a range online and book a site visit.`
  );
  return pageSeo(
    b,
    `service:${service.key}`,
    pageTitle(b, service.label),
    fallbackDescription
  );
}

export default async function ServicePage({ params }: Props) {
  const { slug } = await params;
  const b = await brand();
  const service = b.service_categories.find((s) => s.key === slug);
  if (!service) notFound();

  const copy = serviceCopy(service, b.company_name);
  const homeLabel = b.content?.service?.home_label || "Home";
  const requestPrefix = b.content?.service?.request_prefix || "Request";
  const serviceImage = b.images?.services?.[service.key];

  return (
    <section className="service-hero">
      <p className="crumbs">
        <Link href="/">{homeLabel}</Link>
        {" / "}
        {service.label}
      </p>
      <h1>{service.label}</h1>
      {serviceImage?.url ? (
        // eslint-disable-next-line @next/next/no-img-element
        <img
          className="service-hero__image"
          src={serviceImage.url}
          alt={serviceImage.alt || service.label}
        />
      ) : null}
      <p className="lede">{copy.lede}</p>
      <ul
        style={{
          margin: "1.75rem 0 2rem",
          paddingLeft: "1.1rem",
          color: "var(--color-muted)",
          maxWidth: "36rem",
        }}
      >
        {copy.points.map((p) => (
          <li key={p} style={{ marginBottom: "0.4rem" }}>
            {p}
          </li>
        ))}
      </ul>
      <Link className="btn" href="/quote">
        {requestPrefix} {service.label}
      </Link>
    </section>
  );
}
