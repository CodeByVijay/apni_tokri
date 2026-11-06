# Veggie Tokri – Complete E-commerce Website Requirements & Stage-wise Development Plan

**Document type:** Product Requirements Document (PRD) + Technical Implementation Specification  
**Working brand:** Veggie Tokri (proposed; final trademark/legal clearance pending)  
**Suggested tagline:** Freshness, Ready for Your Kitchen.  
**Initial market:** Indore, Madhya Pradesh, India  
**Technology:** Core PHP + MySQL + HTML + Tailwind CSS CDN + jQuery + AJAX  
**Development approach:** Cursor Composer 2.5, stage-wise implementation  
**Deployment goal:** Upload PHP files, import one SQL file, configure credentials and run. No build or package installation step.

---

# 1. Project Vision

Build a reliable, customer-friendly local food and grocery e-commerce website for Indore. Customers should be able to browse and purchase:

- Fresh vegetables
- Ready-cut vegetables
- Fresh fruits
- Cut fruits
- Pickles
- Papad
- Dry chutneys
- Other packaged food products and future categories

The website should make shopping easy, transparent and trustworthy. Customers must understand product quality information, prices, delivery charges, order status, subscription rules, cancellation and refund policies without needing to call the business for routine questions.

Start with one dispatch location and one Indore service area configuration. The architecture should allow additional delivery zones, dispatch locations and product categories later without rewriting the application.

## 1.1 Business objectives

1. Make repeat ordering easy.
2. Build trust through transparent product information and genuine customer feedback.
3. Support both one-time orders and prepaid subscriptions.
4. Give the owner/admin control over products, prices, stock, delivery, offers, homepage content, FAQs, reviews, refunds and website settings.
5. Keep operational costs and technical complexity low at launch.
6. Make the application maintainable by a PHP developer without a framework.

## 1.2 Non-goals for initial launch

Do not add unnecessary complexity in the first release:

- No multi-vendor marketplace.
- No seller registration or seller dashboards.
- No native Android/iOS application.
- No Laravel or another PHP framework.
- No Node, npm, Vite, Composer or frontend build pipeline.
- No expensive CRM/live-chat system initially.
- No AI chatbot unless separately approved after the core site is stable.

---

# 2. Non-negotiable Technical Constraints

## 2.1 Technology stack

- Core PHP, compatible with PHP 8.2+.
- MySQL 8+ or a compatible supported MariaDB version.
- PDO with prepared statements for database access.
- HTML5, CSS and Tailwind CSS CDN for the initial no-build frontend.
- jQuery for client-side interactions and AJAX.
- Vanilla JavaScript where it is simpler.
- Leaflet + OpenStreetMap for map pin selection, subject to provider usage policies.
- PHP Sodium for encrypting sensitive configuration values at rest.
- cURL for payment, SMS, email-provider and other HTTP integrations.
- SMTP for email notifications.
- Razorpay and Cashfree payment gateways.

Do not use Laravel, Symfony, CodeIgniter, Composer packages, npm packages, Node, Vite, Webpack or any build command.

## 2.2 Deployment experience

The intended deployment process:

1. Create a MySQL database and database user.
2. Import `database/database.sql`.
3. Upload the project files to the server.
4. Configure database credentials and environment-specific settings.
5. Place the master encryption key and sensitive configuration outside the public webroot.
6. Set correct directory permissions.
7. Configure HTTPS.
8. Configure cron jobs.
9. Configure payment gateway webhooks.
10. Open the website and complete the installation/health checks.

Do not make the application dependent on running migrations or installing dependencies on the production server. Provide a complete SQL installation file and, where necessary, versioned SQL upgrade scripts.

## 2.3 Security constraints

- Use PDO prepared statements.
- CSRF protection for all state-changing forms and AJAX requests.
- Output escaping to prevent XSS.
- Server-side validation for every important input.
- Passwords (if password login is later introduced) must use `password_hash()` and `password_verify()`.
- OTPs must be hashed or securely stored with short expiry and attempt limits.
- Rate-limit OTP requests, login attempts, review submissions, coupon attempts and sensitive endpoints.
- Session ID regeneration after login/OTP verification.
- Secure, HttpOnly and SameSite cookies; Secure enabled on HTTPS.
- Enforce server-side role/permission checks.
- Validate file type, MIME, extension, size and image dimensions; randomize stored filenames.
- Prevent PHP execution in upload directories.
- Never trust price, discount, stock, delivery fee, order total or payment status sent by the browser.
- Never log passwords, OTPs, API secrets, full payment credentials or unnecessary personal data.
- Use transaction boundaries and row locking for stock, coupons, payment state and subscription delivery generation.
- Maintain audit logs for sensitive admin actions.
- Use HTTPS throughout.
- Provide secure error handling; no SQL errors, stack traces or secrets in public responses.

## 2.4 Sensitive configuration and encryption

Admin can configure:

- Razorpay key ID, key secret and webhook secret.
- Cashfree app ID, secret and webhook secret.
- SMTP host, port, encryption mode, username, password and sender details.
- SMS provider URL, API key/token, sender ID and template IDs.
- Other integration credentials.

Requirements:

- Encrypt secrets at rest using PHP Sodium.
- Master encryption key must be stored outside public webroot and must not be stored in the database.
- Admin should see masked credentials only, e.g. `************abcd`.
- Admin can replace a secret but cannot retrieve the existing plaintext secret.
- Test and live gateway credentials must be separate.
- Provide a documented master-key backup and recovery process.
- Key rotation must be planned and safely supported; do not silently replace the master key.
- Do not expose secrets in HTML, JavaScript, logs, exports or error messages.

---

# 3. Brand, Identity and Visual Design

## 3.1 Working brand

Working name: **Veggie Tokri**  
Suggested tagline: **Freshness, Ready for Your Kitchen.**

Trademark and domain clearance are not confirmed. Treat the name as a working candidate until proper trademark similarity searches and professional legal review are completed. Domain availability does not establish trademark rights.

Do not use the previous “Apna Fresh” brand name in new UI copy unless explicitly instructed.

## 3.2 Suggested visual palette

- Primary green: `#245C3A`
- Warm yellow: `#F5A623`
- Cream background: `#FFF8E9`
- Charcoal text: `#292D29`
- White: `#FFFFFF`
- Muted text and borders: neutral grey shades

Use the palette consistently. Maintain readable contrast and accessible focus states.

## 3.3 Design personality

- Modern, local, friendly and trustworthy.
- Clean grocery-commerce design, not a generic corporate template.
- Product photography should be clear and relevant.
- Use natural product images and genuine business photography where available.
- Avoid excessive animations, popups and visual clutter.
- Use simple Hindi/English microcopy.
- Never invent customer counts, ratings, reviews, certifications, discounts or scarcity.

## 3.4 Responsive design

All public and admin pages must work from 320px mobile widths through tablets, laptops and large desktop monitors.

- Mobile: 1–2 product columns depending on width; touch-friendly controls; sticky cart summary where useful; optional bottom navigation.
- Tablet: responsive 2–4 column product grids.
- Laptop: responsive 3–5 column grids.
- Desktop: responsive 4–6 column grids.
- No horizontal page overflow.
- No clipped buttons, overlapping text or tiny tap targets.
- Product gallery must support swipe on mobile.
- Admin tables should become usable cards or horizontally scrollable data regions on small screens, without breaking the whole page.
- Test portrait and landscape layouts.

---

# 4. Customer Experience Principles

1. Allow guest browsing; do not force account creation before browsing.
2. Make product price, unit, variant and availability obvious.
3. Allow quick add-to-cart from product listing where no variant ambiguity exists.
4. If a product has variants, require a clear variant selection.
5. Keep cart persistent across navigation and failed payment attempts.
6. Explain delivery serviceability before payment.
7. Show delivery fee before the customer commits to payment.
8. Use OTP verification at checkout/account access.
9. Make order status understandable.
10. Provide a visible Need Help option on order and subscription pages.
11. Explain skip, pause, refund and cancellation rules before subscription purchase.
12. Show genuine customer reviews and clearly identify verified purchases.
13. Use helpful empty states, loading states, error messages and retry options.
14. Never use fake urgency, fake countdowns, fabricated ratings or misleading discount percentages.

---

# 5. Public Website Pages and Navigation

## 5.1 Main pages

- Homepage: `index.php`
- Search: `search.php`
- Category listing: `category.php`
- Product details: `product.php`
- Cart: `cart.php`
- Checkout: `checkout.php`
- Order success/status: `order.php` or equivalent secure route
- CMS page: `page.php`
- Login/OTP: `login.php`, `verify-otp.php`
- Customer account: `my-account.php`
- Orders: `my-orders.php`
- Order details: `order-details.php`
- Subscriptions: `my-subscriptions.php`
- Subscription details: `subscription-details.php`
- FAQs: `faqs.php`
- About Us: `about-us.php` or CMS-managed route
- Contact: `contact.php`
- Refund/cancellation policy
- Shipping/delivery policy
- Privacy policy
- Terms and conditions
- Subscription terms
- 404 page

Use clean URLs with rewrite rules where hosting supports them. Retain safe fallback routes if rewrite configuration is unavailable.

## 5.2 Header and navigation

- Logo and brand name.
- Delivery location/serviceability selector.
- Search input with suggestions.
- Category navigation.
- Account/login.
- Cart count and cart access.
- Mobile navigation menu.
- Optional sticky header, but do not consume too much mobile screen space.

## 5.3 Footer

- About Veggie Tokri.
- FAQs.
- Contact and WhatsApp support.
- Delivery information.
- Refund and cancellation policy.
- Privacy policy.
- Terms and conditions.
- Subscription terms.
- FSSAI registration/licence details where applicable.
- Business contact details and operating hours.
- Social links only when real accounts exist.

---

# 6. Homepage – Dynamic Sections and Trust Building

Homepage must be database-driven. Admin can add, edit, reorder, enable/disable and schedule sections.

## 6.1 Homepage sections

1. Hero banner with headline, image and CTA.
2. Delivery location/serviceability checker.
3. Main category cards.
4. Fresh vegetables.
5. Fresh fruits.
6. Ready-cut vegetables.
7. Cut fruits.
8. Pickles, papad and chutneys.
9. Best sellers.
10. Featured products.
11. New arrivals.
12. Genuine offers and discounts.
13. Trending products, if based on real data.
14. Subscription packages.
15. How It Works.
16. Why Choose Veggie Tokri.
17. Quality and packing information.
18. Genuine approved customer reviews/testimonials.
19. FAQs preview (top 5–6 questions with View All FAQs).
20. Short About Us introduction with Read More.
21. Contact and support information.
22. Footer.

## 6.2 Homepage section controls

Admin should be able to:

- Create section title/subtitle.
- Select products manually.
- Select by category.
- Select by bestseller/new-arrival/featured flags.
- Select by automatic business rules.
- Configure product count.
- Configure desktop/mobile visibility.
- Set display order.
- Schedule start/end date.
- Enable/disable section.
- Set CTA text and destination.
- Preview before publishing.

## 6.3 Trust content

Show genuine and verifiable information:

- Freshness and quality-check process.
- Proper packing and handling practices.
- Local Indore delivery information.
- Transparent pricing and delivery charges.
- Actual support contact and operating hours.
- Real customer reviews.
- Applicable FSSAI registration/licence information.
- Clear refund and complaint process.

Do not claim “organic”, “chemical-free”, “farm fresh”, “same-day delivery”, “100% safe” or similar unless the business can substantiate the claim and it is legally appropriate.

---

# 7. About Us, FAQs and Trust/Transparency Module

## 7.1 About Us page

The About Us page should explain:

1. What Veggie Tokri is.
2. Why the business was started.
3. What customer problem it aims to solve in Indore.
4. Product categories offered.
5. How products are sourced, selected and checked (only actual process).
6. Packing and delivery process.
7. Customer support and complaint handling.
8. Business contact details.
9. Applicable registration/licence details.

Suggested initial copy (must be reviewed against actual business operations):

“Veggie Tokri ka maksad hai Indore ke gharon tak fresh fruits, vegetables aur rozmarra ke food products ko aasani se pahunchana. Hum chahte hain ki aapko apni daily shopping ke liye baar-baar alag-alag jagah na jaana pade. Fresh vegetables aur fruits se lekar pickles, papad aur chutneys tak, aapki zaroorat ki cheezein ek hi jagah mil sakein. Hamari priority convenient shopping, transparent pricing, proper packing aur responsive customer support dena hai.”

Admin can edit content, images, sections and publish status.

## 7.2 FAQ categories

- General
- Products and quality
- Orders
- Delivery
- Payments
- Cancellation and refunds
- Subscriptions
- Account and OTP
- Reviews and testimonials
- Support and complaints

