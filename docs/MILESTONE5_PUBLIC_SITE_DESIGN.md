# Public website design plan — Acutera (Milestone 5)

Subject: drywall / paint / insulation finishing. Audience: stressed homeowners
who need a mess fixed properly. Job of the page: competence + talk-to-us-now
in ~5 seconds, then into AI chat.

## 1. Color tokens (Acutera default)

| Token | Hex | Role |
|---|---|---|
| `--color-bg` | `#E8EAE4` | Cool plaster field (gypsum paper, not cream) |
| `--color-surface` | `#F7F8F5` | Finished wall / panels |
| `--color-ink` | `#1A211C` | Charcoal with green cast (sealed trim) |
| `--color-muted` | `#5A635C` | Secondary text |
| `--color-accent` | `#2C4A3E` | Deep workshop green (paint-shop shelves, not teal SaaS) |
| `--color-mud` | `#B8956C` | Joint-compound / sanded mud (warm, not terracotta) |
| `--color-line` | `#CFD4CC` | Quiet edges |

**Why this (not generic):** Avoids cream+serif+terracotta, neon-on-black, and
blue/orange contractor kits. Palette reads as finishing materials —
plaster, mud, sealed green trim — specific to drywall/paint shops.

## 2. Type

- **Display:** Fraunces (soft optical sizing → “hand-finished,” not tech)
- **Body:** Outfit (clean geometric sans; not Inter/Roboto/system)
- Scale: display `clamp(2.6rem, 7vw, 4.25rem)` · h1 `2rem` · h2 `1.35rem` ·
  body `1.0625rem/1.55` · small `0.875rem`

## 3. Layout concept

One composition per first viewport: brand as hero signal, one headline,
one supporting line, one CTA group, finish-reveal visual. No card grids
in the hero. Services below as a quiet linked list. Quote page embeds
chat as the main stage (not a corner widget).

```
HOME
+------------------------------------------+
| ACUERA          services…    [Get quote] |
+-------------------+----------------------+
| UNFINISHED MUD    | FINISHED WALL        |
| texture half      | Brand + headline     |
|                   | one sentence + CTA   |
+-------------------+----------------------+
| What we fix — service links (no cards)   |
| 1 Describe  2 Range  3 Book (sequence)   |
+------------------------------------------+

SERVICE /:slug
+------------------------------------------+
| crumb · Service label (display)          |
| homeowner-side lede · CTA to /quote      |
+------------------------------------------+

QUOTE
+------------------------------------------+
| Talk through the fix (display)           |
| Chat stage on surface (full width)       |
| estimate = finish card (signature)       |
+------------------------------------------+
```

## 4. Signature element — “The Finish Reveal”

Hero splits unfinished mud texture → clean finished wall; CTA lives on
the finished side. In chat, the price estimate uses the same language: a
quiet “finish card” that reveals the range like lifting a drop cloth.
One memorable idea; everything else stays disciplined.

## Self-review revisions

- First pass leaned “green trust + white cards” (any contractor). Changed
  to plaster/mud material language and a literal before→after reveal so
  the mark is finishing work, not “home services SaaS.”
- Dropped card grid for services (generic dashboard feel) in favor of a
  quiet linked list.
- Kept 1–2–3 only for the real quote sequence (describe → range → book).
