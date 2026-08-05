---
name: Smart Bill
colors:
  surface: '#f9f9ff'
  surface-dim: '#cfdaf2'
  surface-bright: '#f9f9ff'
  surface-container-lowest: '#ffffff'
  surface-container-low: '#f0f3ff'
  surface-container: '#e7eeff'
  surface-container-high: '#dee8ff'
  surface-container-highest: '#d8e3fb'
  on-surface: '#111c2d'
  on-surface-variant: '#424754'
  inverse-surface: '#263143'
  inverse-on-surface: '#ecf1ff'
  outline: '#727785'
  outline-variant: '#c2c6d6'
  surface-tint: '#005ac2'
  primary: '#0058be'
  on-primary: '#ffffff'
  primary-container: '#2170e4'
  on-primary-container: '#fefcff'
  inverse-primary: '#adc6ff'
  secondary: '#006c49'
  on-secondary: '#ffffff'
  secondary-container: '#6cf8bb'
  on-secondary-container: '#00714d'
  tertiary: '#924700'
  on-tertiary: '#ffffff'
  tertiary-container: '#b75b00'
  on-tertiary-container: '#fffbff'
  error: '#ba1a1a'
  on-error: '#ffffff'
  error-container: '#ffdad6'
  on-error-container: '#93000a'
  primary-fixed: '#d8e2ff'
  primary-fixed-dim: '#adc6ff'
  on-primary-fixed: '#001a42'
  on-primary-fixed-variant: '#004395'
  secondary-fixed: '#6ffbbe'
  secondary-fixed-dim: '#4edea3'
  on-secondary-fixed: '#002113'
  on-secondary-fixed-variant: '#005236'
  tertiary-fixed: '#ffdcc6'
  tertiary-fixed-dim: '#ffb786'
  on-tertiary-fixed: '#311300'
  on-tertiary-fixed-variant: '#723600'
  background: '#f9f9ff'
  on-background: '#111c2d'
  surface-variant: '#d8e3fb'
typography:
  display-lg:
    fontFamily: Inter
    fontSize: 48px
    fontWeight: '600'
    lineHeight: 56px
    letterSpacing: -0.02em
  headline-lg:
    fontFamily: Inter
    fontSize: 32px
    fontWeight: '600'
    lineHeight: 40px
    letterSpacing: -0.01em
  headline-lg-mobile:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '600'
    lineHeight: 32px
  headline-md:
    fontFamily: Inter
    fontSize: 24px
    fontWeight: '500'
    lineHeight: 32px
  body-lg:
    fontFamily: Inter
    fontSize: 18px
    fontWeight: '400'
    lineHeight: 28px
  body-md:
    fontFamily: Inter
    fontSize: 16px
    fontWeight: '400'
    lineHeight: 24px
  body-sm:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '400'
    lineHeight: 20px
  label-md:
    fontFamily: Inter
    fontSize: 14px
    fontWeight: '500'
    lineHeight: 20px
    letterSpacing: 0.01em
  label-sm:
    fontFamily: Inter
    fontSize: 12px
    fontWeight: '600'
    lineHeight: 16px
rounded:
  sm: 0.25rem
  DEFAULT: 0.5rem
  md: 0.75rem
  lg: 1rem
  xl: 1.5rem
  full: 9999px
spacing:
  base: 4px
  xs: 4px
  sm: 8px
  md: 16px
  lg: 24px
  xl: 40px
  gutter: 24px
  margin-mobile: 16px
  margin-desktop: 48px
  max-width: 1280px
---

## Brand & Style

The design system is built on a foundation of **Minimalism** and **Modern Corporate** aesthetics. It prioritizes clarity, efficiency, and financial transparency for property management. The interface aims to evoke a sense of organized calm, reducing the cognitive load associated with billing and property oversight.

The visual language utilizes high-quality whitespace and a restricted palette to direct attention toward data and actionable insights. By avoiding heavy ornamentation, the system ensures that complex financial information remains legible and accessible. The target audience—property managers and tenants—should feel that the platform is reliable, precise, and effortless to navigate.

