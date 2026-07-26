import { headers } from "next/headers";
import type { Metadata } from "next";
import { ChatWidget } from "@/components/ChatWidget";
import {
  fetchBrand,
  pageDescription,
  pageSeo,
  pageTitle,
  renderBrandTemplate,
} from "@/lib/brand";

async function brand() {
  const h = await headers();
  return fetchBrand(h.get("x-forwarded-host") || h.get("host"));
}

export async function generateMetadata(): Promise<Metadata> {
  const b = await brand();
  return pageSeo(
    b,
    "quote",
    pageTitle(b, "Get a quote"),
    pageDescription(
      b,
      `Describe your project to ${b.company_name}, get a ballpark range, and book a site visit.`
    )
  );
}

export default async function QuotePage() {
  const h = await headers();
  const host = h.get("x-forwarded-host") || h.get("host");
  const b = await brand();
  const heading = b.content?.quote?.heading || "Talk through the fix";
  const lede = renderBrandTemplate(
    b.content?.quote?.lede ||
      "Describe what you see — photos help. {{company_name}} will collect what we need, show a ballpark range, and let you hold a visit time.",
    b
  );

  return (
    <div className="quote-stage">
      <div className="quote-stage__intro quote-stage__intro--compact">
        <h1>{heading}</h1>
        <p className="lede">{lede}</p>
      </div>
      <ChatWidget brand={b} hostHint={host || b.domain} />
    </div>
  );
}