Example questions:

- Veggie Tokri kya hai?
- Kya Indore mein delivery available hai?
- Delivery area kaise check karein?
- Product availability kaise check karein?
- Product ka weight/variant kaise select karein?
- Delivery charges kya hain?
- Same-day delivery available hai?
- Order modify ya cancel kaise karein?
- Payment fail ho to kya karein?
- Damaged ya poor-quality product mile to kya karein?
- Refund kitne samay mein process hota hai?
- Subscription delivery skip karne par kya hoga?
- Review kaise submit karein?
- Review website par kab dikhega?
- Customer support se kaise contact karein?

FAQs must be editable and categorized by admin. Allow ordering, enable/disable and search. Show relevant FAQs on product, checkout, order, subscription and refund pages where useful.

## 7.3 Contextual trust information

Do not repeat the same large block everywhere. Display relevant information on each page:

- Homepage: quality promise, customer reviews, About Us, FAQ preview.
- Product listing: actual product images, price, unit, availability.
- Product details: full description, storage/handling, ingredients where relevant, reviews.
- Cart: price breakdown, discounts, delivery fee.
- Checkout: delivery address, ETA/slot, payment method and final amount.
- Payment failure: preserve cart and show retry/support.
- Order details: timeline, delivery details and Need Help.
- Subscription: schedule, skip/pause rules and remaining deliveries.
- Refund page: eligibility, policy and request status.
- Footer: About, FAQs, contact and legal policies.

---

# 8. Product Catalogue and Product Details Page

## 8.1 Categories and subcategories

Admin CRUD for:

- Name.
- Slug.
- Parent category.
- Description.
- Image/icon.
- Display order.
- Active/inactive.
- Homepage visibility.
- SEO fields.
- Optional category-specific attributes.

## 8.2 Product fields

- Product name.
- Slug.
- SKU or product code.
- Category/subcategory.
- Short description.
- Full description.
- Multiple images.
- Optional video.
- Product status.
- Featured/bestseller/new-arrival flags.
- Product type (fresh, cut/prepared, packaged, etc.).
- Storage instructions.
- Preparation instructions.
- Ingredients and allergens where applicable.
- Net weight/quantity.
- Shelf-life/expiry details where applicable.
- Manufacturer/packer and mandatory packaged-food details where applicable.
- SEO fields.
- Related products.
- Delivery restrictions, if any.

## 8.3 Product variants

Each variant should have:

- Variant name.
- Weight/size/unit.
- SKU.
- Regular price.
- Sale price.
- Available stock.
- Low-stock threshold.
- Active/inactive status.
- Optional barcode.
- Optional variant-specific image.

Examples: 250 g, 500 g, 1 kg. Every variant has its own price and stock.

## 8.4 Product details page – required layout

### A. Image gallery

- Multiple product images (recommended 3–5 where available).
- Main image with thumbnails.
- Click/tap zoom or full-screen viewer.
- Previous/next navigation.
- Mobile swipe support.
- Optional video.
- Admin can upload, reorder, delete and choose primary image.
- Lazy loading and optimized image sizes.
- Relevant images only; do not show generic unrelated photos.

### B. Product summary

- Product name.
- Category/breadcrumb.
- SKU (optional customer visibility).
- Average rating and review count.
- Stock/availability.
- Regular price and sale price.
- Genuine discount display.
- Variant/weight selector.
- Quantity selector.
- Add to Cart.
- Buy Now.
- Estimated delivery availability based on customer location.

### C. Product description and attributes

Use category-specific attributes rather than forcing identical fields on every product.

Examples:

**Fresh vegetables/fruits**
- Product description.
- Approximate weight/size information.
- Origin/source only when known.
- Storage guidance.
- Best-use suggestions.
- Freshness/handling information based on actual process.

**Cut vegetables/cut fruits**
- Cut type/size.
- Pack size/net weight.
- Preparation-on-order information if applicable.
- Storage and use-by guidance.
- Preparation/handling information.

**Pickles/chutneys**
- Ingredients.
- Net weight.
- Allergen information where applicable.
- Storage instructions.
- Shelf-life/expiry.
- Manufacturer/packer details and mandatory declarations as applicable.

**Papad**
- Ingredients.
- Pack quantity/net weight.
- Preparation instructions.
- Storage.
- Allergen and mandatory packaged-food declarations as applicable.

### D. Product reviews

- Overall average rating.
- Rating distribution (1–5 stars).
- Written reviews.
- Product quality/freshness/packaging/delivery sub-ratings.
- Customer review photos.
- Verified Purchase badge.
- Review date and display name.
- Admin reply.
- Report review.
- Pagination or load more.
- Show only approved reviews.

### E. Related products

- Similar products.
- Frequently bought together.
- Customers also viewed.
- Related category products.
- Recently viewed products.

Admin can choose products manually or configure automatic selection. Do not show disabled products or misleading unavailable products.

## 8.5 Product review eligibility

- Customer must have a delivered order containing that product.
- Review eligibility must be checked server-side.
- Define one review per eligible order item, with a controlled edit/update policy.
- Customer can submit rating, written review and optional photos.
- Review status: Submitted/Pending Approval, Approved, Rejected.
- Rejection reason visible to customer.
- Admin can reply, approve, reject, feature or report.
- Genuine negative reviews must not be rejected merely because they are negative.
- Moderate spam, abuse, privacy violations, unrelated content and prohibited material.
- Do not fabricate reviews or ratings.

---

# 9. Search, Filters and Product Discovery

- Search products by name, SKU and relevant keywords.
- Search suggestions/autocomplete.
- Search results page.
- Category filters.
- Price range filter.
- Availability filter.
- Product type filter.
- Sort by relevance, price low-high, price high-low, newest and popularity where data supports it.
- Pagination or load more.
- Preserve filters when navigating pages.
- Helpful no-result state and suggested categories.
- Search logs/analytics with privacy-conscious retention.
- Do not expose unpublished or restricted products.

---

# 10. Cart and Checkout

## 10.1 Cart

- Add/update/remove items.
- Variant-aware cart lines.
- Quantity controls.
- Server-side price and stock revalidation.
- Coupon entry and removal.
- Subtotal.
- Discounts/savings.
- Delivery fee.
- Taxes if applicable.
- Final total.
- Free-delivery threshold message only when configured.
- Preserve cart for guest and logged-in customer.
- Handle stock/price changes with a clear message.
- Do not let client-side totals determine the payable amount.

## 10.2 Checkout

- Guest checkout with mobile OTP verification.
- Customer name and mobile.
- Address selection/addition.
- Map pin/location support where configured.
- Delivery instructions.
- Delivery slot or automatic delivery estimate.
- Coupon summary.
- Full price breakdown.
- Payment method.
- Terms/policy acknowledgement where required.
- Final confirmation.

Do not create duplicate orders on repeated clicks. Use idempotency/duplicate submission protection.

## 10.3 Customer account

- OTP-based login.
- One customer account per normalized mobile number.
- Saved addresses.
- Profile details.
- Order history.
- Payment history.
- Refund requests/status.
- Subscription plans and delivery schedule.
- Coupons.
- Reorder.
- Review submission and review status.
- Support tickets.
- Account data/privacy controls.

Admin must never see customer OTPs or plaintext passwords.

---

# 11. Delivery Serviceability and Delivery Management

## 11.1 Initial delivery model

- One dispatch/store location initially.
- Admin configures store latitude/longitude.
- Admin configures maximum delivery radius or serviceable zones.
- Customer enters address and optionally selects map pin.
- Leaflet + OpenStreetMap may be used for map interaction.
- PHP Haversine formula for straight-line radius validation.
- Block checkout outside serviceable area unless an authorized admin override is recorded.
- Clearly tell customer when address is not serviceable.

Important: straight-line radius is not road distance. If charging by road distance, use a suitable routing provider. Public geocoding/routing services have usage policies, rate limits and uptime constraints; do not assume free public endpoints can support unlimited commercial autocomplete.

## 11.2 Delivery fees

Support configurable rules:

- Flat fee.
- Distance slabs.
- Free delivery above cart threshold.
- Product-level free delivery.
- Category-level free delivery.
- Promotional free delivery.

Define mixed-cart priority explicitly. A free-delivery product must not silently make the entire mixed cart free unless that is the configured rule.

Store the final delivery fee and relevant distance/zone snapshot on the order.

## 11.3 Delivery slots

Admin can configure:

- Enable/disable slots.
- Slot start/end times.
- Slot duration.
- Per-slot capacity.
- Same-day delivery.
- Cutoff time.
- Holidays/closed dates.
- Preparation buffer.
- Lead time.

If slots are disabled, show an automatic delivery estimate based on business hours, preparation time and delivery capacity.

Revalidate slot capacity at checkout. A pending payment must not reserve a slot indefinitely. Use Asia/Kolkata timezone.

## 11.4 Delivery operations

- Delivery queue.
- Assign delivery person/driver.
- Delivery status updates.
- Delivery notes.
- Proof of delivery if required.
- Delivery exception handling.
- Customer notifications.
- Delivery history and audit.

---

# 12. Inventory and Stock Management

- Stock at variant level.
- Stock adjustment by admin.
- Stock movement history.
- Low-stock threshold.
- Out-of-stock handling.
- Inventory report.
- Stock reservation/decrement in transactions.
- Prevent overselling using row locking or equivalent concurrency-safe logic.
- Define stock release on payment failure/timeout.
- Define stock restoration on cancellation/refund.
- Do not automatically return perishable returned goods to sellable stock.
- Support prep capacity/daily limits for cut products and made-to-order items.
- Record who changed stock, old quantity, new quantity and reason.

---

# 13. Offers, Discounts and Coupons

## 13.1 Automatic offers

Support:

- Store-wide offers.
- Category offers.
- Product offers.
- Variant offers.
- First-order offers.
- Subscription-only offers.
- Free delivery.
- Date/time-bound offers.
- Customer-specific offers.

## 13.2 Coupons

Fields/rules:

- Coupon code.
- Fixed or percentage discount.
- Minimum cart value.
- Maximum discount cap.
- Start/end date.
- Total usage limit.
- Per-customer usage limit.
- First-order eligibility.
- Product/category/subscription eligibility.
- Excluded products.
- Assigned customer(s).
- Stacking rules.
- Priority.
- Active/inactive.

All coupon eligibility and discount calculations must be validated server-side. Prevent race conditions and duplicate usage.

---

# 14. Orders and Order Lifecycle

## 14.1 Suggested order statuses

- Pending Payment
- Payment Failed
- Confirmed
- Accepted
- Preparing
- Ready
- Out for Delivery
- Delivered
- Cancelled
- Delivery Exception
- Partially Refunded
- Refunded

Use a controlled transition map. Do not allow arbitrary status changes without permission and audit.

## 14.2 Order records

Store immutable snapshots of:

- Customer details needed for fulfilment.
- Delivery address.
- Product name and variant.
- SKU.
- Quantity.
- Unit price.
- Discount.
- Tax if applicable.
- Delivery fee.
- Coupon.
- Final payable amount.
- Selected slot/estimated delivery.
- Relevant policy version where appropriate.

## 14.3 Order history

- Status timeline.
- Payment details.
- Delivery details.
- Items and quantities.
- Invoice/receipt where applicable.
- Cancel request if allowed.
- Refund request.
- Need Help.
- Reorder.

---

# 15. Payment Gateways – Razorpay and Cashfree

## 15.1 Admin configuration

For each gateway:

- Enable/disable.
- Test/live mode.
- Key ID/app ID.
- Secret.
- Webhook secret.
- Test connection/configuration where supported.
- Masked credentials.
- Save/update history and audit.

## 15.2 Payment flow

- Create payment order/session on the server.
- Verify gateway response/signature server-side.
- Validate amount, currency and internal order reference.
- Do not mark order paid based only on browser callback.
- Process signed webhooks.
- Verify webhook signature.
- Store unique gateway event IDs and prevent duplicate processing.
- Make webhook processing idempotent.
- Reconcile delayed/uncertain payments.
- Provide retry payment without losing cart.
- Never store card number, CVV or sensitive payment instrument data.

## 15.3 Payment history

Store:

- Internal payment ID.
- Order ID.
- Gateway.
- Gateway order/payment reference.
- Amount and currency.
- Status.
- Event/reference IDs.
- Request/response metadata after redaction.
- Created/updated timestamps.

---

# 16. Refunds, Replacements and Cancellations

Admin must control the review/approval workflow, while gateway refunds must be executed and verified through the payment gateway.

## 16.1 Customer refund/request flow

Customer can request help from order details:

