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
      `Chat with ${b.company_name} to start your project request.`
    ),
  };
}

export default async function QuotePage() {
  const h = await headers();
  const host = h.get("x-forwarded-host") || h.get("host");
  const b = await brand();

  return (
    <main>
      <h1>Get a quote</h1>
      <p className="lede">
        Message {b.company_name} below. You can add photos and submit when
        you&apos;re ready.
      </p>
      <ChatWidget brand={b} hostHint={host || b.domain} />
    </main>
  );
}
