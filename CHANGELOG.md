# Release Notes for Discontent

## Unreleased

### Added
- **Fields** tool: Craft’s Settings → Fields list with a field-type dropdown (used types only), a view dropdown (**Show all** / **Unused** / **Has instances** / **Used for keywords**), search, sort, double-click-to-edit slideout, delete, and “New field”.
- **Sections** tool: Craft’s Settings → Sections list with **URLs** and **Versioning** columns, a section-type dropdown, a view dropdown (**Show all** / **Has URLs** / **No URLs** / **Has versioning**), search, sort, double-click-to-edit slideout, delete, and “New section”. Both dropdowns are omitted when every section falls on the same side of them.
- **Entry Types** tool: usage filter (Section Entries / Matrix entries / Nested CKEditor entries), a view dropdown (**Show all** / **Unused** / **Has instances** / **Has slugs** / **Has statuses**), search, sort, double-click slideout, delete, and “New entry type”.
- Canonical pages at `/discontent/*` with an in-page sidebar; native Settings → Sections / Fields / Entry Types redirect there when **Replace native pages** is on.
- Shareable filtered URLs — filter state is reflected in query params.
- Settings: **Plugin name**, **Replace native pages**, **Show in main navigation**, and **Default view**.
- **Fields** tool: a **Show instances** view listing the fields used under a different label or handle somewhere, with each instance nested under its field — showing the instance’s label, handle, and the entry type, volume or global set it lives in.
- **Entry Types** tool: the same **Show instances** view, for entry types renamed where they’re used — each instance nested under its entry type, with the section or field that renamed it.
- A “+ N instances” note beside the name of any field or entry type that has instances, in every view.
- **Entry Types**: **Show Slug** and **Show Status** columns (a tick when authors see the field, a faint dash when they don’t), plus **Has slugs** and **Has statuses** views.
- **Fields**: a **Used for keywords** column and a matching **Used for keywords** view, for the fields whose values feed element search.
- Double-clicking an instance opens the entry type or volume it belongs to in a slideout, where its field layout designer edits the instance’s own settings (label, handle, instructions, conditions).
- **Used by** names the layout, with its icon, for fields used in only one — “2 layouts” and up are still counted, as in Craft.

### Changed
- Toolbar toggles can be flipped by clicking their label (**Unused**, **Instances**), not just the switch.
- Read-only environments (`allowAdminChanges` off): the Fields/Entry Types edit slideouts hide their Save button and relabel Cancel as **Close**, keeping the native read-only notice. Esc or a shade click also dismiss the slideout.