- Quality issue.
- Damaged product.
- Wrong product.
- Missing item.
- Order not delivered.
- Duplicate payment.
- Cancellation.
- Other.

Customer provides:

- Affected order item(s).
- Quantity.
- Description.
- Optional photo/evidence.
- Preferred resolution: refund, replacement or other configured option.

## 16.2 Admin refund controls

Admin can:

- Review request and evidence.
- Approve or reject with mandatory reason.
- Approve full or partial refund.
- Approve replacement.
- Offer store credit only if policy allows and customer accepts.
- Initiate actual gateway refund.
- Track gateway refund reference/status.
- Add internal notes.
- View complete history.

Do not let admin manually mark a gateway payment as refunded without actual gateway confirmation.

## 16.3 Refund statuses

- Requested
- Under Review
- Approved
- Rejected
- Refund Initiated
- Processing
- Refunded
- Refund Failed
- Cancelled

Approval and successful gateway refund are separate events.

## 16.4 Refund safety rules

- Refund cannot exceed captured amount.
- Cumulative partial refunds cannot exceed captured amount.
- Use idempotency and gateway refund IDs.
- Reconcile refund status through webhook/API.
- Keep immutable refund history.
- Restore stock separately; perishable items must not automatically become sellable.
- Subscription refunds use a separate calculation/policy.
- Valid customer subscription skips are carry-forward, not refund, unless the applicable policy says otherwise.
- Do not create blanket “no refund under any circumstances” rules for perishable food.
- Applicable consumer protection laws, food regulations and payment gateway rules override internal settings.

## 16.5 Cancellation policy

Admin-configurable policy:

- Customer cancellation allowed before/after acceptance.
- Cutoff by order status/time.
- Product-specific restrictions where lawful.
- Admin override with reason and audit.
- Refund treatment for each cancellation case.

---

# 17. Subscription System

Subscriptions are prepaid packages for repeat deliveries. This module must be visually clear to customers and operationally safe.

## 17.1 Plan types

Support:

- **Admin Custom Subscription Plan Builder (§17.12):** admin creates plans with **custom durations** and full rule configuration; not limited to fixed SKUs.
- **Default templates:** on install/seed, provide editable **7-day, 15-day and 30-day** vegetable subscription templates; admin may clone, edit or deactivate them.
- Fixed admin-defined packages and customizable daily baskets (§17.11).
- Admin-configured allowed **delivery frequencies** per plan (§17.2).
- Plan-specific delivery, skip, pause and slot rules (snapshotted at purchase — §17.12).
- Product/variant quantities and **daily basket allowance / quantity rules** (plan-level; day-level in §17.11).
- Plan price and payment rules (§17.4, §17.12).
- Start date, delivery address, delivery slot or automatic estimate.

Each plan page must explain **delivery count**, **calendar duration**, **skip/carry-forward**, **pause/resume** and **daily basket / selection deadline** rules before purchase (§17.5, §17.6, §17.11, §17.12).

Customers may purchase **only plans with `Active` (published) status** set by admin (§17.12).

## 17.2 Frequency options

Admin can enable any combination of:

- Daily.
- Alternate day.
- Weekly.
- Selected weekdays.
- Custom interval.

Customer can select only frequencies enabled by admin for that plan.

## 17.3 Plan duration model – configurable per plan

Do not hardcode one global model. Each plan should have a duration type:

1. **Fixed delivery count:** e.g. 7 deliveries. Valid skips carry forward and remaining deliveries are completed later.
2. **Fixed calendar duration:** e.g. 30 calendar days. The plan expires on the defined date unless the plan's pause/extension policy extends it.
3. **Hybrid/configurable:** admin selects delivery count, calendar duration or both, with explicit expiry/extension rules.

The plan page must clearly explain which model applies before purchase. Admin must not configure contradictory rules. **Server-side validation** must reject invalid combinations (duration vs delivery count vs frequency — §17.12).

## 17.4 Advance payment

- 100% advance payment.
- Subscription activates only after verified gateway payment/webhook.
- Do not activate from browser success alone.
- Payment record and invoice/receipt.
- Show paid amount and subscription status.

## 17.5 Skip and carry-forward rules

Recommended default model, configurable by admin:

- Customer can skip a future delivery before the configured cutoff.
- Valid skip becomes a carry-forward delivery credit.
- Remaining delivery count stays unchanged.
- Schedule extends by one eligible delivery day for each valid skip.
- No refund or wallet credit for a valid customer skip.
- After cutoff, online skip is disabled.
- Admin override requires reason and audit.
- Max skip count and allowed skip window are configurable.

Example for a fixed 7-delivery package:

- 5 deliveries completed.
- 2 valid deliveries skipped.
- 2 deliveries remain.
- Schedule extends by 2 eligible delivery days.

This is an illustrative example, not a fixed package rule.

## 17.6 Pause rules

Pause is separate from skip.

- Admin can enable/disable pause.
- Admin configures maximum pause duration/count.
- Define whether pause extends the schedule.
- Recommended: extend by paused eligible delivery days when the plan's duration model allows it.
- Show pause consequences before confirmation.
- Record start/end, reason and audit.

## 17.7 Delivery generation

- Create individual subscription delivery records.
- Generate rolling 2–3 day schedule, not all future orders at once.
- Cron must be idempotent.
- Use unique subscription/date or delivery sequence constraints.
- Use transactions/locking to prevent duplicate deliveries.
- Handle holidays, slot capacity and customer skips.
- Business-caused failure must follow a separate carry-forward/credit/refund policy.

Suggested delivery statuses:

- Scheduled
- Preparing
- Ready
- Out for Delivery
- Delivered
- Skipped – Carry Forward
- Cancelled – Exception

## 17.8 Customer subscription dashboard

Must visually show:

- Plan name and selected items (or “suggested daily basket” summary where applicable).
- Total deliveries or plan duration.
- Completed deliveries.
- Skipped/carry-forward deliveries.
- Remaining deliveries.
- Next delivery date/time.
- **Day-wise subscription calendar** with planned basket per delivery day (§17.11).
- Current address.
- Pause/skip controls when eligible.
- Clear cutoff information (skip cutoff and **vegetable selection deadline** per day).
- Payment/receipt history.
- Help/support.
- Change history.

Do not show only confusing raw counts. Explain in plain language why the schedule has moved.

The subscription calendar must be **responsive and usable on mobile and desktop** (touch-friendly day cells, bulk actions, readable basket lines).

## 17.9 Admin subscription controls

- **Custom Subscription Plan Builder** (§17.12): create/edit/clone plans with custom duration, frequency, delivery count, basket rules, price, payment/skip/pause/slot policies, Active/Inactive.
- Create/edit/enable plans (seed **7 / 15 / 30-day** templates editable by admin).
- Fixed/custom package settings.
- Plan frequency options.
- Duration model.
- Skip cutoff and max skips.
- Pause rules.
- Pricing and discount.
- **Subscription menu planner:** configure date/day suggested vegetable baskets, quantities, prices, availability flags and **selection deadlines** (§17.11).
- Product substitution policy (no silent swaps — §17.11).
- Delivery capacity.
- Customer subscriptions.
- Delivery schedule and exceptions.
- **Customer-wise daily baskets:** view suggested, customized and confirmed quantities for inventory planning (§17.11).
- Refund/credit handling.
- Subscription reports.

## 17.11 Smart Subscription Calendar & Flexible Daily Basket

**Product decision (final):** Veggie Tokri subscriptions centre on a **customer-facing day-wise calendar**. Customers are **not** required to visit the site every day to choose vegetables; the business provides **automatic suggested daily baskets**, with **optional** customization before a deadline.

### 17.11.1 Plans and calendar

1. Customers purchase subscription plans **created and published by admin** (including default **7-, 15- and 30-day templates** and any **custom duration** plans — §17.12).
2. Every active subscription must expose a **day-wise calendar** covering each scheduled delivery day in the plan.
3. Each calendar day shows the **planned vegetable basket** for that delivery (names, quantities and **prices** visible to the customer).
4. Calendar must work on **mobile and desktop** (list and/or month/week views acceptable if day detail remains clear).

### 17.11.2 Suggested baskets (automatic)

5. The system **generates suggested daily baskets automatically** using:
   - Admin-configured **vegetable menus / menu planner** (by date or day template),
   - **Stock/catalogue availability**,
   - **Customer preferences** (where captured),
   - **Rotation rules** (avoid repetition; admin-configurable).
6. Customers **must not** be required to log in daily merely to accept the default basket.
7. If the customer makes **no changes before the selection deadline**, the **suggested basket is auto-confirmed** for that delivery day (server-side job; not client timer alone).

### 17.11.3 Customer customization

8. Customers may **optionally customize** vegetables for any **upcoming** delivery day from the calendar.
9. Customers may customize **multiple days in one session** (bulk select days → edit basket).
10. Each delivery day supports exactly one of these modes (stored explicitly):
    - **Use suggested basket** (default path),
    - **Customize basket** (customer-edited lines),
    - **Skip delivery** (subject to §17.5 skip/carry-forward rules).
11. Customization UI must show **vegetable name, quantity and price** per line; totals for the day must match server recalculation.

### 17.11.4 Unavailability and substitutions

12. If a selected or suggested vegetable becomes **unavailable** before fulfilment, the system **must not silently replace** it.
13. Offer a **proposed alternative** (admin rules + available stock); require **customer approval** when policy demands (configurable per plan or globally).
14. Until approved (or admin override per policy), the delivery day remains in an **exception** state visible to customer and admin.

### 17.11.5 Admin menu planner and operations

15. Admin **subscription menu planner** must allow configuring:
    - Suggested baskets by **calendar date** or **plan day index**,
    - Vegetable SKUs/variants, quantities, **unit prices** (snapshot for subscription delivery),
    - Availability / “include in rotation” flags,
    - **Selection deadline** (cutoff) per delivery day or plan rule (timezone: Asia/Kolkata).
16. Admin must **view customer-wise daily baskets**: suggested vs customized vs **confirmed** quantities for **inventory and prep planning**.
17. Confirmed baskets feed delivery generation and stock reservation/allocation per Stage 8 delivery rules.

### 17.11.6 Rules transparency

18. Subscription terms (CMS + checkout) must clearly define for each plan:
    - **Delivery count** vs **calendar duration** (§17.3),
    - **Skip** handling and **carry-forward**,
    - **Pause** and **resume**,
    - **Selection deadline** and auto-confirm behaviour,
    - Substitution / approval policy.

### 17.11.7 Technical principles

- All basket contents, skip/customize/confirm actions and cutoff checks are **validated server-side**.
- Idempotent cron for: suggestion generation, auto-confirm at deadline, delivery record creation (§17.7).
- Immutable **price/name snapshots** on confirmed basket lines for fulfilment and support.
- Audit log for admin overrides, substitution approvals and skip/pause changes.

## 17.12 Admin Custom Subscription Plan Builder

Admin must be able to **create, edit, clone and publish** subscription plans without developer intervention.

### 17.12.1 Plan fields (admin-configurable)

For each plan, admin configures:

| Area | Requirements |
|------|----------------|
| **Identity** | Plan name, description (customer-facing), internal notes (optional) |
| **Duration** | **Calendar duration (days)** — how long the subscription calendar window runs (separate field; §17.13.1) |
| **Delivery count** | Total **delivery occurrences** — separate from calendar duration; schedule must match both (§17.13.1) |
| **Frequency** | **Daily**, **alternate days**, **selected weekdays**, or **custom interval** (every N days); validated against duration + count |
| **Basket rules** | Daily basket **allowance** and item quantity rules; unused allowance policy; over-allowance approval (§17.13.2) |
| **Pricing** | Total subscription price (INR); optional compare-at price; payable amount server-calculated at checkout |
| **Payment rules** | Prepaid 100%; Stage 9 gateway; ties to subscription payment records |
| **Skip / pause / carry-forward** | Per-plan limits and cutoffs (§17.5–17.6) |
| **Delivery slots** | Slot required / auto ETA / plan-specific allowed slots (Stage 8) |
| **Status** | **Active (published)** or **Inactive** — storefront lists **Active only** |

### 17.12.2 Default templates (7 / 15 / 30 days)

- Migration/seed creates **editable default templates** for 7-, 15- and 30-day vegetable plans (`is_default_template` or equivalent flag).
- Admin may change duration, count, price and all rules, or **deactivate** templates.
- **Clone** template → new custom plan.

### 17.12.3 Calendar generation from plan rules

- On subscription **activation** (after verified payment), **server generates** the day-wise calendar from snapshotted plan rules: start date, duration, frequency, delivery count (Asia/Kolkata).
- Admin plan edits **never** mutate existing subscription calendars; only snapshot + skip/pause/carry-forward logic extends schedule.

