# HUD Frontend Redesign — Design Spec
**Date:** 2026-04-29  
**Scope:** `session.php` frontend only — no PHP, no backend, no DB changes  
**Style:** Dark Racing HUD — black base, neon cyan/red/green accents  
**Approach:** Full HUD Redesign (Option B) — new navbar, animated arc gauges, all panels restyled, zero library upgrades

---

## 1. Overview

Open Torque Viewer's main session page (`session.php`) gets a complete visual overhaul into a dark racing HUD aesthetic. The map stays full-screen. A slim neon navbar replaces the Bootstrap dark bar. A frosted-glass HUD widget with animated SVG arc gauges is pinned to the top-left of the map. All floating panels are restyled to match. The chart strip goes deep black with a glowing cyan line. Nothing in the PHP, database, or JS logic changes — only CSS and a small amount of new JS for gauge animation.

---

## 2. Design Tokens

New CSS custom properties defined in `static/css/hud.css` on `:root`:

```css
--hud-bg:         #060912;   /* panel/navbar background */
--hud-bg-map:     #0a0e1a;   /* body/map background */
--hud-cyan:       #00d4ff;   /* primary accent */
--hud-red:        #ff6b6b;   /* temperature / warning accent */
--hud-green:      #00ff88;   /* speed / healthy accent */
--hud-border:     rgba(0, 212, 255, 0.2);
--hud-glow-cyan:  0 0 12px rgba(0, 212, 255, 0.4);
--navbar-height:  46px;      /* reduced from 58px */
```

---

## 3. File Changes

| File | Change |
|------|--------|
| `static/css/hud.css` | **New file** — all HUD overrides, loaded after `torque.css` |
| `static/js/torquehelpers.js` | Add gauge animation logic (`_updateGauges`, `_initGauges`) |
| `session.php` | Add `<link>` for `hud.css`; inject HUD widget HTML; update navbar markup |

`torque.css` is **not modified** — `hud.css` overrides on top via higher specificity or later cascade position.

---

## 4. Navbar

**Height:** 46px (down from 58px) — more map visible.

**Background:** `--hud-bg` (`#060912`) with a `1px` bottom border in `--hud-border`.

**Brand:** "⬡ TORQUE" in `--hud-cyan`, `font-weight: 800`, `letter-spacing: 3px`, `text-transform: uppercase`, `text-shadow: 0 0 12px rgba(0,212,255,0.6)`.

**Session / Profile pickers:** Tom Select instances reskinned as dark outlined pills:
- Background: `rgba(0,212,255,0.06)`
- Border: `--hud-border`
- Text: `--hud-cyan` for active value, `#8ab` for placeholder
- Dropdown: `--hud-bg` background, cyan highlight on hover

**Icon buttons** (Variables, Chart, Summary, Export, AI, Settings, Dark mode):
- 28×28px, `border-radius: 4px`, border `--hud-border`, icon colour `#445` (dim)
- **Active state:** border `--hud-cyan`, icon `--hud-cyan`, `box-shadow: --hud-glow-cyan`

**Merge / Delete buttons:** Styled as small outlined buttons matching the neon scheme (outline-cyan for merge, outline-red for delete).

---

## 5. HUD Widget

A persistent frosted-glass overlay pinned `top: 12px; left: 12px` on the map canvas. Always visible when a session is loaded.

**Container:**
```css
background: rgba(6, 9, 18, 0.82);
border: 1px solid rgba(0, 212, 255, 0.22);
border-radius: 10px;
backdrop-filter: blur(8px);
box-shadow: 0 0 24px rgba(0,212,255,0.08), 0 4px 20px rgba(0,0,0,0.6);
padding: 12px 14px;
min-width: 220px;
```

### 5.1 Animated Arc Gauges

Three SVG arc gauges in a row: **RPM** (cyan), **Coolant Temp** (red), **Speed** (green).

