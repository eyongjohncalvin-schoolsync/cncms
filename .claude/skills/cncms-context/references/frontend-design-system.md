# Frontend Design System

Production-grade frontend design rules for building distinctive, memorable websites.
Use this file as a reference when building any web UI.

---

## 1. Design Thinking (Before Coding)

Before writing ANY code, commit to a bold aesthetic direction:

- **Purpose**: What problem does this interface solve? Who uses it?
- **Audience**: What do they expect? What would surprise them?
- **Tone**: Pick an extreme — never settle for middle-ground

### Aesthetic Directions (pick one, commit fully)

| Direction | Characteristics |
|-----------|-----------------|
| Brutally Minimal | Stark, essential, nothing extra |
| Maximalist Chaos | Dense, layered, overwhelming intentionally |
| Retro-Futuristic | Vintage aesthetics with modern tech |
| Organic/Natural | Soft, flowing, nature-inspired |
| Luxury/Refined | Premium materials, subtle elegance |
| Playful/Toy-like | Fun, approachable, bright |
| Editorial/Magazine | Type-forward, grid-based |
| Brutalist/Raw | Exposed structure, anti-design |
| Art Deco/Geometric | Bold shapes, symmetry, ornament |
| Industrial/Utilitarian | Function-forward, robust |

### The Memorable Element
Every page needs ONE unforgettable design choice — a typography treatment, hero animation, unusual layout, or visual detail that someone will remember.

---

## 2. Typography

### Font Selection

**NEVER use**: Arial, Helvetica, system-ui, Inter, Roboto, Open Sans — overused, generic, forgettable.

**USE distinctive fonts**:

| Use Case | Recommendations |
|----------|-----------------|
| Display/Headlines | Clash Display, Cabinet Grotesk, Satoshi, Playfair Display, Instrument Serif, Fraunces, Newsreader |
| Body Text | Plus Jakarta Sans, Instrument Sans, General Sans, Satoshi |
| Monospace | JetBrains Mono, IBM Plex Mono, Fira Code |

### Rules

- Pair a characterful display font with a refined body font (max 2 families)
- Use dramatic size jumps (2x+), not timid increments
- Body text 16-18px minimum
- Line height 1.5-1.7 for body, 1.1-1.2 for headlines
- Max width 65-75 characters per line
- One hero size per page — don't compete for attention

### Size Scale

```css
fontSize: {
  'base': '1rem',       /* 16px */
  'lg':  '1.125rem',    /* 18px */
  '2xl': '1.5rem',      /* 24px */
  '4xl': '2.5rem',      /* 40px */
  '5xl': '3.5rem',      /* 56px — hero */
  '6xl': '4.5rem',      /* 72px — statement */
}
```

```css
--font-display: 'Clash Display', sans-serif;
--font-body: 'Satoshi', sans-serif;
```

---

## 3. Color & Theme

### Rules

- **70-20-10 rule**: Primary 70%, secondary 20%, accent 10%
- **Commit to light OR dark** — no muddy mid-grays
- **Dominant + accent** outperforms evenly-distributed colors
- **High contrast CTAs** — buttons must pop
- **Semantic colors**: red=destructive, green=success, yellow=warning
- **Use CSS variables** for consistency and theme switching

### Backgrounds