### 17.12.4 Plan snapshot at purchase (immutability)

- `customer_subscriptions.plan_snapshot_json` stores immutable rules and **price paid** at activation (plus `subscription_plan_id` reference for reporting).
- Fulfilment, skip/pause, basket allowance and customer UI read **snapshot**, not live plan row.
- Plan version increment optional for admin audit when publishing changes.

### 17.12.5 Server validation

On **admin save/publish** and **customer checkout**:

- Validate duration, delivery count, frequency, price, hybrid duration model consistency.
- **Calendar dry-run:** preview list of delivery dates before publish.
- Block or warn on impossible combinations (admin-configurable strict mode default: **block publish** on validation errors).

### 17.12.6 Storefront

- Only **Active** plans on subscription listing and checkout.
- Checkout displays terms from **server-rendered** plan snapshot for that purchase session.

## 17.13 Subscription safeguards (Stage 11 — approved)

### 17.13.1 Calendar duration vs delivery count

- **`calendar_duration_days`** and **`delivery_count`** are **separate** admin-configured fields on every plan (also stored in `plan_snapshot_json`).
- Supported schedules: **daily**, **alternate-day**, **selected weekdays**, **custom interval** (every N days).
- **Server-generated schedule** must be validated against **both** values:
  - Exactly **`delivery_count`** delivery dates (unless plan explicitly documents hybrid exception in snapshot).
  - Every delivery date falls within `[start_date, start_date + calendar_duration_days − 1]` (Asia/Kolkata).
- Admin plan builder shows **dry-run preview** of dates before publish; invalid combinations **block save** (default).

### 17.13.2 Basket allowance accounting

- Daily **allowance** (e.g. INR cap) and **per-item quantities** must be **visible** to the customer on each calendar day.
- Admin configures **`unused_allowance_policy`**: **`expire`** (default) or **`carry_forward`** (to next delivery day only, per plan rules — document in snapshot).
- If customized basket **exceeds allowance**, require **explicit customer approval** before confirm/charge adjustment; show overage clearly.
- **Never silently charge** additional amounts beyond prepaid subscription price; over-allowance cannot create hidden charges — only block, trim, or approved exception flows documented in terms.

### 17.13.3 Basket cutoff, locking and audit

- All cutoffs use **`Asia/Kolkata`**.
- Each delivery day has a **selection cutoff** (from plan/snapshot); after cutoff:
  - Customer **cannot** change basket/skip/customize (except admin override with audit).
  - **Confirmed** baskets are **locked**; cron/system must **not silently overwrite** confirmed content.
- **Audit trail** (`subscription_selection_audit` or equivalent) for: customer customize, confirm, auto-confirm, skip; admin override; substitution approval.

### 17.13.4 Subscription lifecycle and payment safety

**Customer subscription statuses (minimum):**

| Status | Meaning |
|--------|---------|
| `pending_payment` | Created at checkout; awaiting Stage 9 verification |
| `active` | Paid; calendar generated; fulfilment ongoing |
| `paused` | Temporarily suspended per plan policy |
| `cancelled` | Terminated (customer or admin); no new deliveries |
| `expired` | Calendar/delivery entitlement exhausted or end date passed |
| `refunded` | Refund completed per policy (Stage 12 gateway tie-in) |

**Rules:**

- **Failed payment** leaves or returns subscription in `pending_payment` / does not activate calendar.
- **Duplicate payment webhooks** idempotent (Stage 9 pattern); no duplicate activation or duplicate calendar generation.
- **Cancellation/refund calculation** documented per plan snapshot (pro-rata undelivered deliveries vs policy); admin-initiated refund requires gateway confirmation (Stage 12).
- **Unique constraints** prevent duplicate calendar days and duplicate subscription delivery orders for the same subscription/date.
- **Pause/resume** preserves **remaining delivery entitlement** per snapshotted plan policy (extend calendar end, do not consume deliveries while paused).

## 17.10 Accounting note

Prepaid subscription collections should be tracked separately from delivered sales for operational/accounting reporting. Treat undelivered package value as an outstanding fulfilment liability in reports; confirm the final accounting treatment with the business accountant.

---

# 18. Customer Reviews, Testimonials and Moderation

## 18.1 Product reviews

- Only delivered/purchased customers can submit verified product reviews.
- 1–5 star rating.
- Written review.
- Optional ratings for freshness, quality, packaging and delivery.
- Optional customer photo.
- Display name preference.
- Customer sees review submission status.
- Admin approval required before public display.

## 18.2 Review statuses

- Submitted / Pending Approval
- Approved
- Rejected

Customer should see a clear status and rejection reason where appropriate.

## 18.3 Admin moderation

- Review queue.
- Filter by product, rating and status.
- View customer/order verification.
- View photo attachments.
- Approve/reject.
- Mandatory rejection reason.
- Reply to review.
- Feature review on homepage.
- Report/moderation history.
- Do not reject genuine negative reviews merely for being negative.

## 18.4 Testimonials

Testimonials are separate from product reviews.

- Admin can invite/select customers.
- Customer permission/consent required for publication.
- Optional photo/video/story.
- Admin approval.
- Homepage display order and featured flag.
- Record consent details and revocation handling.

Never fabricate customer names, photos, ratings or testimonial text.

---

# 19. Customer Support and Complaint Tickets

## 19.1 Initial support channels

- WhatsApp Business click-to-chat.
- Business phone.
- Business email.
- Website support ticket.

Start with WhatsApp Business and a simple PHP ticket module. Do not add expensive CRM/live chat until volume justifies it.

## 19.2 Order-specific support

Need Help button on order details. Complaint categories:

- Product quality.
- Damaged product.
- Wrong product.
- Missing product.
- Late delivery.
- Payment issue.
- Refund issue.
- Subscription issue.
- Other.

## 19.3 Ticket statuses

- Open
- Assigned
- In Progress
- Resolved
- Closed

Customer sees ticket status timeline and replies. Admin can assign, reply, add internal notes, resolve and view history.

Support response targets such as 30–60 minutes during business hours are only examples. Do not promise an SLA unless the business can consistently meet it.

## 19.4 FAQ and support management

- Admin-configured support phone/WhatsApp/email.
- Business hours.
- FAQ categories.
- FAQ search.
- Ticket history.
- Customer satisfaction feedback.
- Support analytics.

---

# 20. CMS and Legal Pages

Use a safe rich text editor such as CKEditor only after reviewing licensing and deployment requirements. A simpler sanitized editor is acceptable.

Admin-managed pages:

- About Us.
- Contact Us.
- Privacy Policy.
- Terms and Conditions.
- Refund and Cancellation Policy.
- Shipping and Delivery Policy.
- Subscription Terms.
- Disclaimer.
- Other custom pages.

Features:

- Draft/published.
- Slug.
- SEO title/description.
- Footer visibility.
- Publish schedule if needed.
- Version history.
- Preview.
- Sanitized HTML.
- Safe image upload.
- No arbitrary script insertion.

Legal content must be reviewed for actual business operations and applicable Indian consumer, food, labelling, tax and privacy requirements. Do not claim legal compliance merely because a page exists.

---

# 21. SEO Management – Admin and Technical SEO

SEO controls must exist for homepage, products, categories, subcategories and CMS pages. Future blog pages should use the same SEO system.

## 21.1 Admin SEO fields

For each eligible page:

- SEO title / meta title.
- Meta description.
- URL slug.
- Focus keyword (editorial guidance only; not a ranking guarantee).
- Canonical URL (optional; automatic self-canonical by default).
- Open Graph title.
- Open Graph description.
- Open Graph/social sharing image.
- Robots index/noindex.
- Robots follow/nofollow.

SEO settings should be in a collapsible “SEO Settings” section so ordinary product entry remains simple.

## 21.2 Automatic slug generation

- Generate slug from product/category/page name.
- Lowercase.
- Convert spaces to hyphens.
- Remove unsafe/special characters.
- Normalize repeated hyphens.
- Support Hindi/Unicode input with configurable transliteration or allow admin to enter a custom English slug.
- Check uniqueness server-side.
- If duplicate, generate a unique suffix.
- Admin can manually edit slug.
- Validate allowed characters and length.
- Database unique index must enforce uniqueness.
- Do not trust client-side slug generation alone.

Examples:

- `Fresh Red Tomatoes` → `fresh-red-tomatoes`
- `Green Capsicum 500g` → `green-capsicum-500g`
- `Homemade Mango Pickle` → `homemade-mango-pickle`

## 21.3 Slug changes and redirects

- When a published slug changes, preserve the old URL.
- Create a 301 redirect from old URL to new URL.
- Prevent redirect loops and chains.
- Normalize trailing slash/case consistently.
- Validate destination is internal or explicitly allowlisted.
- Do not create redirect records for failed/unsaved edits.
- Use a transaction for slug update and redirect creation.

## 21.4 Technical SEO

- XML sitemap generated dynamically.
- Include only published, canonical and indexable URLs.
- `robots.txt`.
- Self-referencing canonical by default.
- Open Graph tags.
- Product structured data (Schema.org JSON-LD).
- Breadcrumb structured data.
- Product price, currency and availability based on actual database values.
- Aggregate rating/review schema only when genuine eligible reviews exist and page visibly displays them.
- No fabricated structured data.
- Clean URLs.
- Correct 404 response and page.
- 301 redirects.
- Noindex for admin, cart, checkout, account, private order pages and internal search/filter combinations where appropriate.
- Configurable out-of-stock product indexing policy.
- Correct pagination canonical strategy.
- Avoid duplicate title/description.
- Sitemap updates after publish/unpublish or SEO changes.
- Social sharing preview image.

## 21.5 SEO security and correctness

- Escape meta output.
- Validate canonical URLs.
- Do not allow arbitrary scripts in SEO fields.
- Structured data must use actual values.
- Do not keyword-stuff.
- Do not promise ranking results.
- Admin should preview search/social snippets.

---

# 22. Email, SMS and Notifications

## 22.1 SMTP settings

Admin can configure:

- SMTP host.
- Port.
- TLS/SSL mode.
- Username.
- Password (encrypted).
- From email.
- From name.
- Test email.
- Email templates.

## 22.2 SMS provider settings

Generic provider adapter:

- Provider name.
- API URL.
- API key/token.
- Sender ID.
- Template IDs.
- OTP configuration.
- Test SMS.

Do not hardcode one SMS vendor throughout the application.

## 22.3 Notification events

- OTP.
- Order placed.
- Payment success/failure.
- Order accepted.
- Preparing/ready/out for delivery/delivered.
- Cancellation.
- Refund request/status.
- Subscription activation.
- Upcoming subscription delivery.
- Skip/pause confirmation.
- Support ticket update.
- Review approval/rejection.

Notifications should be queued/retried where practical. Avoid duplicate messages. Redact secrets and personal data from logs.

---

# 23. Admin Panel – Modules

## 23.1 Dashboard

- Sales summary.
- Orders by status.
- Today’s deliveries.
- Pending payments.
- Pending refund requests.
- Low stock.
- Subscription deliveries due.
- Open support tickets.
- Recent activity.

## 23.2 Admin modules

- Dashboard.
- Categories/subcategories.
- Products and variants.
- Product images.
- Inventory and stock movements.
- Orders.
- Customers.
- Addresses.
- Subscriptions and plans.
- Subscription deliveries.
- Delivery zones/fees/slots.
- Delivery assignments.
- Coupons.
- Offers.
- Homepage section builder.
- FAQs.
- About Us and CMS.
- Reviews.
- Testimonials.
- Support tickets.
- Payments.
- Refunds and cancellations.
- SEO settings and redirects.
- Notifications.
- Reports.
- Business settings.
- Payment gateway settings.
- SMTP/SMS settings.
- Admin users.
- Roles and permissions.
- Audit logs.

## 23.3 Roles and permissions

Suggested roles:

- Super Admin.
- Order Manager.
- Inventory Manager.
- Delivery Manager.
- Customer Support.
- Finance/Refund Manager (optional).

Enforce permissions server-side. Restrict payment secrets, refunds, exports and user/role changes to authorized roles. Log sensitive actions.

---

# 24. Reports and Analytics

- Sales report.
- Order report.
- Product performance.
- Category performance.
- Best sellers.
- Inventory/low-stock report.
- Payment reconciliation.
- Refund report.
- Coupon performance.
- Repeat customer report.
- Subscription collection and fulfilment.
- Delivery performance.
- Support ticket report.
- Review/rating summary.
- Export CSV where authorized.

Use date filters and timezone Asia/Kolkata. Avoid exposing personal customer data unnecessarily.

---

