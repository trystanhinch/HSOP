import type { BrandConfig } from "@/lib/brand";
import { googleFontsHref, resolveTheme, themeToCssVars } from "@/lib/theme";

/**
 * Applies brand theme tokens as CSS custom properties.
 * Components must consume var(--color-*) / var(--font-*), never hardcoded hex/fonts.
 */
export function BrandTheme({ brand }: { brand: BrandConfig }) {
  const theme = resolveTheme(brand);
  const vars = themeToCssVars(theme);
  const css = `:root{${Object.entries(vars)
    .map(([k, v]) => `${k}:${v}`)
    .join(";")}}`;

  return (
    <>
      <link rel="preconnect" href="https://fonts.googleapis.com" />
      <link rel="preconnect" href="https://fonts.gstatic.com" crossOrigin="anonymous" />
      <link rel="stylesheet" href={googleFontsHref(theme)} />
      <style dangerouslySetInnerHTML={{ __html: css }} />
    </>
  );
}
