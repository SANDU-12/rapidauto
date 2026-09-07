# Rapid Auto Service Center — Full Project (Frontend + PHP Backend + MySQL)

A complete, self-contained project: multi-page website, PHP REST API, and MySQL database — everything in one folder, ready for WampServer.

## What's inside
```
rapidauto/
├── index.html, about.html, services.html, shop.html, blog.html,
│   reviews.html, book.html, checkout.html, track.html, admin.html
├── styles.css, app.js              ← shared frontend styling + logic
├── api/                            ← PHP REST endpoints
│   ├── auth.php          (register/login)
│   ├── services.php      (price list — full CRUD for admin)
│   ├── products.php      (spare parts shop)
│   ├── bookings.php      (booking, live tracking, reschedule/cancel)
│   ├── orders.php        (spare parts checkout)
│   ├── inventory.php     (parts, purchase records, supplier orders — full CRUD)
│   ├── reports.php       (business analytics by day/week/month/year)
│   └── reviews.php
├── config/db.php                   ← MySQL connection (WAMP defaults)
├── includes/jwt.php                ← lightweight JWT auth (no external libraries needed)
├── schema.sql                      ← full database schema + seed data
└── seed_admin.php                  ← run once to create the admin login
```

Note: `gallery.html` and `contact.html` were merged into `about.html` (About Us now includes the story, videos, photo gallery, contact form/map, and FAQ in one page).

## Fixed in this version

**Dashboard navigation**: previously, if any one section hit an error while loading, it would silently hang on "Loading…" forever and look like the sidebar wasn't working. The tab router now wraps every section render in error handling — a failed section shows a clear error message with a **Retry** button instead of freezing, and every other tab keeps working normally regardless.

**Login / Sign Up overhaul**: the old modal was a bare email+password box. It's now a full flow:
- **Customer Login** — email + password, with a **Forgot password?** link
- **Sign Up** — first name, last name, email, phone, password, confirm password → creates a real account via `api/auth.php?action=register`
- **Forgot Password** — enter your email, a reset token is generated (`password_resets` table, 1-hour expiry) and a reset link is shown. There's no SMTP server configured in this project, so instead of emailing it, the link is shown directly on screen — swap that for a real mail call once you have SMTP/PHPMailer set up.
- **Reset Password page** (`reset-password.html`) — opened from the reset link, lets you set a new password against the token
- **Continue with Google** — uses Google's Identity Services library. This needs your own Google OAuth Client ID (Google Cloud Console → Credentials → OAuth Client ID → Web application). Paste it into `GOOGLE_CLIENT_ID` near the top of `app.js`. Until you do, the Google button simply doesn't render — everything else works without it.
- **Admin Login** is now a separate, clearly labeled option (separate "Admin" button in the nav) so it's not confused with the customer flow.

## Fixed / added in this latest session

**Images finally fixed for real**: the root cause was `source.unsplash.com` ("Unsplash Source"), which was **permanently shut down in 2023** — that's why spare-parts and gallery images never loaded. Replaced every image with a real, specific Unsplash CDN URL, and added a universal `imgFallback()` safety net to *every* `<img>` tag site-wide — if any image URL ever breaks in the future, you get a clean labeled placeholder instead of a broken-image icon, never a dead page.

**Videos rebuilt to be actually reliable**: raw YouTube `<iframe>` embeds can silently fail (a video owner can disable embedding at any time with no visible error). Replaced every video with a click-to-play card: a real YouTube thumbnail image (always loads, since thumbnails are just static images) plus a "watch on YouTube ↗" link that always works, with the embedded player only loading once you click. I was upfront that I can't literally download and host video files myself — my sandbox's network access is locked to package registries (npm, pypi, etc.), not video hosts — so this thumbnail+direct-link pattern is the most reliable alternative available.

**Truly unified login for every user type**: one single "Login / Sign Up" entry point for customers, admins, and technicians — no more separate admin button. After authenticating, the server's returned role decides where you land: staff roles (`super_admin`, `manager`, `cashier`, `receptionist`, `mechanic`, `car_wash_staff`, `inventory_manager`) route straight to the admin dashboard; customers stay on the site. Google sign-in lives in this same single flow.

**New: Employees & Technicians management** (`api/employees.php` + new admin tab): add technicians/staff with position, phone, salary, skills, and availability — with an optional "create a login for this person" checkbox that creates a real linked account (`employees.user_id → users.id`) so they can actually log in with the role you assign them.

### All 21 database entities
`users`, `customers`, `employees`, `vehicles`, `services`, `packages`, `package_services`, `bookings`, `booking_services`, `payments`, `suppliers`, `inventory`, `password_resets`, `purchase_records`, `supplier_orders`, `purchase_orders`, `reviews`, `notifications`, `coupons`, `products`, `orders`, `order_items` — all created automatically when you import `schema.sql`, nothing to add manually.

