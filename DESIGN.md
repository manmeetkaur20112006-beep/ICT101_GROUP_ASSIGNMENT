# DESIGN.md — Singh Fitness Gym

Read this file before writing or changing any page.
These are design decisions that are already final. Do not change them
or invent your own. If something here is unclear, stop and ask.

---

## 1. Overall look

Apple-style and minimal. Calm, plenty of space, few colours.

- Grouped list style: related rows sit inside one rounded box.
- Section boxes have a soft light grey background.
- Accent colour is light blue.
- Gold is only for streak, points, and premium items. Nowhere else.
- No loud colours, no heavy borders, no drop shadows everywhere.

---

## 2. Navigation — the most important rule

- Navigation lives in ONE place only: a floating bar at the BOTTOM.
- The bottom bar has exactly 4 tabs: Home, Fitness, Progress, Profile.
- 5 is the absolute maximum. Never more.
- NEVER put tab switchers in the header.
- The header holds the gym name and the profile/notification area only.

Sub-sections inside a page (for example workout vs diet) are a small
segmented switcher inside the page body, not extra tabs in the header.

---

## 3. Bottom bar — locked values

The bottom bar was already built and tested as a reusable component:
`liquid-tabbar.css`, `liquid-tabbar.js`, `demo.html`.

Use those files. Do not rebuild the bar from scratch.
Because the project is limited to 2 external CSS files, paste the
contents of `liquid-tabbar.css` into `shared.css` instead of adding a
third stylesheet.

The sliding glass piece inside the bar is called "the lens".
Both the bar and the lens are capsules (border-radius 999px), not squircles.

Locked numbers, do not retune:

| Setting          | Value |
|------------------|-------|
| button bloom     | 1.030 |
| button bounce    | 1.60  |
| button speed     | 260ms |
| lens springiness | 160   |
| lens wobble      | 21.5  |
| lens swell wide  | 1.31  |
| lens swell tall  | 1.46  |
| bar give         | 5px   |
| icon zoom        | 1.30  |
| bar width        | 360px |
| bar thickness    | 5px   |

Spring feel follows SwiftUI `.snappy` (response 0.5s, damping 0.85).

---

## 4. Liquid glass

Glass treatment is used on:

- the bottom navigation bar
- floating buttons
- primary action buttons such as Save

A glass button is blurred background + soft translucent white layer +
thin light border. A flat solid rectangle is wrong.
Ordinary text links and small secondary buttons stay plain.

---

## 5. Dividers inside boxes

Lines between rows inside a box must be inset.

- Equal gap on the left and the right. Same number on both sides.
- The line must never touch the edge of the box.
- Wrong: line runs edge to edge.
- Wrong: bigger gap on the left than on the right.

---

## 6. Cards and corners

Cards use a JS-drawn superellipse squircle, n = 5, radius 22, applied
with a ResizeObserver so it redraws on resize.

Do NOT use Chrome's native `corner-shape` property. It draws a different
curve and the shapes stop matching.

---

## 7. Tapping behaviour

Rows open themselves. There are no extra buttons underneath them.

- Tapping an exercise opens the full exercise plan.
- Tapping a member row opens that member.
- Do NOT add buttons like "View full plan" or "Open details".

---

## 8. Press feedback

- Press states are handled in JavaScript with `pointerdown` / `pointerup`,
  not CSS `:active`. iOS Safari does not fire `:active` reliably.
- Vibration via `navigator.vibrate()` where supported. iOS ignores it.
  That is accepted.