# 25. Suggested Database Modules and Tables

Final schema should be normalized sensibly and include indexes, foreign keys where supported, unique constraints, timestamps and status fields. Avoid putting the entire application into a few giant tables.

Suggested table groups (names can be refined during schema design):

## Core/admin
- `admin_users`
- `admin_roles`
- `admin_permissions`
- `admin_role_permissions`
- `admin_user_roles`
- `admin_audit_logs`
- `settings`

## Customers
- `customers`
- `customer_addresses`
- `customer_otp_attempts`
- `customer_sessions` (only if needed)

## Catalogue
- `categories`
- `products`
- `product_variants`
- `product_images`
- `product_attributes`
- `product_attribute_values`
- `product_related_items`

## Inventory
- `inventory`
- `inventory_movements`
- `stock_reservations`

## Cart/orders
- `carts`
- `cart_items`
- `orders`
- `order_items`
- `order_status_history`
- `order_delivery_snapshots`

## Payments/refunds
- `payments`
- `payment_webhook_events`
- `refund_requests`
- `refund_request_items`
- `refunds`
- `refund_status_history`
- `refund_attachments`
- `refund_admin_notes`
- `refund_policies`

## Delivery
- `delivery_zones`
- `delivery_slots`
- `delivery_slot_reservations`
- `delivery_assignments`
- `delivery_status_history`
- `delivery_holidays`

## Offers
- `offers`
- `offer_conditions`
- `offer_products`
- `coupons`
- `coupon_usages`
- `customer_coupon_assignments`

## Subscriptions
- `subscription_plans` — plan builder fields: name, description, duration_days, frequency_type, frequency_config (JSON), delivery_count, duration_model, basket_rules_json, price, payment_rules_json, skip_pause_policy_json, slot_policy_json, is_active, is_default_template, sort_order, published_at
- `subscription_plan_items`
- `subscription_plan_frequencies` (optional normalisation if not fully JSON on plans)
- `customer_subscriptions` — **`plan_snapshot_json`**, price_paid, plan_id, status, start_date, …
- `customer_subscription_items`
- `subscription_deliveries`
- `subscription_delivery_items`
- `subscription_payments`
- `subscription_skips`
- `subscription_pauses`
- `subscription_changes`
- `subscription_refunds`
- **Calendar & daily basket (Stage 11):**
  - `subscription_menu_templates` — admin menu planner (date or day-index templates)
  - `subscription_menu_template_items` — suggested vegetables, qty, price hints
  - `subscription_customer_preferences` — optional prefs for rotation/suggestions
  - `subscription_delivery_days` — per-subscription calendar day (date, status, mode: suggested/custom/skip, deadlines)
  - `subscription_delivery_baskets` — basket header per day (suggested / custom / confirmed)
  - `subscription_delivery_basket_items` — line snapshots (name, qty, unit price, variant/product refs)
  - `subscription_basket_substitutions` — unavailable item → proposed alternative → customer approval state
  - `subscription_selection_audit` — confirm/auto-confirm/customize/skip events

## Reviews/testimonials
- `product_reviews`
- `review_images`
- `review_replies`
- `review_moderation_history`
- `testimonials`
- `testimonial_media`
- `testimonial_consents`

## Support
- `support_tickets`
- `support_messages`
- `support_ticket_history`
- `faq_categories`
- `faqs`
- `customer_feedback`

## CMS/SEO
- `cms_pages`
- `cms_page_versions`
- `seo_redirects`
- `seo_settings`

## Notifications
- `notification_templates`
- `notification_queue`
- `notification_logs`

The final SQL schema must be generated as one complete `database/database.sql` file. Include indexes for slug lookups, status/date filters, foreign keys, uniqueness and webhook idempotency. Avoid storing derived values where they can safely be computed, but preserve immutable order/price/address snapshots.

---

# 26. Suggested Project Folder Structure

Use a simple, understandable PHP structure. Do not create an MVC framework.

```text
apni-tokri/
├── index.php
├── search.php
├── category.php
├── product.php
├── cart.php
├── checkout.php
├── order.php
├── page.php
├── faqs.php
├── contact.php
├── login.php
├── verify-otp.php
├── my-account.php
├── my-orders.php
├── order-details.php
├── my-subscriptions.php
├── subscription-details.php
│
├── admin/
│   ├── index.php
│   ├── dashboard.php
│   ├── categories/
│   ├── products/
│   ├── inventory/
│   ├── orders/
│   ├── customers/
│   ├── subscriptions/
│   ├── deliveries/
│   ├── coupons/
│   ├── offers/
│   ├── homepage/
│   ├── faqs/
│   ├── reviews/
│   ├── testimonials/
│   ├── support/
│   ├── payments/
│   ├── refunds/
│   ├── cms/
│   ├── seo/
│   ├── reports/
│   ├── settings/
│   ├── users/
│   └── roles/
│
├── includes/
│   ├── config.php
│   ├── database.php
│   ├── auth.php
│   ├── permissions.php
│   ├── csrf.php
│   ├── security.php
│   ├── validation.php
│   ├── helpers.php
│   ├── pricing.php
│   ├── inventory.php
│   ├── delivery.php
│   ├── subscription.php
│   ├── payment.php
│   ├── refund.php
│   ├── seo.php
│   ├── notification.php
│   ├── upload.php
│   └── layout/
│
├── ajax/
│   ├── cart/
│   ├── search/
│   ├── checkout/
│   ├── customer/
│   ├── reviews/
│   └── support/
│
├── payment/
│   ├── razorpay/
│   └── cashfree/
│
├── webhooks/
│   ├── razorpay.php
│   └── cashfree.php
│
├── cron/
│   ├── generate_subscription_deliveries.php
│   ├── process_delivery_reminders.php
│   ├── process_notification_queue.php
│   ├── cleanup_pending_payments.php
│   ├── expire_offers.php
│   └── reconcile_payments_refunds.php
│
├── assets/
│   ├── css/
│   ├── js/
│   ├── images/
│   └── icons/
│
├── uploads/
│   ├── products/
│   ├── categories/
│   ├── reviews/
│   ├── testimonials/
│   └── cms/
│
├── database/
│   ├── database.sql
│   └── upgrades/
│
├── storage/
│   ├── logs/
│   └── temporary/
│
├── .htaccess
└── README.md
```

Security note: in production, move `storage`, secrets, encryption key and preferably private uploads outside the public webroot. If the hosting layout does not permit this, deny direct web access and PHP execution using server configuration. Cron scripts should be CLI-only. Webhooks must be HTTPS and signature-verified.

---

# 27. Development Stages for Cursor Composer 2.5

Do not ask Cursor to build the entire system in one prompt. Complete and test one stage before moving to the next. Cursor must inspect existing files before changing them and must not silently rewrite completed modules.

## Stage 0 – Project rules and architecture

Deliverables:
- Confirm folder structure.
- Define coding conventions.
- Define configuration strategy.
- Define DB connection and error handling.
- Define security helpers.
- Define common response format for AJAX.
- Define status enums/constants.
- Create README with setup instructions.
- Confirm no framework/build tooling/dependencies.

Acceptance:
- PHP project opens and runs.
- Database connection test works.
- No Composer/npm/Node required.
- No secrets in public files or Git.

## Stage 1 – Brand and design system

Deliverables:
- Veggie Tokri working-brand UI.
- Logo placeholder that can be replaced.
- Color/font/spacing system.
- Header/footer.
- Buttons, forms, cards, badges, alerts and modal styles.
- Responsive public and admin shells.
- Hindi/English-ready labels.

Acceptance:
- 320px mobile to large desktop.
- No overflow or broken navigation.
- Accessible contrast and focus states.

## Stage 2 – Public homepage and catalogue UI

Deliverables:
- Homepage sections.
- Category listing.
- Search results.
- Product cards.
- Product details page UI with gallery, variants, description, attributes, reviews and related products.
- FAQs preview and About Us preview.
- Trust sections.

Acceptance:
- Responsive.
- Clear loading/empty/error states.
- No fake reviews or fake statistics.
- Product detail layout supports fresh and packaged food attributes.

## Stage 3 – Cart, checkout and customer account UI

Deliverables:
- Cart.
- Checkout.
- Address management.
- OTP screens.
- Order details and timeline.
- Subscription dashboard.
- Refund request UI.
- Review submission UI.
- Support ticket UI.

Acceptance:
- Clear price breakdown.
- Clear delivery information.
- Clear skip/pause/refund rules.
- Payment failure state preserves cart.

## Stage 4 – Admin UI

Deliverables:
- Admin layout/dashboard.
- Product/category forms.
- Product image manager.
- Variant and inventory UI.
- Order/subscription/delivery screens.
- Coupon/offer screens.
- Homepage section builder.
- FAQs/CMS/About Us editor.
- Review/testimonial moderation.
- Support/refund screens.
- SEO settings and redirect screens.
- Settings, users and roles.

Acceptance:
- Responsive admin.
- Permission-aware UI.
- Forms have validation and helpful errors.
- No secrets are displayed.

## Stage 5 – Database and PHP foundation

Deliverables:
- Complete normalized schema.
- `database/database.sql`.
- PDO connection.
- Config loader.
- Auth/session/OTP foundation.
- CSRF and validation helpers.
- Permission checks.
- Upload helper.
- Logging and audit helper.
- Common AJAX response handling.
- Installation/health-check page.

Acceptance:
- SQL imports into a fresh supported database.
- Core connection/auth tests pass.
- No SQL credentials committed to source control.
- Database constraints and indexes exist.

## Stage 6 – Catalogue and inventory backend

Deliverables:
- Category CRUD.
- Product CRUD.
- Product image uploads/order.
- Variant CRUD.
- Category-specific attributes.
- Stock management.
- Inventory movement history.
- Low-stock alerts.
- Product search/filter.
- Slug generation and uniqueness.
- Product visibility and availability.

Acceptance:
- Server-side validation.
- Safe uploads.
- Concurrent stock tests prevent overselling.
- Disabled/unpublished products are not publicly exposed.

## Stage 7 – Customer, cart and checkout backend

Deliverables:
- OTP login.
- Customer profile/address.
- Guest cart and persistent cart.
- Server-side pricing.
- Coupon validation integration.
- Delivery eligibility integration.
- Checkout/order creation.
- Duplicate submission prevention.

Acceptance:
- Browser cannot manipulate final price.
- Invalid stock/address/coupon is rejected.
- Cart survives payment failure.
- OTP rate limits work.

## Stage 8 – Delivery engine

Deliverables:
- Store location settings.
- Delivery zones/radius.
- Address pin support.
- Delivery fee rules.
- Slot management and capacity.
- Automatic ETA when slots are disabled.
- Delivery assignment and status tracking.
- Delivery history.

Acceptance:
- Outside-zone checkout blocked unless authorized override.
- Mixed-cart free-delivery rule is deterministic.
- Slot capacity is revalidated.
- Timezone is Asia/Kolkata.

## Stage 9 – Payment gateway integration

Deliverables:
- Razorpay.
- Cashfree.
- Test/live configuration.
- Encrypted credentials.
- Server-side order/session creation.
- Signature verification.
- Webhooks.
- Idempotency.
- Payment retry.
- Payment reconciliation.

Acceptance:
- Browser callback alone cannot mark paid.
- Duplicate webhook cannot duplicate payment/order effects.
- Wrong amount/currency/order reference rejected.
- Secrets never appear in logs or browser source.

## Stage 10 – Offers and coupons

Deliverables:
- Automatic offers.
- Coupon CRUD.
- Eligibility rules.
- Usage limits.
- Customer assignment.
- Discount calculation.
- Admin reports.

Acceptance:
- All eligibility validated server-side.
- Concurrent redemption does not exceed limits.
- No unintended stacking.

## Stage 11 – Subscription engine (Plan Builder, Smart Calendar & Daily Basket)

**Prerequisite:** Stages 1–10 complete. **Do not modify or regress** Stages 1–10 code or behaviour.

**Status (Oct 2026):** **CONDITIONALLY ACCEPTED — CORE IMPLEMENTATION COMPLETE, GATEWAY INTEGRATION PENDING.**

- Phases **A–E** and production-hardening migrations through schema **1.6.6** are implemented and covered by automated tests (`scripts/test-subscription-stage11*.php`).
- Stage 11 is **not** marked fully complete. Remaining work is **deferred** until all planned project stages (12–15) are implemented, then executed in a single **final Razorpay/staging integration and regression pass** (see §11.10).
- Subsequent stages (**Stage 12 onward**) are **not blocked** by this deferral.

**Next planned stage:** **Stage 12** — Reviews, testimonials, support and refunds (including real gateway refund API integration planned there).

---

