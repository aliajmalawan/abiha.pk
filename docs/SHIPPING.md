# Shipping

Shipping is managed by hand in the admin today, and the code is ready for courier APIs later. No courier is connected, and the app never invents courier names, tracking numbers or tracking events.

## What the owner configures (Admin → Shipping)
- **Methods** (e.g. Standard, Express): name, description, default charge, **free-shipping threshold**, **estimated delivery time**, display order, on/off.
- **Free-shipping threshold**: compared with the order amount after discounts. Blank means never free. A destination rule can set its own threshold.
- **Province rules and city rules** (open a method → *City & province rules*): a different charge, a different delivery time, a different free threshold, or *not offered here* for a province or a city. The most specific rule wins: **city → province → the method's default**. City matching ignores upper/lower case.
- Rates and times are the owner's own. Sample rates that ship with a fresh install are flagged **SAMPLE** until the owner saves them.

At checkout the customer sees each method with its estimate and charge for the province/city they typed. The server recomputes everything when the order is placed: a charge or estimate sent by the browser is ignored. The estimate that was shown is saved on the order (`shipping_eta`) and repeated on the confirmation and tracking pages.

## Manual shipping (how orders go out now)
1. Order arrives, payment is verified (see PAYMENTS.md), staff move it to *Processing*.
2. Staff hand the parcel to a courier of their choice.
3. Admin → Orders → the order → **Shipping**: enter the courier name, the tracking number and, if the courier has one, the tracking link (http/https only), then set the status to *Shipped*.
4. The customer sees exactly what was typed on *Track order* and in their account. Nothing is shown when nothing was entered.

## Adding a courier API later
The integration layer is in `app/Services/Shipping/`:

| Piece | Purpose |
|---|---|
| `CourierProvider` | the contract: `code()`, `label()`, `isConfigured()`, `createShipment(Order)`, `track(string)` |
| `Shipment`, `TrackingResult` | what a courier returned (booking id, tracking number/link, events exactly as reported) |
| `CourierException` | a plain-English failure that is shown to staff |
| `CourierManager` | the registry read from `config/shipping.php` |

Steps once you have an account with a courier:
1. Write a class implementing `CourierProvider` from the courier's own API documentation. Take the address, COD amount and items from the `Order`, never from the browser. Return only what the courier returns.
2. Add its credentials to `.env` and read them in `config/shipping.php` (nowhere else).
3. Register the class in `config/shipping.php` → `couriers`.
4. A courier with missing credentials (`isConfigured()` false) is not offered anywhere. Once configured, a **Book shipment** control appears on confirmed/processing orders. It saves the courier name, booking reference and tracking number the courier returned, and logs the action.

## What is verified
Automated tests cover charges, thresholds, city/province precedence, estimates, manual tracking, link safety and the integration layer using a **test double** courier (never registered in the app). No real courier has been connected or tested.
