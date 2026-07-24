export type BrandTheme = {
  color_bg: string;
  color_surface: string;
  color_ink: string;
  color_muted: string;
  color_accent: string;
  color_mud: string;
  color_line: string;
  color_danger: string;
  color_ok: string;
  font_display: string;
  font_body: string;
};

/** Acutera default — plaster / mud / workshop green (finishing trades). */
export const ACUTERA_THEME: BrandTheme = {
  color_bg: "#E8EAE4",
  color_surface: "#F7F8F5",
  color_ink: "#1A211C",
  color_muted: "#5A635C",
  color_accent: "#2C4A3E",
  color_mud: "#B8956C",
  color_line: "#CFD4CC",
  color_danger: "#8F2D2D",
  color_ok: "#2F5D3A",
  font_display: "Fraunces",
  font_body: "Outfit",
};

/** Example alternate brand — slate roof / asphalt (second-brand visual proof). */
export const ROOFING_THEME: BrandTheme = {
  color_bg: "#E6E9EE",
  color_surface: "#F4F6F8",
  color_ink: "#1B2430",
  color_muted: "#5B6675",
  color_accent: "#2E4057",
  color_mud: "#8B7355",
  color_line: "#C5CCD6",
  color_danger: "#8F2D2D",
  color_ok: "#2F5D3A",
  font_display: "Libre Baskerville",
  font_body: "Source Sans 3",
};

type BrandLike = {
  domain?: string;
  branding?: {
    primary_color?: string | null;
    theme?: Partial<BrandTheme> | null;
    [key: string]: unknown;
  };
};

export function resolveTheme(brand: BrandLike | null | undefined): BrandTheme {
  const domain = (brand?.domain || "").toLowerCase();
  const base =
    domain.includes("roof") || domain.includes("example-roof")
      ? ROOFING_THEME
      : ACUTERA_THEME;

  const fromBrand = brand?.branding?.theme || {};
  const merged: BrandTheme = {
    ...base,
    ...Object.fromEntries(
      Object.entries(fromBrand).filter(([, v]) => typeof v === "string" && v)
    ),
  };

  // Legacy single primary_color still wins for accent if theme didn't set one explicitly
  if (
    brand?.branding?.primary_color &&
    !(fromBrand as Partial<BrandTheme>).color_accent
  ) {
    merged.color_accent = brand.branding.primary_color;
  }

  return merged;
}

export function themeToCssVars(theme: BrandTheme): Record<string, string> {
  return {
    "--color-bg": theme.color_bg,
    "--color-surface": theme.color_surface,
    "--color-ink": theme.color_ink,
    "--color-muted": theme.color_muted,
    "--color-accent": theme.color_accent,
    "--color-mud": theme.color_mud,
    "--color-line": theme.color_line,
    "--color-danger": theme.color_danger,
    "--color-ok": theme.color_ok,
    "--font-display": `"${theme.font_display}", Georgia, "Times New Roman", serif`,
    "--font-body": `"${theme.font_body}", "Segoe UI", system-ui, sans-serif`,
  };
}

export function googleFontsHref(theme: BrandTheme): string {
  const families = [theme.font_display, theme.font_body]
    .filter(Boolean)
    .map((name) => `family=${encodeURIComponent(name).replace(/%20/g, "+")}:wght@400;500;600;700`)
    .join("&");
  return `https://fonts.googleapis.com/css2?${families}&display=swap`;
}
