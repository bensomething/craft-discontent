# Release Notes for Discontent

## Unreleased

### Added
- **Fields** tool: Craft’s Settings → Fields list with a field-type dropdown (used types only), a view dropdown (**Show all** / **Unused** / **Has instances**, then **Used for keywords** / **Not used for keywords**), search, sort, double-click-to-edit slideout, delete, and “New field”.
- **Sections** tool: Craft’s Settings → Sections list with **URLs** and **Versioning** columns, a section-type dropdown, a view dropdown (**Show all**, then **Has URLs** / **No URLs** and **Has versioning** / **No versioning**), search, sort, double-click-to-edit slideout, delete, and “New section”. Both dropdowns are omitted when every section falls on the same side of them.
- **Entry Types** tool: usage filter (Section Entries / Matrix entries / Nested CKEditor entries), a view dropdown (**Show all** / **Unused** / **Has instances**, then **Has slug** / **No slug** and **Has status** / **No status**), search, sort, double-click slideout, delete, and “New entry type”.
- Canonical pages at `/discontent/*` with an in-page sidebar; native Settings → Sections / Fields / Entry Types redirect there when **Replace native pages** is on.
- Shareable filtered URLs — filter state is reflected in query params.
- Settings: **Plugin name**, **Replace native pages**, **Show in main navigation**, and **Default view**.
- **Fields** tool: a **Show instances** view listing the fields used under a different label or handle somewhere, with each instance nested under its field — showing the instance’s label, handle, and the entry type, volume or global set it lives in.
- **Entry Types** tool: the same **Show instances** view, for entry types renamed where they’re used — each instance nested under its entry type, with the section or field that renamed it.
- A “+ N instances” note beside the name of any field or entry type that has instances, in every view.
- **Entry Types**: **Slug** and **Status** columns (a tick when authors see the field, a faint dash when they don’t), plus matching **Has** / **No** views.
- **Fields**: a **Used for keywords** column and matching **Used for keywords** / **Not used for keywords** views, for the fields whose values feed element search.
- Double-clicking an instance opens the entry type or volume it belongs to in a slideout, where its field layout designer edits the instance’s own settings (label, handle, instructions, conditions).
- View dropdowns group each **Has** / **No** pair under its own heading.
- Long URI formats in the Sections **URLs** column are cut off with an ellipsis, in full on hover.
- The nav item is hidden from non-admins, since every page behind it requires an admin.
- **Used by** names the layout, with its icon, for fields used in only one — “2 layouts” and up are still counted, as in Craft.

### Changed
- Toolbar toggles can be flipped by clicking their label (**Unused**, **Instances**), not just the switch.
- Read-only environments (`allowAdminChanges` off): the Fields/Entry Types edit slideouts hide their Save button and relabel Cancel as **Close**, keeping the native read-only notice. Esc or a shade click also dismiss the slideout.
