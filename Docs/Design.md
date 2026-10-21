# Product Design System

## CINNAMON Inventory and Sales Management

**Status:** Revised design baseline  
**Source of truth:** `PRD.md`  
**Client:** React with TypeScript and Vite  
**Primary language:** Indonesian

## 1. Design direction

CINNAMON is an operational tool for a spice store. The interface should feel warm and trustworthy while remaining compact, fast, and easy to audit. Product photos and spice colors may support recognition, but decoration must never compete with quantities, status, prices, or actions.

Design principles:

1. **Clarity before decoration:** show ready stock, pending stock, status, and next action first.
2. **Safe operations:** distinguish draft, pending, approved, posted, and cancelled states.
3. **Evidence in context:** show actor, date, batch, reason, and source document beside changes.
4. **Repeatable workflows:** receiving and sales forms use the same layout, validation behavior, and confirmation pattern.
5. **Progressive detail:** list screens stay scannable; detail drawers or pages expose the full audit trail.
6. **Accessible by default:** color is never the only status signal and every action works from keyboard.

## 2. Visual tokens

### Colors

| Token | Value | Use |
|---|---|---|
| `brand-700` | `#6B341F` | Primary navigation, strong headings, primary action hover |
| `brand-600` | `#8B4A2F` | Primary buttons and active navigation |
| `brand-100` | `#F4E4D8` | Brand surfaces and selected row background |
| `accent-600` | `#C88719` | Attention, pending state, low-stock warning |
| `success-700` | `#246344` | Approved, posted, ready stock |
| `danger-700` | `#B42318` | Destructive action and validation failure |
| `ink-900` | `#1F2933` | Main text |
| `ink-600` | `#52606D` | Secondary text |
| `line` | `#D9E2EC` | Borders and table dividers |
| `surface` | `#FFFFFF` | Cards and forms |
| `canvas` | `#F7F5F2` | Application background |

Status must include a text label and, where useful, an icon. Do not use red/green color alone to communicate stock or approval state.

### Typography

- Use a system sans stack or Inter for the web client.
- Body text: 14-16px, line height 1.5.
- Page title: 24-28px, weight 700.
- Section title: 18-20px, weight 650.
- Table text: 13-14px; numeric columns use tabular numerals.
- Monetary values use a consistent Indonesian format and show the currency context in the column header or value.

### Spacing and shape

- Base spacing unit: 4px.
- Common gaps: 8px, 12px, 16px, 24px, 32px.
- Card radius: 10px; input radius: 8px; button radius: 8px.
- Use a 1px border and a small shadow only for elevation or focus context.
- Do not use rounded pills for every element; reserve badges for status and compact metadata.

## 3. App shell and navigation

Desktop layout:

- Left sidebar with CINNAMON mark and modules.
- Top bar with page title, date context, user menu, and alerts.
- Main content uses a max width of 1440px with responsive gutters.

Navigation order follows the workday:

1. Dashboard
2. Master Data: Categories, Products, Suppliers, Customers
3. Incoming Goods: New Receipt, Pending Verification, History
4. Sales: New Sale, History
5. Inventory: Stock Overview, Corrections, Movements
6. Reports: Incoming, Outgoing, Stock, Profit, Ledger
7. Administration: Users, Profile

Karyawan sees only permitted modules. The API remains the authority even when navigation is hidden.

Mobile web collapses the sidebar into a menu. Forms remain usable on a phone, but desktop remains the primary reporting surface. Flutter is a future client, not a requirement for the MVP web layout.

## 4. Reusable components

### Foundation

- `Button`: primary, secondary, danger, ghost, loading.
- `Input`, `Select`, `DateInput`, `NumberInput`, `Textarea`, `FileUpload`.
- `FieldError`, `FormSection`, `RequiredLabel`.
- `Badge`, `StatusBadge`, `Avatar`, `Tooltip`.
- `Modal`, `Drawer`, `ConfirmDialog`, `Toast`.
- `Table`, `Pagination`, `FilterBar`, `EmptyState`, `Skeleton`.

### Domain components

- `StockSummaryCard`: ready, pending, minimum-stock state.
- `DocumentStatusTimeline`: draft, pending, approved, posted, cancelled.
- `LineItemEditor`: product search, quantity/weight, unit cost or selling price, subtotal.
- `BatchAllocationTable`: batch code, received date, allocated quantity, unit cost, HPP.
- `MovementTimeline`: type, quantity, before/after, actor, source document.
- `ReportToolbar`: date range, product/category filters, export action.

