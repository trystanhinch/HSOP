"use client";

import { FormEvent, useState } from "react";
import Link from "next/link";
import type { BrandConfig } from "@/lib/brand";
import { apiBaseUrl, brandHeaders } from "@/lib/brand";

type Props = {
  brand: BrandConfig;
  hostHint?: string;
};

export function ManualQuoteForm({ brand, hostHint }: Props) {
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [leadId, setLeadId] = useState<number | null>(null);
  const [form, setForm] = useState({
    contact_name: "",
    phone: "",
    email: "",
    address: "",
    service_category: brand.service_categories[0]?.key || "",
    project_description: "",
  });

  async function onSubmit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const start = await fetch(`${apiBaseUrl()}/api/public/intake/start`, {
        method: "POST",
        headers: brandHeaders(hostHint || brand.domain),
        credentials: "include",
      });
      if (!start.ok) throw new Error("Could not start request.");
      const session = await start.json();
      const token = session.session_token as string;

      if (form.project_description.trim()) {
        await fetch(`${apiBaseUrl()}/api/public/intake/message`, {
          method: "POST",
          headers: {
            ...brandHeaders(hostHint || brand.domain),
            "X-Intake-Token": token,
          },
          credentials: "include",
          body: JSON.stringify({
            session_token: token,
            message: form.project_description.trim(),
            stream: false,
          }),
        });
      }

      const submit = await fetch(`${apiBaseUrl()}/api/public/intake/submit`, {
        method: "POST",
        headers: {
          ...brandHeaders(hostHint || brand.domain),
          "X-Intake-Token": token,
        },
        credentials: "include",
        body: JSON.stringify({
          session_token: token,
          contact_name: form.contact_name.trim(),
          phone: form.phone.trim(),
          email: form.email.trim() || undefined,
          address: form.address.trim() || undefined,
          project_description: form.project_description.trim(),
          service_category: form.service_category || undefined,
        }),
      });
      const data = await submit.json();
      if (!submit.ok) {
        throw new Error(data.message || "Submit failed");
      }
      setLeadId(data.lead_id);
      window.localStorage.removeItem("serviceop_intake_token");
    } catch (err) {
      setError(err instanceof Error ? err.message : "Submit failed");
    } finally {
      setBusy(false);
    }
  }

  if (leadId) {
    return (
      <div className="manual-quote success-box">
        <p className="success">
          Request received{typeof leadId === "number" ? ` (#${leadId})` : ""}.{" "}
          {brand.company_name} will follow up shortly.
        </p>
        <Link href="/" className="btn">
          Back to home
        </Link>
      </div>
    );
  }

  return (
    <form className="manual-quote" onSubmit={onSubmit}>
      <label>
        Name
        <input
          required
          value={form.contact_name}
          onChange={(e) => setForm({ ...form, contact_name: e.target.value })}
        />
      </label>
      <label>
        Phone
        <input
          required
          value={form.phone}
          onChange={(e) => setForm({ ...form, phone: e.target.value })}
        />
      </label>
      <label>
        Email
        <input
          type="email"
          value={form.email}
          onChange={(e) => setForm({ ...form, email: e.target.value })}
        />
      </label>
      <label>
        Address / area
        <input
          value={form.address}
          onChange={(e) => setForm({ ...form, address: e.target.value })}
        />
      </label>
      {brand.service_categories.length > 0 ? (
        <label>
          Service
          <select
            value={form.service_category}
            onChange={(e) =>
              setForm({ ...form, service_category: e.target.value })
            }
          >
            {brand.service_categories.map((s) => (
              <option key={s.key} value={s.key}>
                {s.label}
              </option>
            ))}
          </select>
        </label>
      ) : null}
      <label>
        Describe the project
        <textarea
          required
          rows={4}
          value={form.project_description}
          onChange={(e) =>
            setForm({ ...form, project_description: e.target.value })
          }
        />
      </label>
      {error ? (
        <p className="error" role="alert">
          {error}
        </p>
      ) : null}
      <div className="manual-quote__actions">
        <button type="submit" className="btn" disabled={busy}>
          {busy ? "Submitting…" : "Submit request"}
        </button>
        <Link href="/quote">Prefer chat instead</Link>
      </div>
    </form>
  );
}