### 11.10 Deferred gateway and E2E items (final integration pass)

Do **not** treat Stage 11 as closed until these are done in staging/production test mode:

1. Configure **Razorpay test credentials** on staging (`payment.active_gateway`, key/secret, webhook secret).
2. Connect **paid substitution customer UI** to actual **Razorpay Checkout** and `ajax/payment/verify.php` (today: simulate path on `subscription-details.php`; plan checkout uses Razorpay via `assets/js/apni-tokri.js`).
3. Add **subscription substitution payment** handling to **Razorpay webhook** processing (`PaymentService::handleRazorpayWebhook` — plan/order paths exist; `subscription_substitution_payments` pending).
4. Run **complete Razorpay test-mode E2E**, including **webhook-before-verification** and **verification-before-webhook** ordering for subscriptions and paid substitutions.
5. Implement **real Razorpay/Cashfree refund API** integration (**planned for Stage 12**); Stage 11 uses simulate reconcile only when gateway refunds are allowed by config.

**Documentation:** `PROJECT_PROGRESS.md`, `database/README.md` (upgrade path 1.6.0–1.6.6).

---

### 11.0 Phase A implementation checklist (final)

- [x] Migration `1.6.0_subscription_plan_builder.sql` applied; schema version **1.6.0**
- [x] Tables: `subscription_plans`, `customer_subscriptions`, `subscription_plan_audit`, `subscription_selection_audit` (structure for later basket lock)
- [x] Seed templates: **veg 7 / 15 / 30** (`is_default_template`, editable)
- [x] `SubscriptionScheduleGenerator` — daily / alternate / weekdays / custom interval; validates **calendar_duration_days** + **delivery_count**
- [x] `SubscriptionPlanValidator` + `SubscriptionPlanRepository` — server save/publish validation + dry-run preview
- [x] Admin **plan list** + **plan builder** (all §17.12.1 + basket/unused allowance fields in JSON)
- [x] `scripts/test-subscription-stage11-phase-a.php` — schedule validation, templates, inactive plan flag
- [x] `database/README.md` upgrade notes; config `expected_schema_version` **1.6.0**
- [x] **No changes** to Stages 1–10 except `bootstrap.php` load + `install.php` schema constant

---

### 11.1 Business scope

| Area | Requirement |
|------|-------------|
| **Plan builder** | Admin creates plans with **custom duration (days)**, not only 7/15/30; full §17.12 field set |
| **Default templates** | Seed **7 / 15 / 30-day** templates; editable, clonable, deactivatable |
| **Publishing** | Customers see and buy **Active** plans only |
| **Snapshots** | At purchase/activation, freeze **plan_snapshot_json** + price; later admin edits affect **new** subs only |
| **Calendar** | Auto-generate day-wise calendar from snapshotted frequency + delivery count + duration |
| **Daily basket** | §17.11 suggested/custom/skip, auto-confirm, substitutions |
| **Payment** | Stage 9 verified prepaid activation |
| **Delivery** | Stage 8 slots/capacity on generated subscription deliveries |

---

### 11.2 Migration & database (target schema **1.6.0**)

**Migration file:** `database/migrations/1.6.0_subscription_plan_builder.sql` (name TBD at implement time)

**Extend / create:**

- **`subscription_plans`** — all builder fields (§17.12.1); `is_active`, `is_default_template`; indexes on active + sort
- **`customer_subscriptions`** — `plan_snapshot_json`, `price_paid`, `subscription_plan_id`, dates, status
- **Calendar & basket tables** (§17.11) — unchanged intent from prior Stage 11 spec
- **Seed data:** three default templates (7/15/30) with sensible defaults (frequency, count, price placeholders)

**Constraints:**

- Unique delivery day per subscription `(customer_subscription_id, delivery_date)` or sequence
- `plan_snapshot_json` written once at activation (updates only via audited admin correction tools if ever needed)

**Documentation:** `database/README.md` upgrade section 1.5.0 → 1.6.0; bump `Install::SCHEMA_VERSION` only in Stage 11 implementation (not now).

**Explicit:** No ALTER that breaks orders, payments, coupons, delivery from 1.5.0.

---

### 11.3 Backend services

- **`SubscriptionPlanBuilderService`** — CRUD, clone, validate, publish/unpublish, calendar dry-run preview
- **`SubscriptionPlanService`** — storefront list active plans; build checkout session + payable amount from server
- **`SubscriptionActivationService`** — on payment success: snapshot plan, generate calendar days, initial suggested baskets
- **`SubscriptionCalendarService`** — remaining deliveries, next date, extend on skip/pause (from **snapshot** rules)
- **`SubscriptionBasketSuggestionService`**, **`SubscriptionBasketService`**, **`SubscriptionSkipPauseService`**, **`SubscriptionSubstitutionService`**, **`SubscriptionDeliveryGenerator`** — as in §17.11 / §17.7

**Validation (server-only):** duration, delivery count, frequency, price, hybrid model, calendar generation output count matches `delivery_count` (within duration rules).

---

### 11.4 Cron jobs

- `generate_subscription_deliveries.php`
- `subscription_auto_confirm_baskets.php`
- `subscription_refresh_suggestions.php` (optional)
- `subscription_check_substitutions.php`  
All **idempotent**; timezone Asia/Kolkata.

---

### 11.5 Admin UI

| Screen | Purpose |
|--------|---------|
| **Plan list** | Active/inactive; clone; link to builder |
| **Plan builder (create/edit)** | All §17.12.1 fields; validation errors inline; **calendar preview** for sample start date |
| **Default templates** | Flag/filter for 7/15/30 seeds |
| **Menu planner** | §17.11.5 — link plans to menu templates |
| **Customer subscriptions** | Snapshot summary; day-wise baskets; confirmed qty export |
| **Deliveries** | `admin/subscriptions/deliveries.php` queue |

Path convention: `admin/subscriptions/plans.php`, `admin/subscriptions/plan-edit.php` (or equivalent).

---

### 11.6 Customer UI

- Subscription catalog — **active plans only** (name, description, duration, delivery count, price, key rules)
- Purchase / checkout — subscription payment via Stage 9; terms from server
- **`my-subscriptions.php`**, **`subscription-details.php`** — calendar + baskets (mobile + desktop)
- AJAX: customize, skip, bulk days, substitution approval — CSRF + auth; **no client-trusted price/qty**

---

### 11.7 Test cases (`scripts/test-subscription-stage11.php` + manual QA)

| # | Test |
|---|------|
| 1 | Create custom 10-day plan → calendar has correct number of delivery days for daily frequency |
| 2 | Selected weekdays plan → only those weekdays appear |
| 3 | Custom interval N → spacing correct |
| 4 | Inactive plan **not** on storefront; active plan visible |
| 5 | Purchase → `plan_snapshot_json` populated; price matches server |
| 6 | Edit plan price/rules in admin → **existing** subscription unchanged (still old snapshot) |
| 7 | New purchase after edit → new snapshot |
| 8 | Invalid admin config (count vs duration) **rejected** on save |
| 9 | Default 7/15/30 templates exist after migration seed |
| 10 | Payment activation only via verified payment (Stage 9 pattern) |
| 11 | Auto-confirm basket at deadline |
| 12 | Skip carry-forward extends calendar per snapshot rules |
| 13 | Substitution requires approval (no silent swap) |
| 14 | Duplicate cron → no duplicate delivery days |
| 15 | **Regression:** `test-delivery-stage8`, `test-payment-stage9`, `test-promotions-stage10` still PASS |
| 16 | **Duration vs count:** schedule length ≠ delivery_count or date outside window → validation fails |
| 17 | **Allowance overage:** exceeds cap → requires approval flag; no silent charge path |
| 18 | **Cutoff lock:** change after cutoff rejected; confirmed basket not overwritten by cron |
| 19 | **Lifecycle:** failed payment → not active; duplicate webhook → single activation |
| 20 | **Pause/resume:** remaining delivery count preserved per snapshot policy |

---

### 11.8 Acceptance criteria

- Admin **custom plan builder** with all §17.12 fields; **7/15/30 templates** seeded and editable.
- **Server validation** of duration, count, frequency, price, calendar generation.
- **Active-only** storefront; **snapshot immutability** for existing subscriptions.
- **Automatic day-wise calendar** on activation from plan rules.
- §17.11 calendar/basket/auto-confirm/substitution behaviour.
- Verified **prepaid activation**; no duplicate delivery records; cutoffs server-side (Asia/Kolkata).
- Mobile + desktop calendar UX.

---

### 11.9 Implementation phases

| Phase | Deliverable | Core status |
|-------|-------------|-------------|
| **A** | Migration 1.6.0 + plan builder admin + validation + seed templates | **Done** |
| **B** | Storefront active plans + subscription checkout/payment + snapshot on activate + calendar generation (1.6.1) | **Done** |
| **C** | Menu planner + suggested baskets + customer calendar (1.6.2) | **Done** |
| **D** | Customize / skip / multi-day / auto-confirm cron + confirmed snapshots (1.6.3) | **Done** |
| **E** | Delivery generation + substitutions + reports + hardening (1.6.4–1.6.6) + `test-subscription-stage11.php` + regression | **Done** (gateway items §11.10 **pending**) |

**Out of scope for Stage 11:** Changes to Stages 1–10 modules except **integration hooks** (payment webhook, delivery slot API, inventory read).

**Gateway integration (§11.10):** deferred; does not block Stage 12+.

---

Deliverables (summary):
- **Admin Custom Subscription Plan Builder** (§17.12).
- Smart subscription calendar & flexible daily basket (§17.11).
- Prepaid activation, skip/pause/carry-forward, delivery cron, admin menu planner, customer-wise confirmed baskets.
- Migration **1.6.0**, admin UI, customer UI, cron, automated tests.

Acceptance (summary):
- Custom and template plans; **snapshot** on purchase; **active-only** catalog; **server-validated** calendars; §17.11 basket rules; Stage 7–10 regression intact.
- **Conditional acceptance:** core acceptance above is met in code and tests; **§11.10 gateway/E2E items** remain before **full** Stage 11 sign-off.

## Stage 12 – Reviews, testimonials, support and refunds

**Status (Oct 2026):** **In progress — Phase A (schema 1.7.0, core services, admin & customer flows).**

### 12.0 Phase A deliverables (implemented)

- Migration **`1.7.0_stage12_reviews_support_refunds.sql`**: review extensions & photos, testimonials, refund requests/items/evidence/history, `payment_refunds`, support ticket fields, payment `amount_refunded_inr`.
- **`GatewayRefundService`**: simulate + Razorpay + Cashfree refund API initiation; order refund workflow via **`RefundRequestService`** (approve ≠ gateway complete).
- **`ReviewService`**: verified purchase eligibility (delivered orders), moderation, admin reply, product page approved reviews.
- **`SupportTicketService`**, **`TestimonialService`** (consent required).
- Admin: reviews, refunds, support, testimonials (live data when DB ready).
- Customer: `order-details.php` refund request, `submit-review.php`, `support.php`.
### 12.1 Phase B deliverables (implemented)

- **`1.7.1_stage12_phase_b.sql`**: subscription payment refund totals, `payment_refunds` subscription links, support attachments, nullable order `payment_id` for sub-only refunds.
- **Refund webhooks** (`RefundWebhookService`, Razorpay/Cashfree handlers) — completion via webhook; live API response alone does not mark refunded.
- **Customer inbox** (`my-support.php`, `support-ticket.php`, private `ajax/support/attachment.php`).
- **Homepage testimonials** — approved, featured, consent on file only.
- **Subscription refunds** — `GatewayRefundService::refundSubscriptionPayment`, cron reconcile + webhook completion.
- **Admin reconciliation** — `admin/refunds/reconcile.php`, safe retry for failed order refunds.
- **`scripts/test-trust-stage12-phase-b.php`**.

**Status:** Phase A and Phase B complete pending your approval; **Phase C formally scoped (§12.2); implementation not started.**

### 12.2 Stage 12 Phase C – Refund Visibility, Review Moderation Reports and Cashfree Verification

**Status:** **Not started** (formal scope definition only; no Phase C implementation in this document revision).

**Purpose:** Close remaining Stage 12 gaps called out in project progress after Delivery Epic D1–D5: customer-visible refund status, auditable review moderation history for admins, and evidence-backed Cashfree refund webhook verification. This phase builds on Phase A/B services and schema; it does **not** reopen D1–D5 delivery work.

**Authoritative references:** §8.5 (review eligibility and customer-visible rejection reason), §16 (refund request flow, statuses, safety rules and gateway confirmation), §18.3 (admin moderation and “report/moderation history”), §25 (`review_moderation_history` in the suggested schema).