## Colors

The palette is anchored by **Pure White (#ffffff)** to create an expansive, airy feel. **Light Gray (#f8fafc)** is reserved for structural differentiation, such as sidebars or alternating list rows, ensuring the UI doesn't feel monotonous. 

**Dark Gray (#1e293b)** serves as the primary text color to maintain high contrast without the harshness of pure black. Functional accents are used purposefully: 
- **Classic Light Blue (#3b82f6)**: Primary actions, focus states, and navigation.
- **Pastel Green (#10b981)**: Success states, paid bills, and positive trends.
- **Light Orange (#f57c00)**: Warnings, pending payments, and attention-required items.

## Typography

This design system uses **Inter** for its exceptional legibility in data-heavy environments. The typographic hierarchy is strictly enforced to guide the user through complex billing cycles.

Headings utilize **Medium (500)** or **SemiBold (600)** weights to establish clear section breaks. Body copy remains at **Regular (400)** weight to ensure maximum readability in long-form lists and tables. Letter spacing is slightly tightened on larger display text for a more modern, premium feel, while labels utilize increased tracking and uppercase styling for small-scale utility text.

## Layout & Spacing

The layout follows a **Fixed-Fluid hybrid grid**. Content is contained within a max-width of 1280px on desktop to prevent line lengths from becoming unreadable. A 12-column system is used with 24px gutters.

The spacing rhythm is built on a **4px baseline**, favoring "airy" 16px (md) and 24px (lg) increments for internal component padding. 
- **Mobile**: Content flows in a single column with 16px side margins.
- **Tablet**: Transition to an 8-column grid; cards may begin to stack in pairs.
- **Desktop**: Full 12-column grid. Generous vertical spacing (40px+) is used between major page sections to maintain the minimalist intent.

## Elevation & Depth

Elevation in this design system is subtle and functional, used only to indicate interactivity or stacking order.

1.  **Level 0 (Flat)**: The main background layer (#ffffff or #f8fafc).
2.  **Level 1 (Card)**: Used for content containers. Characterized by a 1px border (#e2e8f0) and a very soft, diffused shadow: `0px 4px 12px rgba(30, 41, 59, 0.03)`.
3.  **Level 2 (Hover/Active)**: Used for buttons or active cards. The shadow intensifies slightly: `0px 8px 20px rgba(30, 41, 59, 0.06)`.
4.  **Level 3 (Overlays)**: Modals and dropdowns. These use a more pronounced shadow to separate from the background: `0px 12px 32px rgba(30, 41, 59, 0.1)`.

Backdrop blurs (5px) are used behind modals to maintain a sense of place while focusing the user's attention.

## Shapes

The design system adopts a **Rounded** shape language to soften the "corporate" feel and make the app more approachable. 

The standard corner radius is **0.5rem (8px)** for most UI elements like buttons and inputs. Large containers and cards utilize **rounded-lg (16px)** to create a distinct, modern containerized look. Icons should follow a similar rounded aesthetic, avoiding sharp 90-degree caps or joins.

## Components

### Buttons
- **Primary**: Flat #3b82f6 background with white text. No gradient. Hover state is a slightly darker blue.
- **Secondary**: Ghost style with #3b82f6 text and a 1px #e2e8f0 border.
- **Padding**: 10px vertical, 20px horizontal for standard buttons.

### Inputs
- **Default**: 1px #e2e8f0 border, #f8fafc background.
- **Focus**: Transitions to a #3b82f6 border with a subtle 2px blue glow (ring).
- **Labels**: Positioned above the input in `label-md` style.

### Cards
- White background (#ffffff), 16px corner radius, and 1px border.
- Internal padding should be a minimum of 24px (lg spacing) to ensure content "breathes."

### Chips/Status Badges
- Used for payment status (e.g., "Paid", "Overdue").
- Small text (`label-sm`), high-radius (pill), with low-opacity background tints of the accent colors (e.g., 10% opacity Green for "Paid").

### Lists
- Clean rows with 1px bottom borders. No zebra striping unless the data is extremely dense; favor whitespace for separation instead.