Components must accept explicit data and callbacks. Do not hide API calls, permission checks, or business calculations inside a generic UI component.

## 5. Forms and workflow behavior

- Keep the document header visible while editing line items.
- Show a running subtotal for convenience, but label the server response as authoritative.
- Prevent duplicate submission while a command is pending.
- Preserve entered values after validation or network errors.
- Approval and posting require a confirmation dialog that states the stock impact.
- Stock correction requires a reason and shows system quantity beside physical quantity.
- Disable editing after approval or posting; offer a permitted reversal flow instead.
- Use searchable product selectors that show SKU/name/unit and current ready stock.

## 6. States

### Loading

Use skeleton rows for lists and a disabled action with a progress label for mutations. Avoid a blank screen while data is loading.

### Empty

Explain why the list is empty and provide the next permitted action, such as `Tambah Produk` or `Catat Barang Masuk`.

### Error

Display field-level validation beside fields. Display domain conflicts such as insufficient stock or duplicate approval in a clear alert with the request id for support. Never expose stack traces.

### Success and destructive actions

After a successful receipt approval or sale posting, show the document number, status, stock effect, and a link to the detail. Deletion is replaced by deactivation for referenced master data.

## 7. Interaction and motion

- Use 150-200ms transitions for hover, focus, drawer, and modal entry.
- Do not animate stock numbers in a way that makes values difficult to read.
- Use a clear focus ring with at least 3:1 contrast against the adjacent surface.
- Toasts may confirm a minor save, but critical mutations also update the page state visibly.
- Warn before navigating away from a dirty document form.

## 8. Responsive behavior

- At desktop width, show filters and table columns inline.
- At tablet width, move filters into a collapsible panel and keep the primary action visible.
- At mobile width, turn dense tables into stacked cards or a horizontal scroll region with a fixed key column.
- Forms use one column on mobile and two columns only where fields remain related.
- Reports provide a compact summary first and detailed rows below.

## 9. Accessibility requirements

- All inputs have visible labels and error text linked with `aria-describedby`.
- All controls are keyboard reachable with logical focus order.
- Dialogs trap focus and return focus to the triggering control.
- Tables have captions or accessible labels and do not rely on color alone.
- Icons that convey meaning have accessible text; decorative icons are hidden from assistive technology.
- Contrast meets WCAG AA for body text and controls.
- Date, quantity, and currency inputs expose understandable formats and validation messages.

## 10. Page guidance

### Dashboard

For Admin, show four to six summary cards: ready stock, pending stock, low-stock products, incoming pending approval, today's sales, and gross profit for the selected period. For Karyawan, show only incoming tasks, pending-product count, and the next required operational action. Follow with role-appropriate recent activity and shortcuts.

### Product list

Provide search, category filter, active status, stock status, and a clear `Tambah Produk` action. The table shows product, unit, ready stock, pending stock, selling price, and status.

### Incoming goods

Use a two-step visual flow: receipt details and item verification. Pending items show initial/final weight and approval status. The approval panel shows the exact quantity that will become ready stock.

### Sales

Make product search and quantity entry dominant. Show available ready stock before posting. After posting, show FIFO allocations, HPP, total sale, and gross profit in the detail view.

### Stock and movements

Stock overview emphasizes ready versus pending. Movement detail shows before/after values, batch, source, actor, and reason. Correction is a deliberate action, not an inline cell edit.

### Reports

Use a shared date range toolbar. Show totals above the table, keep filters visible, and label whether a value is sales, HPP, or gross profit. Export is secondary to a correct on-screen report.

## 11. Design acceptance criteria

- A user can identify the next action on every operational page without reading documentation.
- Pending, ready, approved, posted, cancelled, and low-stock states are distinguishable without relying on color alone.
- Incoming and sales forms prevent duplicate posting and explain stock impact before confirmation.
- Tables remain readable on a laptop and usable on a narrow viewport.
- Keyboard navigation, focus states, validation messages, and dialog behavior meet the accessibility requirements above.
- Page layouts map directly to the screens and workflows in `PRD.md` and the TA rather than introducing unrelated SaaS features.
