Phase 4.1 delivered the front half of the events platform: user registration, event submission by hosts, committee moderation, host invoicing, and payment for the right to host an event. That work is complete and documented separately.

Phase 4.2 covers the second half — everything the public-facing programme and the attendee-facing booking system needs to do. In short:

    approved, confirmed hosts logging in to manage their own live events and their attendee lists;
    attendees browsing the programme and booking onto the flagship conference and other events;
    a basket so an attendee can book several events, and several colleagues, in one payment;
    Stripe handling the money, with correct VAT and a clean record the committee and attendees can both find later.

This document describes the principal workflows and behaviours expected, not an exhaustive field-level specification. As with 4.1, exact interface details and edge cases will be settled during build, staying within the scope below. Anything that materially changes the effort will be flagged before it is built.

This spec assumes the platform decisions already taken on the project: WordPress with Gravity Forms, Gravity Flow, Gravity Perks (Gravity Wiz) and GravityKit (GravityView) already licensed and installed, Stripe as the payment gateway, and Stripe ↔ Xero integration already in place from the host-payment work.

1. Scope at a glance

In scope for 4.2

    Public programme, event pages and calendar views.
    Speaker directory, with primary and additional organisations, roles, and duplicate prevention.
    Enhanced agendas for content-heavy events (session times, titles, descriptions and speakers).
    Host management of approved, confirmed events — editing permitted details, managing bookings, and capacity notifications.
    Online booking and payment for the flagship conference (approval-gated), with payment details saved at application and charged automatically on approval.
    Online booking and payment for other (hosted) events, including free registration and open-booking events.
    A basket / cart so a delegate can pay for multiple events and multiple attendees in one transaction, with discount codes and a 3-attendee cap per transaction.
    Automatic waitlist activation, promotion and self-withdrawal.
    Stripe integration for attendee payments, VAT handling, receipts and a findable transaction record.
    The attendee account area — booking history, VAT receipts, calendar and print options, and self-service changes.

Out of scope for 4.2

    The host submission, moderation and host-fee invoicing flow — delivered in 4.1.
    Event badging / on-site check-in hardware (a third-party solution, as set out in the detailed scope of work).
    A dedicated mobile app (the site will be fully mobile-responsive).
    Advanced HubSpot CRM automation beyond the agreed sync, and any move of payment processing into HubSpot (raised as a future consideration).
    Substantive year-on-year archiving (a separate additional service; the data structure supports it).
    Transit integration between events, e.g. Citymapper (raised as a possible future enhancement).
    A "featured" filter or homepage ribbon for the flagship and headline events (optional, to be costed and agreed separately).
    HubSpot sync for bookings and attendees — deferred; to be revisited after year one.

2. Working assumptions