#### 12.2.1 Customer refund status page

**Requirements**

- Provide a **customer-facing** page (or equivalent account flow) where the logged-in customer can view **their own** refund requests only.
- Enforce **strict ownership** on every read: filter by authenticated `customer_id`; never expose another customer’s requests by ID guessing or shared links.
- For each refund request, show at minimum:
  - Internal **refund request status** (per §16.3).
  - **Gateway refund status** derived from linked `payment_refunds` rows (initiated, processing, completed, failed, etc.) without exposing raw gateway secrets, internal admin notes, or full webhook payloads.
- Present a **customer-safe timeline/history** using existing audit data:
  - `refund_request_history` (workflow actions visible to the customer where appropriate).
  - `payment_refunds` (gateway-oriented status and timestamps suitable for customer display).
- Link to this page from **relevant order details** (and subscription payment context where Phase B subscription refunds apply) so customers can navigate from the order they disputed.
- Reuse **`RefundRequestService`** / existing repositories where possible; do not duplicate refund business rules in the UI layer.
- **Privacy:** omit admin-only fields (internal notes, operator identity beyond generic labels if needed, gateway credentials, full API error dumps).

**Acceptance criteria**

- Authenticated customer A cannot view customer B’s refund requests (direct URL, query parameters, or API-style access).
- Customer sees accurate refund request status and gateway refund status for their requests, consistent with admin view minus restricted fields.
- History shown matches `refund_request_history` and `payment_refunds` for that request; no fabricated statuses.
- Order details (and applicable subscription refund entry points) include a clear link to the refund status view for requests tied to that order/payment.
- Automated or manual tests cover ownership enforcement and at least one happy-path display of request + gateway status.

#### 12.2.2 Review moderation reports (admin moderation history)

**Requirements**

- Introduce **persistent review moderation history** aligned with §18.3 (“report/moderation history”) and the §25 table name **`review_moderation_history`** (migration in Phase C if not already present).
- On each moderation-related action, append an immutable history row including at minimum:
  - **Actor** (admin user id and/or system actor type).
  - **Action** (e.g. approve, reject, feature, unfeature, reply posted, status change — preserve existing **`ReviewService`** moderation behaviour).
  - **Timestamp**.
  - **Reason** where mandatory today (e.g. rejection reason) or otherwise required by policy.
  - Reference to **`product_reviews`** (and related ids as needed).
- **Admin UI:** allow staff to **inspect moderation history** for a review (read-only audit trail), without removing or replacing the existing review queue, approve/reject, reply, or public display rules from Phase A.
- **Scope clarity:** Phase C covers **admin-visible moderation audit history** only. A separate **customer “report review”** workflow is **not** included unless separately approved (see §12.2.4).

**Acceptance criteria**

- Every approve/reject (and other in-scope moderation actions defined in implementation) creates a corresponding `review_moderation_history` record with actor, action, timestamp and reason when applicable.
- Admin can open a review and view chronological moderation history; existing moderation flows continue to work unchanged from a functional standpoint.
- History rows are append-only from an application perspective (no silent edits/deletes of past audit entries).
- Tests or documented manual checks confirm history is written on moderation and is visible in admin.

#### 12.2.3 Cashfree refund webhook verification

**Requirements**

- **Signature verification:** Cashfree refund webhook handling must reject requests with invalid or missing signatures using existing **`CashfreeGateway::verifyWebhookSignature`** (or equivalent) and configured webhook secret from environment/config — **no hardcoded credentials** in code or documentation.
- **Idempotency:** duplicate webhook deliveries (same event/id) must not double-apply refund completion; align with existing **`RefundWebhookService::handleCashfree`** and webhook event-id storage patterns used for Razorpay in Phase B.
- **Completion integrity:** mark order/subscription refund **completed** (or failed) only when processing **valid** gateway events through the established completion path (**`RefundCompletionService`** / existing webhook service); live API initiation response alone must not mark refunded (consistent with Phase B).
- **Verification evidence (staging first):**
  - Document **staging** steps to trigger or simulate Cashfree refund webhooks (sandbox/test credentials only).
  - Capture evidence: test script output (e.g. extend or run **`scripts/test-trust-stage12-phase-b.php`** where applicable), log excerpts, or checklist sign-off showing signature pass/fail, idempotent replay, and correct DB state on `payment_refunds` / refund request status.
  - **Production verification** may be recorded only after real production webhook traffic or an agreed production test with valid credentials — do not claim production verification without such evidence.

**Dependencies**

- Valid **Cashfree staging** (and optionally production) credentials, webhook URL reachability, and operator access to gateway dashboards/logs.
- Phase B **`webhooks/cashfree.php`** and **`RefundWebhookService`** baseline must remain the integration point unless a security fix requires a minimal change in a later implementation task.

**Acceptance criteria**

- Invalid signature → HTTP 4xx and no state change to refund completion.
- Replayed valid event → idempotent response; refund totals and statuses remain correct.
- Valid refund success/failure webhook → refund request and `payment_refunds` reflect gateway outcome per §16.4.
- Staging verification checklist and evidence attached to release notes or test output; production line item explicitly marked “pending” until evidence exists.

#### 12.2.4 Explicitly out of scope for Phase C (core)

Unless separately approved, Phase C **does not** include:

- Customer **Report Review** functionality (flagging other customers’ reviews).
- Admin **Feature Review** action extensions beyond what Phase A already provides, and **homepage review widgets** beyond Phase B testimonials.
- **Review sub-ratings** UI (freshness, quality, packaging, delivery — §18.1 optional fields).
- **Replacement / store-credit** customer workflows (§16.1 resolution options beyond refund visibility).
- **FAQ search** and **support analytics** (§19.4 — Stage 13/operations).
- **Stage 13** CMS/SEO, **Stage 14** reporting and bulk permissions.
- **Stage 11 deferred** Razorpay substitution **E2E** (§11.10 — unchanged deferral).
- Any **Delivery Epic D1–D5** changes or re-scoping.

Deliverables:
- Verified purchase reviews.
- Customer review photos.
- Review moderation and admin replies.
- Testimonial consent and moderation.
- Support tickets and order-linked complaints.
- Refund request workflow.
- Admin approval/rejection.
- Actual gateway refund initiation and reconciliation.
- Replacement/store-credit option only if configured.

Acceptance:
- Only eligible delivered purchases can submit verified reviews.
- Only approved reviews display publicly.
- No fake testimonials.
- Refund approval is distinct from gateway refund completion.
- Refund total cannot exceed captured amount.
- Full audit history exists.

## Stage 13 – CMS, FAQs, About Us, trust and SEO

**Implementation status (Nov 2026):** Phases **1–9** implemented and acceptance-tested in CLI (`scripts/test-*-stage13*.php`, `test-stage13-integration-phase9.php`). Schema **`1.9.0-stage13-cms-1`**. See [PROJECT_PROGRESS.md](./PROJECT_PROGRESS.md) for permissions, public routes, known limitations (homepage FAQ preview still uses demo catalogue data; no redirect/SEO admin UI), and manual production checklist. **Not** marked production-ready from automated tests alone.

Deliverables:
- CMS pages.
- About Us.
- FAQ categories and search.
- Homepage trust sections.
- SEO fields for products/categories/pages/homepage.
- Automatic editable slug generation.
- Slug uniqueness and 301 redirect history.
- Sitemap and robots.
- Canonical, Open Graph and structured data.
- 404 handling.

Acceptance:
- Admin can edit slug and SEO metadata.
- Slug change preserves old URL with 301.
- Sitemap contains only published canonical indexable pages.
- Structured data reflects actual visible product/review data.
- Private pages are noindex and inaccessible without authorization.

## Stage 14 – Notifications, reports, permissions and operations

Deliverables:
- SMTP settings.
- SMS provider adapter.
- Notification queue/templates.
- Admin roles/permissions.
- Sales/order/stock/payment/refund/subscription reports.
- CSV exports.
- Audit logs.
- Customer support analytics.

Acceptance:
- Duplicate notifications prevented.
- Sensitive data redacted.
- Role restrictions tested server-side.
- Exports permission-controlled.


### Stage 4A / Stage 14A – Marketing & Conversion Psychology
Implement the dedicated Marketing & Conversion Settings module described in Section 32. This work can be delivered incrementally alongside Stages 2, 3, 4, 6, 8, 10, 11, 14 and 15. Do not delay core checkout, inventory or payment correctness for cosmetic conversion experiments.

## Stage 15 – Security, performance, QA and launch

Deliverables:
- Security review.
- Performance optimization.
- Image optimization.
- Caching where safe.
- Backup/restore guide.
- Cron setup guide.
- Webhook setup guide.
- Deployment checklist.
- User acceptance test plan.
- Production README.

Acceptance:
- Test on mobile/tablet/desktop.
- Test all payment success/failure/pending/webhook paths.
- Test refund and subscription edge cases.
- Test duplicate clicks/webhooks/cron retries.
- Test SQL injection, XSS, CSRF, upload validation and authorization.
- Verify backup and restore.
- No debug mode or public secrets in production.

---

# 28. Testing Strategy – Mandatory

Cursor must not only create code; it must create and run meaningful test cases.

## 28.1 Functional tests

- Product with one variant.
- Product with multiple variants.
- Product with no stock.
- Product unpublished.
- Duplicate slug.
- Manual slug edit.
- Slug change and old URL redirect.
- Product image upload and invalid file.
- Cart price/stock changes.
- Coupon expired/used/invalid.
- Delivery inside/outside zone.
- Slot full.
- Payment success/failure/pending.
- Duplicate webhook.
- Partial refund and full refund.
- Subscription skip before and after cutoff.
- Subscription pause.
- Subscription calendar: suggested basket, multi-day customize, auto-confirm at deadline, skip day mode.
- Subscription substitution: unavailable item requires approval (no silent swap).
- Admin menu planner → customer suggested basket.
- Confirmed basket quantities report for inventory.
- **Plan builder:** custom duration plan → correct calendar; inactive plan hidden; snapshot immutability after plan edit.
- Cron retry and duplicate prevention.
- Review from delivered and undelivered order.
- Review approval/rejection.
- Testimonial without consent.
- Support ticket lifecycle.
- Role permission denial.

## 28.2 Security tests

- SQL injection.
- XSS in product/FAQ/review/CMS fields.
- CSRF.
- IDOR (changing order/customer IDs).
- Unauthorized admin endpoint access.
- File upload executable/script attempts.
- OTP brute force/rate limiting.
- Session fixation.
- Payment callback tampering.
- Webhook signature failure.
- Secret leakage in HTML/logs.
- Refund amount tampering.
- Coupon race conditions.
- Inventory overselling.
- Subscription duplicate generation.

## 28.3 Responsive tests

- 320px mobile.
- 360px and 390px mobile.
- Tablet portrait/landscape.
- Laptop.
- Large desktop.
- Product gallery swipe.
- Checkout usability.
- Admin tables/forms.
- Keyboard navigation and focus states.

---

# 29. Cursor Composer 2.5 – Working Rules

Use these rules at the start of every stage:

1. First inspect the current project files and database schema.
2. Do not assume a file or table exists; verify it.
3. Do not overwrite completed work without explaining why.
4. Do not create a framework or introduce Composer/npm/Node/build tools.
5. Use Core PHP, PDO, MySQL, Tailwind CDN and jQuery.
6. Keep business logic in reusable PHP include/helper files.
7. Use prepared statements and server-side validation.
8. Do not trust browser-calculated prices, stock, permissions or payment status.
9. Use transactions for multi-step financial/inventory/subscription operations.
10. Keep each stage focused; do not implement future stages prematurely.
11. Before finishing a stage, list files changed, DB changes, security considerations and manual test steps.
12. Run syntax checks and relevant tests where possible.
13. Update README and SQL file when schema/configuration changes.
14. Do not use dummy success responses for real payment, SMS or email integrations.
15. If credentials are missing, build a safe configuration interface and clearly mark integration as awaiting credentials; never fake a successful live integration.
16. Keep UI responsive and consistent with the brand design system.
17. Use realistic sample data only in development and clearly identify it as demo data.
18. Never fabricate customer reviews, testimonials, business claims, ratings or certifications.

## 29.1 Suggested prompt for each stage

