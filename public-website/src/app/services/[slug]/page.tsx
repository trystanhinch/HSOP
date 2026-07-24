import Link from "next/link";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { fetchBrand, pageDescription, pageTitle } from "@/lib/brand";

type Props = { params: Promise<{ slug: string }> };

async function brand() {
  const h = await headers();
  return fetchBrand(h.get("x-forwarded-host") || h.get("host"));
}

const SERVICE_COPY: Record<string, { lede: string; points: string[] }> = {
  drywall_paint: {
    lede: "Holes, seams, water damage, popcorn texture — we cut, mud, sand, and paint so the repair disappears into the wall.",
    points: [
      "Patch and full-panel drywall repairs",
      "Ceiling texture removal and smooth finish",
      "Interior paint that matches the surrounding wall",
    ],
  },
  insulation: {
    lede: "Cold rooms and drafts usually mean missing or settled insulation. We open up what we need to, install properly, and close the wall back clean.",
    points: [
      "Attic and wall insulation upgrades",
      "Batt and blown-in options where they fit",
      "Finished so you are not left looking at open cavities",
    ],
  },
};

function serviceCopy(key: string, label: string, company: string) {
  return (
    SERVICE_COPY[key] || {
      lede: `${company} handles ${label.toLowerCase()} for homeowners who want the job finished properly — not patched and forgotten.`,
      points: [
        `Clear scope for ${label.toLowerCase()}`,
        "Ballpark range before a site visit",
        "Book a time online when you are ready",
      ],
    }
  );
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const b = await brand();
  const service = b.service_categories.find((s) => s.key === slug);
  if (!service) return { title: b.company_name };
  return {
    title: pageTitle(b, service.label),
    description: pageDescription(
      b,
      `${service.label} from ${b.company_name}. Get a range online and book a site visit.`
    ),
  };
}

export default async function ServicePage({ params }: Props) {
  const { slug } = await params;
  const b = await brand();
  const service = b.service_categories.find((s) => s.key === slug);
  if (!service) notFound();

  const copy = serviceCopy(service.key, service.label, b.company_name);

  return (
    <section className="service-hero">
      <p className="crumbs">
        <Link href="/">Home</Link>
        {" / "}
        {service.label}
      </p>
      <h1>{service.label}</h1>
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
        Request {service.label}
      </Link>
    </section>
  );
}