Each gauge is a `70×50` SVG with two `<path>` elements:
- **Track:** low-opacity arc (`stroke-opacity: 0.12`)
- **Fill:** animated arc with `stroke-dasharray` + `stroke-dashoffset`, `filter: drop-shadow(0 0 4px [colour])`

**`stroke-dashoffset` mapping:** Full arc = 94 units (semicircle circumference for r=30). Value maps linearly from min→max of each PID's expected range:
- RPM: 0–8000
- Coolant: 40–120°C; turns orange >95°C, red >105°C via JS class swap
- Speed: 0–`_maxSpeed` (from PHP)

**On page load:** Gauges animate from `dashoffset=94` (empty) to session-average value over 1.2s `ease-out` via CSS transition on `stroke-dashoffset`.

**On chart hover:** `_updateGauges(tsMs)` reads nearest datapoint from the RPM, coolant, and speed datasets (by k-code: `kc`, `k5`, `kd`/`kff1001`) and tweens `stroke-dashoffset` via `requestAnimationFrame` at 60fps. If a gauge's k-code is not in the currently plotted datasets, that gauge displays a static `—` and its arc stays at zero.

**On chart mouseleave:** Gauges animate back to session-average over 0.6s. Session-average values for gauges are pulled from the plotted dataset's `.data` array mean, computed once after chart creation.

### 5.2 Session Stats Row

Below the gauges, separated by a `1px rgba(0,212,255,0.1)` border:

| Stat | Source | Colour |
|------|--------|--------|
| Duration | `(timeend - timestart) / 60` seconds → minutes, from `$seshdates` session metadata | `--hud-cyan` |
| Distance | Haversine sum over `_routeData` points computed in JS on page load | `#8ab` |
| Fuel/100km | Average of `kff5203` from plotted datasets (if plotted), else `—` | `--hud-green` |

Stats are shown as `font-size: 9px` labelled values. If data is unavailable, the slot shows `—`.

---

## 6. Map

No changes to Mapbox logic, route drawing, or crosshair sync.

**Visual changes only:**
- **Route glow:** Mapbox GL uses WebGL — CSS `filter` on the canvas element glows the entire map, not just the route. Instead, increase the existing route line's `line-blur` paint property to `2` via `map.setPaintProperty('route', 'line-blur', 2)` after the route is drawn. This gives a soft glow effect within Mapbox's render pipeline.
- **Map dot (chart hover):** Existing blue dot gets a CSS `@keyframes pulse` animation — a cyan ring that expands and fades every 1.5s.
- **Speed legend:** Restyled with `--hud-bg` background, `--hud-border` border, `#8ab` label text, matching the HUD widget aesthetic.
- **No-GPS / no-token overlays:** Restyled from white to `--hud-bg` glass with cyan icon.

---

## 7. Chart Strip

**Background:** `--hud-bg` (`#060912`), border-top `--hud-border`.

**Panel header:** "CHART" label in `--hud-cyan`, `letter-spacing: 2px`, `font-size: 9px`. Reset zoom button in `--hud-cyan` outline style.

**Chart.js dataset colours:** First dataset = `--hud-cyan`, second = `--hud-red`, third = `--hud-green`, additional = cycling through `#f4a261`, `#9b5de5`, `#00b4d8`.

**Canvas glow:** `filter: drop-shadow(0 0 4px rgba(0,212,255,0.3))` on `#chartCanvas`.

**Area fill:** Each dataset gets `fill: true` with a gradient from `rgba([colour], 0.25)` → `rgba([colour], 0)`.

**Crosshair:** Stays red (`rgba(239,68,68,0.75)`) — more visible on dark background.

**Tooltip:** Dark background `--hud-bg`, cyan border, monospace values.

---

## 8. Floating Panels (all)

All panels share the same updated base style in `hud.css`:

