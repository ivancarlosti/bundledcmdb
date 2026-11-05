import type { MetaColumn } from './api';

/**
 * Where a device field lives: the edit form's tabs and the detail page's cards.
 *
 * The form and the view used to keep two hand-written lists (a tab map here, a
 * flat "render everything" loop there), so a new column could be placed in one
 * and forgotten in the other. That is how the disk-space field ended up in the
 * catch-all *More* tab while its neighbours were under *Hardware*: the panel
 * payload spelled the key `hds_space_gb` and the tab map listed
 * `hds_spaces_gb`. Both screens now read this module and the keys come from
 * `/meta.columns[].api_key`, so the two lists cannot disagree any more.
 *
 * Keys are wire keys (`sn`, `hds_spaces_gb`, `agent_os_build`).
 */

type SectionDef = {
  id: string;
  keys: readonly string[];
  /**
   * Section whose tab/card renders these keys after its own, under a heading.
   * The agent block belongs to Hardware on both screens: the agent reports the
   * same facts the hardware group holds, and a sixth top-level tab would hide
   * it. Assignment belongs to Identity for the same reason: the two cards were
   * always read together.
   */
  parent?: string;
  /** Translation key of the sub-heading (only for nested blocks). */
  headingKey?: string;
};

/** Catch-all id for columns no section claims; also its `ui_form_tab_*` label. */
export const CUSTOM_SECTION_ID = 'custom';

/**
 * Section layout, in render order.
 *
 * `record` is a view-only card (ids, provenance, timestamps). It holds no
 * writable key, so it never becomes a form tab; if one is added there, the tab
 * needs a `ui_form_tab_record` label.
 */
const SECTIONS: readonly SectionDef[] = [
  {
    id: 'identity',
    keys: ['sn', 'uuid', 'hostname', 'os', 'os_version', 'manufacturer', 'last_seen'],
  },
  {
    id: 'hardware',
    keys: [
      'cpus',
      'hds',
      'hds_types',
      'hds_spaces_gb',
      'network_adapters',
      'mac_addresses',
      'eset_components',
      'primary_local_ip',
      'primary_remote_ip',
    ],
  },
  {
    // Inventory-agent block: one column per key the agent reports
    // (`asset_agent_columns()` on the server) plus the structured disk
    // inventory, which the agent owns as well. A report never fills nor clears
    // the manual `hds`/`hds_types`/`hds_spaces_gb` rows, so the two sets are
    // shown side by side: the text is what a human typed, *Storage* is what the
    // host reported.
    id: 'agent',
    parent: 'hardware',
    headingKey: 'ui_agent_inventory',
    keys: [
      'agent_hostname',
      'agent_os',
      'agent_os_build',
      'agent_bios',
      'agent_secure_boot',
      'agent_manufacturer',
      'agent_model',
      'agent_cpu',
      'agent_gpu',
      'agent_ram_gb',
      'agent_ip',
      'agent_logged_user',
      'agent_all_logged_users',
      'agent_execution_user',
      'agent_antivirus',
      // Structured JSON, so the view renders it as `StorageSection` instead of a
      // table cell (a cell would read `[object Object]`).
      'physical_disks',
    ],
  },
  {
    // Who has the device: the identity card answers "which machine is this",
    // this block answers "who holds it". It used to be a top-level tab, which
    // put a five-field group next to Identity for no reason; nesting keeps the
    // sub-heading (`ui_form_tab_assignment`) and one tab fewer to click.
    id: 'assignment',
    parent: 'identity',
    headingKey: 'ui_form_tab_assignment',
    keys: ['status', 'user_email', 'asset', 'byod', 'mobile', 'warranty', 'purchase_date', 'source'],
  },
  { id: 'admin', keys: ['cypher_id', 'cypher_key', 'notes'] },
  {
    id: 'record',
    keys: [
      'id',
      'files_count',
      'company',
      'source_ref',
      'created_at',
      'updated_at',
      'created_by',
      'updated_by',
      'deleted_at',
    ],
  },
];

const ROOT_SECTIONS = SECTIONS.filter((section) => section.parent === undefined);

