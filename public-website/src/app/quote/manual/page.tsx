import { headers } from "next/headers";
import type { Metadata } from "next";
import Link from "next/link";
import { ManualQuoteForm } from "@/components/ManualQuoteForm";
import { fetchBrand, pageDescription, pageSeo, pageTitle } from "@/lib/brand";

async function brand() {
  const h = await headers();
  return fetchBrand(h.get("x-forwarded-host") || h.get("host"));
}

export async function generateMetadata(): Promise<Metadata> {
  const b = await brand();
  return pageSeo(
    b,
    "quote",
    pageTitle(b, "Request a quote"),
    pageDescription(b, `Submit a structured quote request to ${b.company_name}.`)
  );
}

export default async function ManualQuotePage() {
  const h = await headers();
  const host = h.get("x-forwarded-host") || h.get("host");
  const b = await brand();

  return (
    <div className="quote-stage">
      <div className="quote-stage__intro">
        <p className="crumbs">
          <Link href="/">Home</Link> / <Link href="/quote">Quote</Link> / Manual
        </p>
        <h1>Request a quote manually</h1>
        <p className="lede">
          Prefer a form? Send the same details {b.company_name} needs — no chat
          required.
        </p>
      </div>
      <ManualQuoteForm brand={b} hostHint={host || b.domain} />
    </div>
  );
}
