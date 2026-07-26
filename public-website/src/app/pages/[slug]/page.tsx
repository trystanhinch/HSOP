import Link from "next/link";
import { headers } from "next/headers";
import { notFound } from "next/navigation";
import type { Metadata } from "next";
import { Suspense } from "react";
import { ChatWidget } from "@/components/ChatWidget";
import { FinishRevealHero } from "@/components/FinishRevealHero";
import {
  fetchCustomPage,
  pageDescription,
  pageTitle,
  renderBrandTemplate,
  resolveOgImage,
  type BrandConfig,
  type CustomPagePayload,
} from "@/lib/brand";

type Props = { params: Promise<{ slug: string }> };

async function host() {
  const h = await headers();
  return h.get("x-forwarded-host") || h.get("host");
}

export async function generateMetadata({ params }: Props): Promise<Metadata> {
  const { slug } = await params;
  try {
    const { page, brand } = await fetchCustomPage(slug, await host());
    const title = page.seo_title || pageTitle(brand, page.title);
    const description =
      page.seo_description ||
      pageDescription(brand, `${page.title} — ${brand.company_name}`);
    const ogImage = resolveOgImage(brand, undefined, page.og_image);
    return {
      title,
      description,
      ...(ogImage ? { openGraph: { images: [ogImage] } } : {}),
    };
  } catch {
    return { title: "Page" };
  }
}

function serviceCopy(
  content: Record<string, unknown>,
  brand: BrandConfig
) {
  const label =
    (typeof content.label === "string" && content.label) ||
    (typeof content.title === "string" && content.title) ||
    "Service";
  const lede =
    (typeof content.lede === "string" && content.lede) ||
    `${brand.company_name} handles ${label.toLowerCase()} for homeowners who want the job finished properly.`;
  const pointsRaw = content.points;
  const points = Array.isArray(pointsRaw)
    ? pointsRaw.map((p) => String(p)).filter(Boolean)
    : [
        `Clear scope for ${label.toLowerCase()}`,
        "Ballpark range before a site visit",
        "Book a time online when you are ready",
      ];
  return { label, lede, points };
}

function renderByTemplate(
  page: CustomPagePayload,
  brand: BrandConfig,
  hostHint: string | null
) {
  const content = page.content || {};
  const quoteLabel =
    brand.content?.header?.quote_cta_label ||
    (typeof content.cta_label === "string" && content.cta_label) ||
    "Get a quote";

  if (page.template_type === "home") {
    const headline =
      (typeof content.headline === "string" && content.headline) || page.title;
    const lede =
      (typeof content.lede === "string" && content.lede) ||
      pageDescription(brand);
    return (
      <>
        <FinishRevealHero
          brand={brand}
          headline={headline}
          lede={lede}
          ctaLabel={
            (typeof content.cta_label === "string" && content.cta_label) ||
            undefined
          }
        />
        <section className="section">
          <p>
            <Link className="btn" href="/quote">
              {quoteLabel}
            </Link>
          </p>
        </section>
      </>
    );
  }

  if (page.template_type === "quote") {
    const heading =
      (typeof content.heading === "string" && content.heading) || page.title;
    const lede = renderBrandTemplate(
      (typeof content.lede === "string" && content.lede) ||
        "Describe what you see — photos help. {{company_name}} will collect what we need.",
      brand
    );
    return (
      <div className="quote-stage">
        <div className="quote-stage__intro">
          <h1>{heading}</h1>
          <p className="lede">{lede}</p>
        </div>
        <Suspense fallback={<p className="muted">Loading chat…</p>}>
          <ChatWidget brand={brand} hostHint={hostHint || brand.domain} />
        </Suspense>
      </div>
    );
  }

  if (page.template_type === "service") {
    const copy = serviceCopy(content, brand);
    const requestPrefix = brand.content?.service?.request_prefix || "Request";
    const serviceImage =
      (typeof content.service_key === "string" &&
        brand.images?.services?.[content.service_key]) ||
      null;

    return (
      <section className="service-hero">
        <p className="crumbs">
          <Link href="/">{brand.content?.service?.home_label || "Home"}</Link>
          {" / "}
          {copy.label}
        </p>
        <h1>{copy.label}</h1>
        {serviceImage?.url ? (
          // eslint-disable-next-line @next/next/no-img-element
          <img
            className="service-hero__image"
            src={serviceImage.url}
            alt={serviceImage.alt || copy.label}
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
          {requestPrefix} {copy.label}
        </Link>
      </section>
    );
  }

  // simple template
  const headline =
    (typeof content.headline === "string" && content.headline) || page.title;
  const body =
    (typeof content.body === "string" && content.body) ||
    (typeof content.lede === "string" && content.lede) ||
    "";

  return (
    <section className="section">
      <h1>{headline}</h1>
      {body ? (
        <p className="lede" style={{ whiteSpace: "pre-wrap" }}>
          {body}
        </p>
      ) : null}
      <p style={{ marginTop: "2rem" }}>
        <Link className="btn" href="/quote">
          {quoteLabel}
        </Link>
      </p>
    </section>
  );
}

export default async function CustomPage({ params }: Props) {
  const { slug } = await params;
  const hostHint = await host();
  let payload;
  try {
    payload = await fetchCustomPage(slug, hostHint);
  } catch {
    notFound();
  }

  return renderByTemplate(payload.page, payload.brand, hostHint);
}