/** One resolved block: the section's own keys plus its nested groups. */
export type SplitSection = {
  id: string;
  keys: string[];
  groups: { id: string; headingKey: string; keys: string[] }[];
};

/**
 * Distribute `keys` over the declared sections.
 *
 * A key belongs to the first section that declares it; anything left over lands
 * in the catch-all section, which is what keeps a column the server adds later
 * from disappearing from both screens. Empty sections are dropped, so a manager
 * without `cypher_id` gets no *Admin* tab.
 */
export function splitSections(keys: string[]): SplitSection[] {
  const owner = new Map<string, SectionDef>();
  for (const section of ROOT_SECTIONS) {
    for (const key of keysOf(section)) {
      if (!owner.has(key)) {
        owner.set(key, section);
      }
    }
  }

  // Declared order inside a section, not the order the role list produced:
  // `sn` before `hostname` reads better than a shuffled form.
  const rank = new Map<string, number>();
  let index = 0;
  for (const section of ROOT_SECTIONS) {
    for (const key of keysOf(section)) {
      rank.set(key, index++);
    }
  }
  const byDeclaredOrder = (a: string, b: string): number =>
    (rank.get(a) ?? Number.MAX_SAFE_INTEGER) - (rank.get(b) ?? Number.MAX_SAFE_INTEGER);

  const buckets = new Map<string, string[]>();
  const leftover: string[] = [];
  for (const key of keys) {
    const section = owner.get(key);
    if (section === undefined) {
      leftover.push(key);
    } else {
      buckets.set(section.id, [...(buckets.get(section.id) ?? []), key]);
    }
  }

  const sections: SplitSection[] = [];
  for (const section of ROOT_SECTIONS) {
    const own = (buckets.get(section.id) ?? []).sort(byDeclaredOrder);
    if (own.length === 0) {
      continue;
    }
    // A nested block owns its own heading, so its keys are not repeated in the
    // parent's flat list.
    const groups = childrenOf(section.id)
      .filter((child) => child.headingKey !== undefined)
      .map((child) => ({
        id: child.id,
        headingKey: child.headingKey ?? '',
        keys: own.filter((key) => child.keys.includes(key)),
      }))
      .filter((group) => group.keys.length > 0);
    const grouped = new Set(groups.flatMap((group) => group.keys));
    sections.push({ id: section.id, keys: own.filter((key) => !grouped.has(key)), groups });
  }

  if (leftover.length > 0) {
    sections.push({ id: CUSTOM_SECTION_ID, keys: leftover, groups: [] });
  }

  return sections;
}

export type FormTab = SplitSection;

/**
 * Tabs of the edit form, built from the writable keys of the current role.
 *
 * Unknown keys go to the *Custom* tab instead of vanishing, which is what the
 * old catch-all did — it is just no longer called *More*.
 */
export function formTabs(keys: string[]): FormTab[] {
  return splitSections(keys);
}

export type DetailSection = {
  id: string;
  columns: MetaColumn[];
  groups: { id: string; headingKey: string; columns: MetaColumn[] }[];
};

/**
 * Cards of the detail page, built from every column `/meta` publishes.
 *
 * The list is kept as `MetaColumn[]` because it is also the render order inside a
 * card, and the page needs the label keys anyway.
 */
export function detailSections(columns: MetaColumn[]): DetailSection[] {
  const byKey = new Map(columns.map((column) => [column.api_key, column]));
  const resolve = (keys: string[]): MetaColumn[] =>
    keys.flatMap((key) => {
      const column = byKey.get(key);
      return column === undefined ? [] : [column];
    });

  return splitSections(columns.map((column) => column.api_key)).map((section) => ({
    id: section.id,
    columns: resolve(section.keys),
    groups: section.groups.map((group) => ({
      id: group.id,
      headingKey: group.headingKey,
      columns: resolve(group.keys),
    })),
  }));
}

function childrenOf(id: string): SectionDef[] {
  return SECTIONS.filter((section) => section.parent === id);
}

/** A section's own keys followed by the keys of every nested block. */
function keysOf(section: SectionDef): string[] {
  return [...section.keys, ...childrenOf(section.id).flatMap((child) => child.keys)];
}
