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

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  const b = await brand();
  const service = b.service_categories.find((s) => s.key === slug);
  if (!service) return { title: b.company_name };
  return {
    title: pageTitle(b, service.label),
    description: pageDescription(
      b,
      `${service.label} from ${b.company_name}. Request a quote online.`
    ),
  };
}

export default async function ServicePage({ params }: Props) {
  const { slug } = await params;
  const b = await brand();
  const service = b.service_categories.find((s) => s.key === slug);
  if (!service) notFound();

  return (
    <main>
      <p className="muted">
        <Link href="/">Home</Link> / Services
      </p>
      <h1>{service.label}</h1>
      <p className="lede">
        {b.company_name} offers {service.label.toLowerCase()}. Share a few
        details in chat and we&apos;ll follow up with next steps.
      </p>
      <Link className="btn" href="/quote">
        Request {service.label}
      </Link>
    </main>
  );
}
