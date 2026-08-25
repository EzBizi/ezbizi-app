# Services — design

**Date:** 2026-08-25  
**Status:** built  
**Product:** EzBizi customer app (`app.ezbizi.com`)

The company's catalogue of work it sells: a name, a square photo, a price, a description, and a label. Groups and labels are lists the company writes on Settings.

Not Inventory (that is stock). Not Jobs (a job *names* a service later; this slice does not). Not manufacturing.

---

## Settled

| # | Decision |
|---|---|
| 1 | A **service** belongs to the organization, a company, or a franchise. Same filing as an inventory item. `PlaceInView`. |
| 2 | Two chips: **Catalogue** · **Settings**. 25/75. No modal. |
| 3 | Fields: name, photo (1:1), price (cents), description, one label, one group. |
| 4 | Settings is where **groups** and **labels** are created and deleted. A service may name one of each. Delete refused while a service still names it. |
| 5 | Photo is one square image, via attachments (`services`). Not square → “The photo has to be square (1:1).” |
| 6 | Price is the selling price, visible with `services.view`. Cents. No FX. |
| 7 | Permissions: `view` `create` `edit` `delete`. |
| 8 | Events: `service.created` `service.updated` `service.deleted`. No automation action writes the catalogue. |
| 9 | Name is unique inside the owning place. Prefix `SVC-`. |

Visibility matches Inventory: franchise sees its rows, that company's General, and organization rows. Siblings do not share a catalogue.
