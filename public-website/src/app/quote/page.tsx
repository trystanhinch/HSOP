import { headers } from "next/headers";
import type { Metadata } from "next";
import { ChatWidget } from "@/components/ChatWidget";
import { fetchBrand, pageDescription, pageTitle } from "@/lib/brand";

async function brand() {
  const h = await headers();
  return fetchBrand(h.get("x-forwarded-host") || h.get("host"));
}

export async function generateMetadata(): Promise<Metadata> {
  const b = await brand();
  return {
    title: pageTitle(b, "Get a quote"),
    description: pageDescription(
      b,
      `Describe your project to ${b.company_name}, get a ballpark range, and book a site visit.`
    ),
  };
}

export default async function QuotePage() {
  const h = await headers();
  const host = h.get("x-forwarded-host") || h.get("host");
  const b = await brand();

  return (
    <div className="quote-stage">
      <div className="quote-stage__intro">
        <h1>Talk through the fix</h1>
        <p className="lede">
          Describe what you see — photos help. {b.company_name} will collect what
          we need, show a ballpark range, and let you hold a visit time.
        </p>
      </div>
      <ChatWidget brand={b} hostHint={host || b.domain} />
    </div>
  );
}
