# ETO Operating Areas → CET

**STATUS: PARKED — Abdi wants to discuss before we decide anything here.**

## What ETO does
- Define one or more operating areas as **address + radius (miles) + Active**.
  (ETO example: Sheffield Station, 12 mi.)
- **"What to do when a journey is not in the operating area?"** — a dropdown; ETO
  is set to *"Allow booking and add driver journey to total price"* (i.e. still
  take the job, but add the dead-mileage the driver travels to reach the area).
  Other modes typically: reject the booking, or allow at a fixed price.

## CET today
- No hard "operating radius" concept. Out-of-area / long jobs are handled by the
  **free-roam distance pricer** (`FreeRoamPricer` / `FareCalculator`) — it prices
  any journey by distance rather than refusing it.
- 🟡 So CET already *takes* out-of-area work; what it doesn't have is ETO's
  explicit radius + "add the driver's journey-to-area to the price" rule.

## Open questions for the discussion
- Executive chauffeur work travels far by nature — do we even want a radius, or
  keep taking everything and pricing by distance?
- If we do want it: reject out-of-area, price it, or add dead-mileage on top?
- Should it apply only to the public form, or to office bookings too?

**Recommendation: leave as-is for now; revisit after Abdi's decision. Not a
go-live blocker — CET already accepts and prices these jobs.**