**NEVER use** solid white (#fff) or plain gray.

**USE**:
- Subtle gradients: `bg-gradient-to-br from-slate-50 to-slate-100`
- Noise/grain texture overlays
- Glassmorphism with backdrop-blur
- Gradient meshes, geometric patterns, layered transparencies, dramatic shadows

```css
:root {
  --background: 0 0% 100%;
  --foreground: 222.2 84% 4.9%;
  --primary: 222.2 47.4% 11.2%;
  --primary-foreground: 210 40% 98%;
  --secondary: 210 40% 96%;
  --accent: 210 40% 96%;
  --destructive: 0 84% 60%;
  --border: 214.3 31.8% 91.4%;
}

.dark {
  --background: 222.2 84% 4.9%;
  --foreground: 210 40% 98%;
}
```

```css
/* Grain overlay for texture */
.grain::before {
  content: '';
  position: fixed;
  inset: 0;
  background: url("data:image/svg+xml,...");
  opacity: 0.03;
  pointer-events: none;
}
```

---

## 4. Spatial Composition

Break expectations:
- **Asymmetry** over perfect balance
- **Overlap** elements intentionally
- **Diagonal flow** or unconventional layouts
- **Generous negative space** OR controlled density — not middle ground
- **Grid-breaking elements** that draw attention
- **Unexpected layouts** — not every section needs to be a centered card grid

---

## 5. Animation & Motion

### Priority
One orchestrated page load animation > scattered micro-interactions everywhere.

### High-Impact Moments
1. **Staggered hero reveals** — content fades in sequence
2. **Scroll-triggered sections** — elements enter on scroll
3. **Hover state surprises** — scale, shadow, color shift
4. **Page transitions** — smooth route changes

### Timing

| Type | Duration |
|------|----------|
| Interactions (hover, click) | 150-300ms |
| Transitions (page, modal) | 300-500ms |
| Complex sequences | 500-800ms total |

### Implementation
- Prefer **CSS animations** for HTML
- Use **Framer Motion** for React
- Always respect `prefers-reduced-motion`

```css
/* Staggered entrance */
.card { animation: fadeUp 0.6s ease-out backwards; }
.card:nth-child(1) { animation-delay: 0.1s; }
.card:nth-child(2) { animation-delay: 0.2s; }
.card:nth-child(3) { animation-delay: 0.3s; }

@keyframes fadeUp {
  from { opacity: 0; transform: translateY(20px); }
  to { opacity: 1; transform: translateY(0); }
}

/* Accessibility */
@media (prefers-reduced-motion: reduce) {
  * { animation-duration: 0.01ms !important; }
}
```

```tsx
// Framer Motion stagger (React)
const container = {
  hidden: { opacity: 0 },
  show: { opacity: 1, transition: { staggerChildren: 0.1 } }
}
const item = {
  hidden: { opacity: 0, y: 20 },
  show: { opacity: 1, y: 0 }
}

<motion.div variants={container} initial="hidden" animate="show">
  {items.map(i => <motion.div key={i} variants={item} />)}
</motion.div>
```

---

## 6. Mobile-First

### Breakpoints (enhance upward)

```css
@media (min-width: 640px)  { /* sm: tablet */ }
@media (min-width: 768px)  { /* md: landscape tablet */ }
@media (min-width: 1024px) { /* lg: laptop */ }
@media (min-width: 1280px) { /* xl: desktop */ }
```

### Rules

- Start with mobile layout, enhance upward
- Every grid must collapse to single column
- Touch targets minimum **44x44px**, 8px spacing between targets
- Swipe actions need visual hints

### Layout Transformations

| Pattern | Desktop | Mobile |
|---------|---------|--------|
| Hero with image | 2-column grid | Stack, image below |
| Feature grid | 3-4 columns | Single column |
| Sidebar + content | Side-by-side | Sheet/drawer |
| Data tables | Full table | Card view |
| Multi-column forms | Side-by-side | Stack vertically |

---

## 7. Interaction Feedback

- Acknowledge taps within 100ms
- Optimistic updates for instant feel
- Loading states for operations >1s
- **Preserve user input on errors** — never clear what the user typed

---

## 8. Accessibility (Non-Negotiable)

- Color contrast 4.5:1 (text), 3:1 (UI elements)
- Focus states on ALL interactive elements
- Semantic HTML: `nav`, `main`, `section`, `article`
- Keyboard navigation works for everything
- Respect `prefers-reduced-motion`

---

## 9. Performance

- Lazy load below-fold content
- Image placeholders prevent layout shift
- Code split heavy components
- Target: LCP <2.5s, CLS <0.1

---

## 10. Recommended Stack

| Layer | Choice | Why |
|-------|--------|-----|
| Framework | Next.js 14+ | RSC, file routing, Vercel deploy |
| Language | TypeScript | Catch errors early, better DX |
| Styling | Tailwind CSS | Utility-first, design tokens built-in |
| Components | shadcn/ui | Accessible, customizable, not a dependency |
| Animation | Framer Motion | Declarative, performant |
| Forms | React Hook Form + Zod | Type-safe validation |
| State | Zustand or Jotai | Simple, no boilerplate |

### Project Structure

```
src/
├── app/                 # Next.js App Router
│   ├── layout.tsx
│   ├── page.tsx
│   └── [feature]/
├── components/
│   ├── ui/              # shadcn/ui components
│   └── [feature]/
├── lib/
│   ├── utils.ts         # cn(), formatters
│   └── api.ts
├── hooks/
├── styles/
│   └── globals.css
└── config/
    └── site.ts
```

### Essential Utils

```typescript
import { clsx, type ClassValue } from "clsx"
import { twMerge } from "tailwind-merge"

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}
```

---

## 11. NEVER Do (Anti-Patterns)

1. **NEVER use** generic fonts (Inter, Roboto, Arial, system-ui)
2. **NEVER use** purple gradients on white backgrounds — the "AI aesthetic"
3. **NEVER use** solid white or plain gray backgrounds
4. **NEVER use** predictable, cookie-cutter layouts
5. **NEVER skip** the design thinking phase — understand before building
6. **NEVER hedge** with safe, middle-ground aesthetics — commit to a direction
7. **NEVER clear user input on form errors** — preserve and highlight
8. **NEVER make mobile an afterthought** — 60%+ of users are on mobile
9. **NEVER forget** loading states — users think it's broken otherwise
10. **NEVER converge** on the same patterns across projects — vary intentionally
11. **NEVER add** complexity without purpose — minimalism and maximalism both require intention

---

## 12. Common Traps & Fixes

| Trap | Consequence | Fix |
|------|-------------|-----|
| Generic fonts | Looks like every other site | Use distinctive, paired fonts |
| Solid white backgrounds | Flat, lifeless | Add gradients, grain, depth |
| Mobile as afterthought | Broken for 60% of users | Mobile-first always |
| Form error clears input | User rage | Preserve input, highlight error |
| No loading states | User thinks broken | Show progress immediately |
| Timid type scale | No visual hierarchy | Use 2x+ jumps for headlines |
| Purple gradient on white | Looks AI-generated | Commit to a real palette |