```text
We are building Veggie Tokri, a local food e-commerce website for Indore.

Follow the project requirements document exactly.

Technology restrictions:
- Core PHP 8.2+
- MySQL with PDO
- HTML
- Tailwind CSS CDN
- jQuery/AJAX
- No Laravel or other framework
- No Composer, npm, Node, Vite, Webpack or build process

Before coding:
1. Inspect the current project structure and relevant files.
2. Identify what is already implemented.
3. Identify dependencies and database changes required for this stage.
4. Do not rewrite completed modules unnecessarily.

Implement ONLY the requested stage.
Use secure coding practices, responsive UI, reusable PHP helpers, prepared statements, CSRF protection, server-side validation and permission checks.

After implementation:
1. List created/modified files.
2. List SQL changes.
3. Explain setup/configuration changes.
4. Run PHP syntax checks and relevant tests.
5. Provide manual test steps.
6. Report incomplete integrations honestly.
7. Do not claim tests passed unless they were actually run.
```

---

# 30. Launch Checklist

## Business readiness

- Final brand/trademark review completed.
- Domain ownership and HTTPS configured.
- Business address/contact details verified.
- FSSAI registration/licence scope checked for actual products and operations.
- Product sourcing and handling process documented.
- Product photos and descriptions reviewed.
- Delivery zones, fees and capacity configured.
- Refund, cancellation and subscription policies reviewed.
- Customer support process staffed.
- Applicable tax, consumer and food-label requirements reviewed with appropriate professionals.

## Technical readiness

- Production PHP/MySQL versions supported.
- SQL import tested on a clean database.
- Secrets and master key outside public webroot.
- HTTPS enabled.
- Cron configured and monitored.
- Payment webhooks configured and tested.
- SMTP/SMS tested.
- Backups scheduled and restore tested.
- Upload folders protected.
- Error display disabled in production.
- Logs protected.
- Admin MFA or stronger admin login protection considered.
- Monitoring and uptime checks configured.

## Customer journey readiness

- Browse without login.
- Search and filter.
- View product details and multiple images.
- Select variant and add to cart.
- Check delivery availability.
- Checkout and OTP.
- Successful and failed payment.
- Order tracking.
- Cancel/refund request.
- Subscription purchase, skip and pause.
- Submit review.
- View approved review.
- Contact support.
- Use FAQs and policies on mobile.

---

# 31. Final Product Principles

1. Customer trust is more important than decorative marketing claims.
2. Make product details, weight, price, availability and delivery rules transparent.
3. Make the site easy for first-time and repeat customers.
4. Do not force signup before browsing.
5. Keep all critical calculations and permissions on the server.
6. Treat payment, refund, stock and subscription operations as financial/operational workflows requiring transactions and audit.
7. Use real customer feedback only with appropriate moderation and consent.
8. Keep SEO editable but technically safe.
9. Keep the first release simple enough to operate with a small team.
10. Build modules so future growth does not require a complete rewrite.

**End of requirements document.**


---

# 32. Customer Psychology, Marketing and Ethical Conversion Optimization

This module uses customer-behaviour principles to make product discovery and purchasing easier. The goal is to improve conversion, average order value and repeat purchases through honest information and a better shopping experience—not manipulation.

## 32.1 Core principles

- Help customers make informed decisions.
- Reduce confusion and unnecessary steps.
- Show real availability, real prices and genuine deadlines only.
- Do not fabricate scarcity, customer activity, reviews, ratings, discounts or popularity.
- Do not add products, subscriptions, gifts or paid extras to the cart without explicit customer action.
- Do not use misleading countdown timers, hidden charges, preselected paid add-ons or obstructive cancellation flows.
- Measure customer satisfaction, complaints and refunds alongside sales conversion.

## 32.2 Scarcity – genuine availability

Use only real stock and operational capacity:

- Low-stock badge based on actual sellable inventory.
- Daily preparation capacity for cut vegetables/cut fruits.
- Product availability for the selected delivery date/slot.
- Limited batch labels only when the batch is genuinely limited.

Admin controls:
- Enable/disable low-stock display.
- Configure per-product or per-variant display threshold.
- Configure whether exact stock quantity or a general “Low stock” label is shown.
- Configure daily preparation capacity.
- Never allow a manually entered fake stock count to appear as live inventory.

Examples:
- “Low stock” when inventory is below the configured threshold.
- “Available for 12 Nov delivery” when capacity and serviceability support it.

## 32.3 Urgency – genuine deadlines

Use real operational deadlines:

- Same-day delivery order cutoff.
- Delivery-slot booking cutoff.
- Real offer expiry date/time.
- Subscription enrolment closing date, if capacity-limited.

Requirements:
- Countdown must be calculated from a server-stored deadline.
- Use Asia/Kolkata timezone.
- Expired offers must stop applying server-side even if the browser timer is manipulated.
- Do not reset countdown on refresh or create fake “ends in 10 minutes” messages.
- Do not display urgency if there is no genuine deadline.

## 32.4 Social proof

Use real customer and order data:

- Approved verified-purchase reviews.
- Actual average rating and review count.
- Customer review photos with appropriate consent.
- Bestseller labels based on a defined real sales period and threshold.
- Testimonials only after customer consent and admin approval.
- “Popular in this category” only when supported by actual activity.

Requirements:
- Never seed production with fake reviews or ratings.
- Clearly label demo content in development.
- Do not create fake “someone just bought this” popups.
- Protect customer identity; show only the approved display name.
- Do not expose order details, mobile numbers or precise customer locations.

## 32.5 Loss aversion – transparent savings

Use helpful information rather than fear-based copy:

- Show actual savings against a genuine reference price.
- Show cart savings separately from delivery charges.
- Show how much more is required to reach a configured free-delivery threshold.
- Explain coupon expiry and eligibility clearly.
- Show what benefit a customer would miss, without implying a false loss.

Example:
“Add products worth ₹80 more to qualify for free delivery.”

Rules:
- Calculate progress from server-validated cart values.
- Recalculate after quantity, coupon, address or product changes.
- Never hide delivery fees or other charges until the final payment step.
- Do not make a free-delivery threshold appear available when the delivery zone is ineligible.

## 32.6 Anchoring and honest price presentation

- Show regular price and sale price only when the regular/reference price is genuine and supportable.
- Show unit price where useful, e.g. ₹ per 500 g or ₹ per kg.
- Show discount amount/percentage accurately.
- Do not inflate the regular price to manufacture a discount.
- Keep variant prices clear and update them when the customer changes variant.
- For packaged products, show pack size and relevant unit information.

## 32.7 Reciprocity – first-order benefits and useful extras

Admin may configure:

- First-order coupon.
- Free sample/gift with qualifying orders.
- Useful recipe or storage guide.
- Seasonal product guide.
- Loyalty benefit.

Rules:
- Clearly show eligibility and minimum order value.
- State whether the gift is limited by genuine stock.
- Do not automatically add a paid product.
- A free gift must show ₹0 payable and be removable where operationally appropriate.
- Coupon use and gift allocation must be validated server-side.

## 32.8 Decoy effect and subscription comparison

Subscription plans should be easy to compare, not deliberately confusing.

Show a comparison table with:
- Plan price.
- Number of deliveries or calendar duration.
- Items and quantities.
- Per-delivery cost where meaningful.
- Delivery fee treatment.
- Frequency options.
- Skip/carry-forward rules.
- Pause rules.
- Expiry/extension rules.
- Refund/cancellation terms.

Admin may highlight a recommended/popular plan only when the recommendation has a clear basis, such as actual purchase popularity or a transparent value calculation. Do not invent a decoy package or misrepresent value.

## 32.9 Charm pricing and price testing

- Admin can enter exact prices; do not automatically force every price to end in 9.
- Allow controlled price experiments such as ₹49 vs ₹50 only where margins and customer value support testing.
- Display the exact payable price, unit and applicable charges.
- Maintain price-change audit history.
- Do not use confusing crossed-out prices or hidden fees.

## 32.10 Convenience and choice simplification

Implement:
- Quick Add from product cards when there is no variant ambiguity.
- Clear variant selection when variants exist.
- Reorder from previous delivered orders.
- Saved shopping list or favourites (optional).
- Recently viewed products.
- Useful related products.
- Frequently bought together bundles.
- Clear category navigation.
- Search suggestions.
- Guest browsing.
- Persistent cart.
- Saved addresses.
- Simple checkout with minimal required fields.

Do not preselect a subscription or add optional paid extras without explicit customer consent.

## 32.11 Bundles and product combinations

Support admin-created bundles such as:
- Daily cooking combo.
- Aloo + Pyaz + Tamatar.
- Salad preparation combo.
- Pickle + papad combo.

Bundle configuration:
- Bundle name, description and image.
- Included products/variants and quantities.
- Bundle price.
- Compare-at price only if genuine.
- Start/end dates.
- Stock/capacity validation.
- Active/inactive status.
- Bundle-level delivery eligibility.
- Whether components can be substituted.

All component stock and pricing must be revalidated server-side. A bundle must not oversell component inventory.

## 32.12 Habit formation and repeat purchases

- Reorder button for eligible previous orders.
- Weekly basket suggestions.
- Subscription suggestions based on customer interest, without automatically enrolling the customer.
- Optional reminder notifications with customer consent.
- Saved favourites/list.
- Order history and quick repeat purchase.
- Preference-based recommendations where data and consent allow.

Do not send excessive reminders. Provide notification preferences and unsubscribe/opt-out controls as applicable.

## 32.13 Risk reduction and confidence

- Clear refund and replacement policy.
- Visible support contact.
- Order tracking.
- Genuine verified reviews.
- Accurate product information and photos.
- Clear delivery estimate and fees.
- Transparent subscription skip/pause terms.
- Secure payment information only where technically verified.
- Product-specific storage, ingredient and allergen information where applicable.

## 32.14 Admin – Marketing & Conversion Settings

Create a dedicated admin module with:

- Promotional banners and campaign scheduling.
- Product badges: Bestseller, New, Featured, Low Stock and Limited Availability.
- Low-stock thresholds.
- Real offer deadlines and countdown settings.
- Free-delivery threshold configuration by zone/rule.
- First-order offers.
- Free gift rules.
- Product bundles/combos.
- Subscription plan comparison and recommended-plan label.
- Cart recommendations.
- Related products.
- Recently viewed products.
- Reorder and repeat-order settings.
- Notification/reminder preferences.
- A/B experiment configuration for selected banners, product-card copy, CTA text or layouts.
- Campaign start/end dates and performance reports.

All settings must have server-side validation and audit history. A/B testing must not change the actual product price, delivery promise or policy without explicit configuration and correct customer disclosure.

## 32.15 Conversion analytics

Track useful events with privacy-conscious retention:

- Product impressions/views.
- Search usage and no-result searches.
- Add-to-cart.
- Remove-from-cart.
- Cart abandonment (aggregate analysis).
- Checkout started.
- Checkout completed.
- Payment failure/retry.
- Coupon application.
- Bundle selection.
- Subscription plan views and purchases.
- Repeat order.
- Review submission.
- Refund/complaint rates.

Dashboard metrics:
- Product view-to-cart rate.
- Cart-to-checkout rate.
- Checkout completion rate.
- Average order value.
- Revenue per visitor/session where measurement is appropriate.
- Repeat purchase rate.
- Subscription activation and fulfilment.
- Refund and complaint rate.
- Campaign performance.

Do not optimize conversion at the cost of increased complaints, refunds or customer confusion. Use A/B tests with clear success metrics and guardrail metrics.

## 32.16 Development stage integration

Add the following work to the existing stages:

- **Stage 2 – Public UI:** trust signals, real stock badges, delivery cutoff messaging, free-delivery progress, bundles and recommendations.
- **Stage 3 – Cart/checkout UI:** transparent savings, threshold progress, clear fees and optional gifts.
- **Stage 4 – Admin UI:** Marketing & Conversion Settings module.
- **Stage 6 – Catalogue/inventory:** actual low-stock and preparation-capacity signals.
- **Stage 8 – Delivery:** real cutoff and slot availability.
- **Stage 10 – Offers:** first-order benefits, gifts, offer deadlines and bundles.
- **Stage 11 – Subscriptions:** Admin plan builder (custom duration), 7/15/30 templates, snapshotted rules, smart calendar, daily baskets.
- **Stage 13 – CMS/SEO:** campaign landing pages and promotional content where needed.
- **Stage 14 – Reports:** conversion analytics and campaign performance.
- **Stage 15 – QA:** test that fake urgency, hidden fees, unapproved social proof and unauthorized cart additions are impossible.

## 32.17 Acceptance criteria

- Every scarcity message is backed by actual stock or capacity.
- Every countdown is backed by a real server-side deadline.
- Ratings and reviews come only from genuine approved records.
- Discounts and reference prices are accurate.
- Free-delivery progress uses the current eligible cart and delivery zone.
- No optional paid product or subscription is added without customer action.
- Bundle stock cannot oversell component products.
- Recommendations do not expose private customer data.
- Customer can understand the final payable amount before payment.
- Conversion reports include complaint/refund guardrails.