```css
.torque-panel {
  background: var(--hud-bg);
  border: 1px solid var(--hud-border);
  border-radius: 10px;
  box-shadow: 0 0 20px rgba(0,212,255,0.06), 0 4px 24px rgba(0,0,0,0.7);
}
.torque-panel-header {
  background: #07101f;
  border-bottom: 1px solid rgba(0,212,255,0.12);
}
.torque-panel-header h6 {
  color: var(--hud-cyan);
  font-size: 10px;
  letter-spacing: 2px;
  text-transform: uppercase;
}
```

**Panel open/close transition:** Add `opacity` + `transform: translateY(6px)` at `display:none` equivalent — implemented via a `.torque-panel--hidden` class toggled by `torqueToggle()` instead of `style.display`, with a 150ms ease transition.

### 8.1 Variables Panel
- Tom Select chips: each selected variable coloured to match its chart line (first=cyan, second=red, third=green, etc.) using a colour-indexed CSS class applied on chip creation.
- "Plot!" button: solid `--hud-cyan` background, black text, `font-weight: 800`, `letter-spacing: 1px`.
- "Show only variables with data" checkbox: `accent-color: var(--hud-cyan)`.

### 8.2 Data Summary Panel
- Variable name column: coloured glow dot (`box-shadow: 0 0 5px [colour]`) matching chart line.
- Mean value column: rendered in the variable's neon colour, `font-family: monospace`.
- Sparkline column: Peity sparklines recoloured to match the variable's neon colour.
- Table: no Bootstrap `table-striped` — replaced with a subtle `rgba(255,255,255,0.02)` odd-row background.

### 8.3 Export Panel
- Two buttons (CSV, JSON) as tall outlined cards with a large download icon, `--hud-border` border, `--hud-cyan` icon colour.

### 8.4 AI Chat Panel (TorqueAI)
- Header: robot icon + "TORQUE**AI**" (AI in `--hud-red`), green "ONLINE" badge.
- User messages: `rgba(0,212,255,0.2)` background, `--hud-cyan` text.
- AI messages: `rgba(255,255,255,0.04)` background, `#8ab` text.
- Suggestion pills: `--hud-border` border, `--hud-cyan` text, rounded-pill style.
- Input: `--hud-bg` background, `--hud-border` border, `border-radius: 16px`. Send button: solid `--hud-cyan`, black icon.

### 8.5 Calendar Panel
- Same dark glass treatment — `--hud-bg` background, `--hud-border` borders.
- Selected dates: `--hud-cyan` background instead of Bootstrap blue.
- Session list items: hover `rgba(0,212,255,0.08)`, selected `rgba(0,212,255,0.16)`.

---

## 9. Scrollbars

Applied globally to all `.torque-panel` elements:
```css
::-webkit-scrollbar       { width: 5px; }
::-webkit-scrollbar-track { background: var(--hud-bg); }
::-webkit-scrollbar-thumb { background: rgba(0,212,255,0.3); border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: rgba(0,212,255,0.6); }
```

---

## 10. Dark Mode Toggle Behaviour

The HUD theme is dark-only. The toggle is repurposed:
- **"Dark" (default):** Full neon — all glows at full intensity.
- **"Light":** De-saturated dark — glows reduced to 40% opacity, accent colours shifted to `--hud-cyan` at 60% opacity. Body background stays dark (`#0a0e1a`). No white/light theme for `session.php`.

The toggle icon changes from moon → sun as before.

---

## 11. Out of Scope

- Settings page (`settings.php`) — not restyled in this iteration
- Login page (`auth_user.php`) — not restyled
- PID editor (`pid_edit.php`) — not restyled
- Export page (`export.php`) — not restyled
- Any PHP logic changes
- Any database changes
- Library version upgrades
- Mobile/responsive layout changes beyond what `hud.css` naturally provides

---

## 12. Success Criteria

- `session.php` renders with the Dark Racing HUD theme end-to-end
- Arc gauges animate on page load and respond to chart hover within one frame
- All 5 floating panels match the HUD aesthetic
- No regressions: chart, map, crosshair sync, drag, session picker, export, AI chat all function identically to before
- PHP lint passes (`php -l`)
- No new JS errors in the browser console