These assumptions shape the build and are drawn from the requirements list, the detailed scope of work, the host conditions, the June call on VAT and payments, and the LAW committee's feedback on 4.2.4. Most are now settled; the remaining points marked [Decision needed] are gathered in §12.

    The flagship conference is approval-gated. Delegates apply; the committee approves, keeps on waitlist, or declines; only approved applicants have their card charged. A flagship ticket cannot simply be bought off the shelf.
    Registration states are settled per event category: flagship = approval-gated; the majority of hosted events = free registration; GAR, LCIA and CIARB = external (redirect to the host's own system); up to four LAW-run events = paid, with payment on the LAW site; drinks receptions = pending pricing (see §2.3). Hosts do not select their event's registration state — LAW admin sets it.
    Flagship ticket price is £500 + VAT. Wednesday reception remains included at no extra charge, but the delegate now confirms attendance via a checkbox after the committee approves their application, rather than being auto-registered with no further action. Reception arrangements:
        Opening Drinks (Monday) — flagship delegates can buy a discounted place at £25. This is a self-service purchase, not applied automatically to their account (unlike 2025). General sale is £75, opening after a priority window reserved for Wednesday-reception attendees.
        Wednesday reception — included automatically and free of charge for flagship delegates on approval. Also open for others to purchase; ==price to be confirmed==.
        Friday reception — invitation-only and free of charge (no longer the general free sponsor-benefit model used in 2025).
        All other hosted events are free unless individually priced by LAW admin.

        Monday and Wednesday reception tickets follow the same booking structure as other hosted events, including colleague booking and the 3-attendee cap (§6.2, §6.3). Both receptions have a capacity limit and use the same waitlist behaviour as hosted events (§5.4).
    VAT on tickets: standard-rate VAT applies to all ticket purchases, regardless of buyer location.
    Host fees and sponsorship are handled in Phase 4.1 and are not part of this phase. This phase does not sell host fees through the attendee cart.
    Refunds: the published policy is no refunds, though LAW does process them in practice. The system does not need an automated refund flow, but it makes transactions easy to find for whoever handles a refund (see §8).
    Payment timing for the flagship: payment details are captured at application, the card is stored securely by Stripe, and the card is charged automatically on approval. There is no time-limited payment window and no separate step for the delegate to complete after approval.
    Capacity creates a hard registration stop. Hosts receive an email notification when their event is within 5 of capacity, so they can amend ticket release numbers within their approved capacity band. Once capacity is reached, further registrations move to the waitlist (see §5.4). Hosts continue to manage over-booking / no-show buffers themselves.
    Waitlist behaviour is settled — see §5.4.

3. The calendar / public programme

The London Arbitration Week programme is the public-facing centrepiece of the platform. Once events have been approved, confirmed and published through Phase 4.1, delegates and visitors must be able to browse, search and discover events throughout the week. The programme draws directly on the event data hosts submit through the 4.1 workflow, so nothing needs to be re-keyed.

3.1 Programme views

The programme supports four ways of browsing:

    Calendar view — a day-and-time grid, showing overlapping and parallel sessions at a glance. Suits planning around a specific day.
    List view — chronological, grouped by day, with key details visible without needing to click into an individual event (title, date, time, venue, format, host organisation, short description). Suits scanning the week end-to-end and reads well on mobile.
    Day-by-day programme view — focused on a single day.
    Search results view — arising from any search or filter combination.

The system is fully responsive across desktop, tablet and mobile devices. The list view opens by default, for accessibility and mobile; delegates can switch to calendar or day-by-day from there. The list view is our recommendation for accessibility and mobile; the calendar view better conveys the shape of the week on a desktop.

3.2 Search and filtering

Visitors can filter and search events using combinations of:

    Date
    Time
    Speaker
    Host organisation
    Event category
    Free-text keyword search

Filters are designed to help delegates navigate a large programme with many events across the same week.

A "featured" filter or homepage ribbon is not part of the core build. LAW is interested in principle; it will be costed separately and added to the platform as an optional extra if agreed.

3.3 Event pages

Each published event has its own page, populated from the host's submission and any subsequent host edits. The page shows:

    title;
    summary and full description;
    date and time;
    venue details, including a Google map thumbnail;
    host organisation(s), and any co-hosts named for the public page;
    speaker information (headshot, name, job title, primary organisation, role);
    sponsor logos, where applicable;
    agenda, where the event has one (see §3.6);
    capacity information, where appropriate;
    registration state and the appropriate booking control (see §3.4).

Because the source data comes from the existing event entries, any host edit that goes live (see §4.2) flows through to the public page automatically.

3.4 The booking control

Every event shows a booking control appropriate to its registration state:

    Book now — open bookings, taken straight into the basket (§6);
    Apply — approval-gated events, opening the application form (§5 for the flagship);
    Register — free events, single-step sign-up;
    Waitlist — the event has reached capacity and further registrations move to the waitlist (see §5.4);
    Opening soon — bookings not yet open;
    Invitation only — no public booking; details shown for information;
    External link — bookings handled off-site, opening the external URL.

Current policy is that invitation-only does not apply to any LAW event except the Friday reception (see §2.3).

The control uses the same visual pattern across all programme views and event pages, so the delegate always encounters a consistent affordance.

3.5 Speaker directory

The platform includes a centralised speaker directory. Each speaker profile carries:

    name;
    biography;
    headshot;
    job title;
    primary organisation;
    additional organisations (allowing the same speaker to appear affiliated differently on different events);
    role — a dropdown of Speaker, Moderator, Host, with a free-text option for anything else;
    associated events.

Speakers automatically link to their associated events and vice versa.

To prevent duplicate speaker records, the system uses email as the primary unique identifier. When a host adds a speaker, the system looks up existing speakers and offers "use this existing speaker" before creating a new profile. LAW admin has a merge tool for cases where duplicates slip through — for example, the same person entered under different email addresses, or without an email at all.

3.6 Enhanced agendas

For content-heavy events — the flagship conference, GAR, LCIA, and any others selected by LAW — the agenda supports:

    session timings;
    session titles;
    session descriptions;
    speakers associated with each session (linked to the speaker directory).

Simpler events use a basic single-line agenda or none at all. The enhanced agenda is opt-in per event and configured by LAW admin.

3.7 Featured content

LAW administrators are able to:

    feature selected events on the homepage;
    highlight flagship events;
    promote sponsor events;
    surface newly-added events;
    curate programme content.

3.8 Archive support

The programme data structure supports year-on-year archiving so that previous LAW programmes remain accessible and searchable in future. Historic programmes are not surfaced prominently, and the active archive retains full search functionality for 12–18 months.

Note: the substantive archiving work is a separate service and not part of this phase; the underlying data model is designed to accommodate it.

3.9 Implementation note

The programme is built on the event entries created during 4.1, presented through GravityView for the list, day-by-day and search views, with a lightweight custom template for the calendar/grid view. All views read from the same underlying data, so nothing is maintained twice. The speaker directory is implemented as a first-class content type with its own admin interface, dedup logic and merge tool. The enhanced agenda is a structured, nested content type per event, populated by the host and rendered on the event page. 4. Host event management

The framework for host event management — login, the /account/events/ area, the primary-host and co-owner model, and hosts editing their own confirmed events — was built in Phase 4.1 and is described here for context.

New in Phase 4.2: the attendee-list dashboard (with dietary and access requirements) and CSV export (§4.3); LAW admin removal of individual registrations (§4.3); capacity notifications and waitlist activation (§4.4); and review-before-publish routing for those host edits that warrant it (§4.2).

4.1 Access and roles

    Hosts log in through the existing site login and land in their account area at /account/events/.
    The primary host is the lead admin contact and the billing contact.
    An event can name any number of co-owners, captured through a nested form on the submission. Each co-owner has the same admin access as the primary host: they can manage the event and its bookings. An account is created automatically for each co-owner on approval, as established in 4.1.
    A host or co-owner only ever sees the event(s) they are attached to and those events' attendees, never anyone else's.

4.2 What a host can edit

For each of their confirmed events, hosts can update:

    event description;
    speakers and speaker designations (name, job title, organisation, headshot);
    venue and address;
    event agenda;
    ticket allocations (within the approved capacity band).

The following are locked because they affect the programme grid, clash detection or finances:

    approved title;
    approved date;
    time slots;
    fees;
    approved capacity band;
    registration state (set by LAW admin only).

Host edits can either publish immediately or route to LAW for a quick review before going live. The general principle is that minor content updates (descriptions, headshots) publish immediately, while more substantial changes route for review. The exact split can be adjusted from LAW admin without a code change.

4.3 Managing bookings and attendees

From the host dashboard, for each event the host (or any co-owner) can:

    see a live count of registrations against capacity;
    view the attendee list, including the details attendees supplied (job title, organisation, country) and dietary and access requirements;
    download the attendee list as a CSV;
    see each attendee's status (registered, approved, waitlisted, cancelled).
    reorder their event's waitlist at any time, to control who is promoted first when a place opens (see §5.4);

LAW administrators can remove or reject individual registrations from any event. Event hosts cannot — this preserves admissions decisions as a LAW responsibility and keeps hosts focused on attendee support.

4.4 Capacity notifications

Hosts receive an automated email when their event is within 5 registrations of capacity, so they can adjust the ticket release if their approved band permits. Once capacity is reached, the system hard-stops further bookings and moves subsequent registrations to a waitlist (see §5.4).

4.5 Implementation note

The host dashboard and attendee lists are built on GravityView (GravityKit), already licensed. Attendee bookings are Gravity Forms entries; GravityView presents them as filtered, per-host, front-end tables with CSV export, with edit permissions scoped by user role. Custom code handles the role-scoping rules, the capacity notification, and any review-before-publish logic for host edits. 5. Attendee booking — flagship conference

The flagship conference operates on an approval-gated registration model. The workflow, as agreed with LAW, is:

    Delegate submits an application including payment details.
    Delegate is automatically placed on the waitlist and receives an acknowledgement email.
    Application enters committee review.
    Committee approves, keeps on waitlist, or declines.
    On approval, payment is charged automatically to the saved card.
    Registration is confirmed on successful payment.

Approval remains critical for maintaining the quality and composition of the flagship audience and is not replaced by a simple online-purchase model.

5.1 Apply

A prospective delegate (logged in, or creating an account as part of the flow) applies to attend, providing:

    title, first name, surname;
    job title, organisation;
    work country;
    dietary requirements;
    access requirements;
    any other questions the organisers add;
    payment details (no charge is taken at this stage).

Returning delegates have these fields pre-filled from their account so they don't re-key them each year.

Each flagship application covers one delegate. Where a firm wants to send several colleagues, each colleague submits their own application so the committee reviews each individually and payment is taken (or not) per person. This keeps the approval decision, the payment, and the confirmation cleanly one-to-one, and avoids partial-approval edge cases within a single application.

By providing payment details, the delegate consents to their card being saved and charged automatically if their application is approved. This consent is recorded with the application, and the delegate can withdraw it (deleting their saved payment details) at any point before their card is charged — see §5.4 and §8.

5.2 Committee review

Applications collect in a committee view where the committee can:

    download the applicant list;
    filter by country, surname and organisation;
    bulk approve, keep on waitlist, or decline;

This reuses the committee-review patterns and Gravity Flow approval mechanics from 4.1, applied to a booking form rather than a host-submission form.

5.3 Approve, pay, confirm

On approval:

    the delegate's saved payment method is charged automatically via Stripe (see §7);
    a VAT receipt is issued;
    a confirmation email is sent with event title, date, time and venue, the delegate's own details, a calendar invitation, and the relevant event terms;
    on approval, the delegate is prompted to confirm their Wednesday reception place via a checkbox; the reception ticket is then registered against their account.

On decline:

    the applicant is notified by email;
    their saved payment details are removed.

If the card fails at the point of charge (expired, insufficient funds, blocked), an exception email invites the attendee to update payment details within a defined window. If they do not, the place is offered to the next applicant on the waitlist.

5.4 Waitlist

All flagship registrations enter the waitlist by default. In addition, any bookable hosted event that reaches capacity moves further registrations onto a waitlist.

Waitlisted delegates:

    receive an automated acknowledgement on being added;
    receive automated status updates (promoted, approved, declined);
    can withdraw themselves at any time before their card is charged, from the "My bookings" area (§8), in which case their saved payment details are removed;
    are promoted in the order set on the event's waitlist (see §4.3), with the same charge-on-approval flow described above.

By default, the waitlist is ordered by the time each delegate joined it (first come, first served). Hosts can re-order their own event's waitlist from the host dashboard at any time, for example to reflect priority contacts or sponsor relationships. Once a place opens, promotion and charging happen automatically based on the waitlist order at that moment, so the host doesn't need to action each promotion individually.

Failed charges at the moment of promotion trigger the exception path in §5.3, and the next delegate in the host-ordered waitlist is offered the place.

The system takes a snapshot of the waitlist order at the moment a place opens and promotion is triggered, so a host reordering the list while a promotion is in progress doesn't affect a charge that's already underway.

6. Attendee booking — other events, and the basket

This is the open programme: the bookable hosted events, plus the basket that lets someone book across several of them at once.

The flagship conference is not part of the basket: it follows the separate approval-gated flow in §5, with its own application form, its own saved-payment-then-charge-on-approval mechanism, and one application per delegate. This split keeps each journey clean — open-booking events are paid for immediately at checkout, and flagship applications go through their own review cycle — and avoids the edge cases that would arise from mixing pay-now and pay-if-approved items in a single transaction. A delegate who wants both simply completes the two flows separately from the programme (§3).

6.1 Browsing and selecting

From the programme (see §3), a logged-in delegate can browse events and add bookable ones to a basket. Each event's booking control follows the registration-state pattern set out in §3.4, so the button label and behaviour on the calendar match what happens next.

Within the booking form itself, the delegate can search the events database by keyword, with type-ahead suggestions as they type. Each event in the picker shows its title, host organisation(s), date, time, location and price (where applicable). If the delegate wants more detail before committing, the picker links through to the full event page (§3.3) in a new tab.

The system supports discount codes applied at the basket:

    percentage discounts;
    fixed-value discounts;
    complimentary / 100% codes;
    codes with usage limits and expiry dates.

LAW admin manages the discount code catalogue.

Flagship delegates see the Monday reception at its discounted £25 price automatically when browsing; the £75 general price applies to everyone else. Wednesday-reception attendees get first access to Monday-reception tickets. General sale for Monday reception opens on a date set by LAW admin (rather than a fixed number of days after the priority window begins). The specific date is to be confirmed and configured operationally, not a build decision.

6.2 Clash and duplicate rules

The selection experience must:

    prevent selecting events that clash in time (overlapping slots), with a clear message identifying the conflicting event;
    prevent duplicate registrations for the same event (email address as the unique identifier);
    allow selecting multiple events in one session.

6.3 Multiple attendees per booking

A delegate can book colleagues as well as themselves in one go — for example, three people from the same firm for one event and two for another. Each attendee needs their own details captured (name, job title, dietary, access), because LAW and hosts rely on per-attendee data.

Basket bookings for hosted events are capped at 3 attendees per transaction. This supports chambers clerks and marketing teams booking a small group, while limiting scope for abuse. (This cap applies to the hosted-events basket; the flagship, as noted, is one application per delegate.)

This is the natural job for Gravity Perks Nested Forms, already licensed: a parent booking form with a nested "attendee" form, so each booking line can carry one or more named attendees with their own fields. Combined with GP eCommerce Fields (subtotals, tax, totals) and Conditional Pricing where needed, this covers per-attendee, per-event pricing and the VAT line without bespoke cart maths.

Where a booked colleague doesn't already have an account, the system creates one automatically from the details captured at booking (name, email, organisation), following the same pattern as host co-owner accounts in Phase 4.1. If they already have an account (matched by email), the booking is linked to that existing account rather than creating a duplicate. Either way, the booking appears in that attendee's own "My bookings" area (§8) and calendar, not just the booking colleague's.

An account-creation email is sent immediately. If the colleague never sets a password, this doesn't affect their booking: they still receive their confirmation email and calendar invite directly, and the booking still appears against their account whenever they do log in. The account merely gates self-service access, not the booking itself.

6.4 Press registration

A Press registration type is supported for reporting, host awareness and badging. Press passes are issued administratively by LAW admin — they are not a publicly available free ticket option and do not appear as a self-service choice on the front end.

6.5 The cart: how multi-event booking works

Multiple events and multiple attendees are paid for in a single Stripe transaction using a single configurable booking form. The form lists all bookable events, populated dynamically from the event entries (via GravityKit's Dynamic Lookup or Populate Anything). The delegate selects the events they want, adds attendees via Nested Forms, and eCommerce Fields totals the basket. One submission, one Stripe payment.

This approach makes the most of the existing Gravity stack, keeps pricing, tax and totals handled by tools already licensed, and results in one entry to reconcile — faster to build and lower to maintain than a bespoke cross-page shopping cart.

The booking engine is the main piece of bespoke development in this phase: clash detection, duplicate prevention, capacity checks, applying discount codes, assembling the line items, and the hand-off to Stripe.

6.6 A note on WooCommerce

It is reasonable to ask whether WooCommerce — the standard WordPress e-commerce plugin — should provide the cart, since a shopping basket is exactly what it does. We considered it and recommend staying within the Gravity ecosystem for this project, for three reasons:

    The approval gate is central here. The flagship flow, and any hosted event that runs apply → committee approves/waitlists/declines → then pay, works against WooCommerce's buy-now checkout. Inserting a committee moderation step mid-purchase would mean rebuilding, in a second system, the approval workflow already delivered in 4.1.
    It would mean running two ecosystems. Submission, moderation, host invoicing, roles and the account area all live in Gravity Forms/Flow/View today. Adding WooCommerce introduces a second customer model, a second Stripe configuration, and a second place bookings live — more to integrate, reconcile and maintain.
    The need is narrower than Woo's scope. The requirement is to pay for several events and attendees in one transaction, not to run a full storefront with inventory, shipping and coupons.

WooCommerce would help most with the cart and least with the approval workflow, which is the harder and higher-risk part. If a true browse-and-accumulate basket later becomes essential, a sensible hybrid would be WooCommerce for paid, non-approval bookings only, with approval-gated events remaining in Gravity Flow — but that is worth adopting only if the shopping experience clearly justifies running both systems. 7. Payments and Stripe

This extends the Stripe work from 4.1 (host fees) to attendee bookings.

7.1 Checkout

    Payment uses Stripe Checkout (Stripe's hosted page), consistent with the approach taken for host fees. It supports cards, bank transfer, Apple Pay and the other methods enabled on the LAW Stripe account (all except Klarna), and keeps all payments in one Stripe dashboard.
    The delegate confirms their selected events and attendees, accepts registration terms and conditions, and pays.
    Billing address is collected at checkout.

7.2 VAT

    Standard-rate VAT on all ticket sales, regardless of buyer location.
    VAT is shown clearly at checkout and on the receipt, with LAW's VAT number.
    A fixed standard-rate UK VAT tax rate (a Stripe tax rate object) is applied to ticket line items by the website, following the same approach used for host invoicing in 4.1. (Corrected September 2026: this previously said Stripe Tax; the live 4.1 integration applies a fixed tax rate, not Stripe Tax.)

7.3 Payment-gated confirmation

    For paid events, a booking is not confirmed until payment is received. Approval-gated flows confirm only after both approval and payment.
    Free events skip payment and confirm on registration.
    On successful payment: confirmation email, VAT receipt and calendar invite, as described in §5.3.

7.4 Saved payment methods (flagship and any paid waitlisted events)

For the flagship, and for any paid event where a waitlist applies, Stripe stores the delegate's payment method via a SetupIntent at the point of application. On approval or promotion, the system creates a PaymentIntent against the saved method and charges it off-session.

    There is no time limit on how long a saved payment method can be held — the card remains on file as long as consent stands.
    Consent to save and charge is captured explicitly at the application step, and forms part of the terms and conditions the delegate accepts.
    Strong Customer Authentication (3DS) is handled by Stripe at the point the card is saved.
    If a charge fails off-session, the delegate is prompted to update details via the exception path in §5.3.
    PCI scope does not extend to LAW; Stripe holds card details throughout.

7.5 Records and references

    The Stripe reference is the customer-facing number; Xero references stay internal, logged automatically via the existing integration.
    Each booking stores its Stripe reference and a link to the Stripe invoice / receipt, surfaced in the attendee's account area and visible to LAW for customer service. This directly addresses a difficulty from last year: locating a transaction for a refund previously meant chasing the last four digits of a card and an approximate payment date. With references and receipt links recorded against each booking, transactions are easy to find.

7.6 Branded VAT receipt

Stripe's standard receipt is used, subject to it carrying the correct purchase details (event, host, flagship, etc.) as line items. Stripe product and description fields are configured before go-live so receipts show the correct information for the delegate's records. 8. Attendee account area

Logged-in delegates get a "My bookings" area that lets them:

    view the events they're registered for, with title, date, time, venue and their registration status (registered / approved / waitlisted);
    view and download their VAT receipts, each linked to the Stripe record;
    save events to their calendar (Outlook, iCal, Google Calendar);
    print their bookings list or an individual event page;
    print a location map for individual events;
    update their personal details (kept for reuse in future years);
    withdraw from a waitlist at any time before their card is charged, which also removes their saved payment details;
    cancel attendance where the event rules allow it — with changes potentially restricted for paid events;
    access payment links where an exception (failed charge) requires updating details;
    see multi-year booking history, so returning delegates have their past attendance and receipts on record.
    request an additional role, for example, an attendee who registered as Event attendee can apply to become an Event host for their own event, using the existing host submission flow (§4.1) pre-filled with their account details. On approval, host permissions are added to the same account rather than creating a new one, so login, calendar and booking history stay in one place.

The account area is largely a GravityView job over the booking entries, scoped to the logged-in user, with custom code where business rules bite (what's cancellable, what's locked once paid, waitlist withdrawal handling).

Noted for future: integration with Citymapper or an equivalent to show transit between events was raised as a possible enhancement and is out of scope for this phase.

Noted for future: extending self-service role upgrades to other combinations (e.g. speaker, sponsor contact) was considered and scoped out for 4.2; attendee-to-host is the only upgrade path covered in this phase. 9. Notifications

Reusing the Gravity Flow and Gravity Forms notification patterns from 4.1.

Flagship conference:

    application received / added to waitlist;
    approved — payment taken, place confirmed; Wednesday reception place confirmed via checkbox;
    declined;
    waitlist status change (promoted to approved);
    exception — card charge failed, please update details;
    self-withdrawal confirmation.

Other events (open booking, immediate payment):

    booking confirmed with receipt, calendar invite and terms;
    payment receipt.

Waitlisted attendees on hosted events:

    added to waitlist;
    promoted from waitlist (place confirmed with charge);
    self-withdrawal confirmation.

Host-facing:

    new registration notifications where wanted;
    capacity warning (within 5 of capacity);
    waitlist activation for their event.

Exact templates will be adapted from LAW's existing host email templates, which already establish the tone and structure. 10. HubSpot sync

HubSpot sync for bookings and attendees has been deferred for 4.2, to be revisited after the first year's data is in. The existing sponsor/host HubSpot sync from earlier phases is unaffected. 11. Configured vs custom development

A summary of which parts build on the existing Gravity stack and which require bespoke development, to help with planning.

Built on the existing Gravity stack (configuration plus light integration):

    Programme calendar (list, calendar/grid, day-by-day and search views) — GravityView plus a lightweight custom template for the grid view.
    Event page rendering — GravityView.
    Host dashboard and attendee lists with CSV export — GravityView.
    Attendee account area / booking history — GravityView.
    Multiple attendees per booking — Nested Forms.
    Subtotals, tax and totals — eCommerce Fields + Conditional Pricing.
    Populating event choices into the booking form — Dynamic Lookup / Populate Anything.
    Committee approval of flagship applications — Gravity Flow (as 4.1).
    Notifications — Gravity Flow / Gravity Forms.
    Stripe Checkout and VAT — Stripe + the integration pattern from 4.1.

Bespoke custom development (the core of this phase):

    The booking engine: clash detection, duplicate prevention, capacity rules, 3-attendee cap enforcement.
    Consolidating multiple events and attendees into one payment and writing the results back as individual bookings.
    Discount code system — code management admin, application logic, per-code usage limits and expiry.
    Speaker directory — first-class content type with primary + additional organisations, roles, dedup logic on save, LAW admin merge tool, and front-end directory rendering.
    Enhanced agenda system — structured, nested sessions with per-session speakers, opt-in per event.
    Automatic waitlist promotion — host-controlled waitlist ordering, with the order snapshotted at the moment a place opens (so a host reordering mid-promotion doesn't affect a charge already underway), off-session Stripe charge on promotion, exception handling for failed charges, and self-withdrawal.
    Saved payment method flow — SetupIntent at application, off-session PaymentIntent on approval, and retry-on-failure UX.
    Role-scoped permissions for host edits and attendee self-service, including what's locked once paid, and self-service role upgrades (e.g. attendee to host).
    Capacity warning notification to hosts (within 5 of capacity).
    Review-before-publish routing for host edits where required.
    Surfacing Stripe references and receipt links into both the attendee account and the committee view.
    Attendee account creation and linking for booked colleagues — matching by email to link an existing account or auto-create one, so a booking made on someone's behalf still appears in their own account area and calendar.
    Reception pricing logic — checkbox-confirmed complimentary Wednesday-reception place on flagship approval, flagship-only discounted pricing at Monday reception, and a priority sales window for Wednesday attendees ahead of general release.

In short, Gravity Forms, Gravity Perks, GravityView and Stripe do most of the heavy lifting. The bespoke development sits in the booking engine, the multi-item payment hand-off, the discount code system, the speaker directory, the enhanced agenda, the saved-payment / waitlist promotion flow, and the permission rules. 12. Decisions for LAW

Following the LAW committee review of 4.2.4, most decisions have been settled and are now reflected in the spec above. The following points remain open and sit with the LAW committee:

Reception pricing needs confirming precisely — Monday reception was previously agreed at £25 (flagship) / £75 (general); Emily's latest reply mentions "£50" against Monday. Wednesday reception price for non-flagship purchasers is also still unconfirmed (£50 or £75). Neither affects build effort, only the price fields the committee configures once agreed. (§2)
