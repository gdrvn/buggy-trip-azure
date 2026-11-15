# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
A group of friends/colleagues (~30 people overall, ~15 riding on a given day) on a trip in Brazil lasting up to ~3 months. They use the app mostly on phones, typically the evening before a ride day, to sign up for next-day buggy/hilux transport and to keep track of who owes whom.

## Product Purpose
Coordinate daily transport for the group: who rides on which day, which vehicles to order, and how the vehicle cost is split. Success = no one has to run a spreadsheet or chat thread to know the fleet for tomorrow or their running balance.

## Positioning
Sign-up, vehicle planning and cost splitting live in one place: the fleet is proposed automatically from the rider count, and paid vehicles flow straight into a Splitwise-style balance shared by everyone who rode that day.

## Operating Context
- Ride days are chosen per date; seat changes and cancellations lock at 22:00 the day before.
- Vehicles: buggy (4 seats, default R$370) and hilux (7 seats, default R$400). Proposal: up to 4 riders → buggy, up to 7 → hilux, then hilux + buggy, and so on.
- Vehicle lifecycle: proposed → booked (fixed) → paid by a member.
- Each day's paid vehicle costs are split equally across that day's riders; guests' shares are charged to the member who added them. Members record repayments to settle up.
- Access: a shared site password, then personal accounts.

## Capabilities and Constraints
- PHP + SQLite on Azure App Service (Free F1); vanilla JS front end, no build step. Pushing `main` deploys.
- Currency: Brazilian reais (R$). UI language: English.

## Brand Commitments
Name "Buggy Trip". Existing desert/adventure identity (sand, ink, rust, teal; Anton, Work Sans, Space Mono) is the incumbent look.

## Evidence on Hand
No logos, photos or testimonials. Don't fabricate any.

## Product Principles
- Tomorrow's ride at a glance: who's in, which vehicles, what it costs me.
- Money must be exact and explainable: every balance traces back to a paid vehicle or a recorded repayment.
- Phone-first, one-handed, works at dusk on mobile data.
- Low ceremony: friends trust each other; no admin roles.