## Setup (10 minutes)

**1. Install WampServer** if you don't have it: https://www.wampserver.com/en/ — start it until the tray icon is green.

**2. Copy this whole folder** into your WAMP www directory:
```
C:\wamp64\www\rapidauto
```

**3. Create the database**
- Open phpMyAdmin: http://localhost/phpmyadmin (user `root`, blank password by default)
- Click **Import** → choose `schema.sql` → **Go**
- This creates the `rapidauto` database with all tables and seeds your real price list + spare parts catalog + sample inventory + sample purchase records + sample supplier orders

**4. Create the admin login**
Visit once in your browser:
```
http://localhost/rapidauto/seed_admin.php
```
Creates `admin@rapid.lk` / `admin123` with a proper bcrypt hash.

**5. Open the site**
```
http://localhost/rapidauto/index.html
```
The frontend auto-detects the PHP API (health check on load) and switches from demo/localStorage mode to the real MySQL-backed data. Check the browser console, or the small "● Live DB / ● Demo Mode" tag in the admin dashboard header, to see which mode you're in.

## New in this version

**Admin — every section now has real CRUD**, not just view-only lists:
- **Overview**: live KPIs (read-only, by design — it's a summary of the other sections)
- **Bookings**: Add, View full details (customer, vehicle, services, payment), Edit, Advance stage, Delete
- **Customers**: View full booking history + total spend, Edit contact info (updates across all their bookings), Delete
- **Services**: Add, Edit, Hide (soft-delete)
- **Inventory**: Add, Edit, Hide, quick availability dropdown (`available`/`low_stock`/`unavailable`)
- **Purchase Records**: Add, Edit, Delete
- **Supplier Orders**: Add, Edit, Delete, quick status dropdown (`ordered`/`received`/`returned`)
- **Payments**: Edit (amount, method, status), quick status dropdown, Delete
- **Reviews**: Edit, Delete
- **Reports**: read-only by nature (aggregated stats), with PDF export

All of the above require the live PHP backend — in demo/localStorage mode, add/edit/delete actions show a message asking you to connect WAMP, since there's nowhere durable to persist that data otherwise.

**Admin — Reports**: Daily / weekly / monthly / yearly business stats — revenue (service + shop split), bookings, completed/cancelled counts, customers, low-stock alerts, top services, and payment method breakdown. One click downloads a formatted PDF report (or use your browser's print button on the page itself).

**Book Now — Manage an Existing Booking**: New section where a customer enters their Booking Reference (+ phone for verification) to reschedule (pick a new date/slot) or cancel a confirmed appointment. Already-completed or cancelled bookings are locked from further changes.

**PDF receipts**: After confirming a service booking or placing a spare-parts order, a PDF (via jsPDF, loaded from a CDN — no server-side PDF library needed) is automatically generated and downloaded — a proper receipt/invoice with booking or order details, items, and total.

**About Us page**: Combines the previous Gallery & Videos and Contact pages into one — brand story, video showcase, photo gallery, contact form + map, and FAQ.

## How the connection works
`app.js` defines `API_BASE = "api"` (a relative path) and does a one-time health check against `api/services.php` when any page loads. If it succeeds, every page — services, shop, booking, tracking, checkout, admin (including all the new CRUD screens and reports) — calls the real PHP endpoints instead of using localStorage. If WAMP isn't running, everything gracefully falls back to demo mode so the site never breaks either way. Note: CRUD actions specifically (add/edit/hide/reports) require the live backend — they'll show a message asking you to connect WAMP if you're in demo mode, since there's nowhere else to durably persist that data.

## What's real vs. what's still simulated
✅ **Real and working once WAMP is running:**
- Price list, spare parts catalog, inventory, purchase records, supplier orders — all live CRUD against MySQL
- Bookings, rescheduling, cancellation — all write to the database
- Live tracking — reads real `tracking_stage_index`
- Spare parts orders — written to `orders` / `order_items`, stock auto-decrements
- Reports — real SQL aggregation by period
- Admin login — real JWT

🟡 **Still simulated (by design — needs your own accounts):**
- PayPal / card payments — forms validate and the method + last-4 digits (never the full card number) are stored, but no money actually moves. Needs your own PayPal Business or Stripe keys.
- SMS/email confirmations — needs a Twilio/SMTP account.
- PDF generation is entirely client-side (jsPDF in the browser) — no PHP PDF library needed, works offline once the page has loaded.

## Security notes before this goes anywhere public
- Change `JWT_SECRET` in `config/db.php` to a long random string.
- Set a real MySQL password in WAMP and update `DB_PASS` accordingly.
- Only the last 4 digits of any card are ever stored.

