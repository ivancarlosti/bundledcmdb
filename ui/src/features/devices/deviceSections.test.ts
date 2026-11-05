import { describe, expect, it } from 'vitest';

import type { MetaColumn } from './api';
import { CUSTOM_SECTION_ID, detailSections, formTabs } from './deviceSections';

/**
 * The section layout is the contract between the edit form and the view.
 *
 * The bug this file guards against: the disk-space key was misspelt in the tab
 * map (`hds_spaces_gb` there, `hds_space_gb` in the payload), so the field fell
 * through to the catch-all tab while the rest of the hardware sat where it
 * belonged. Both screens now read one list, and every key of that list is
 * asserted here rather than in a screenshot.
 */

const column = (apiKey: string): MetaColumn => ({
  key: apiKey,
  api_key: apiKey,
  label_key: `col_${apiKey}`,
  label: apiKey,
  type: 'text',
  in_grid: false,
  sortable: false,
  editable: true,
  virtual: false,
});

describe('formTabs', () => {
  it('groups the wire keys into the declared tab order', () => {
    const tabs = formTabs(['notes', 'sn', 'cpus', 'status', 'cypher_id']);

    expect(tabs.map((tab) => tab.id)).toEqual(['identity', 'hardware', 'admin']);
    expect(tabs[0]?.keys).toEqual(['sn']);
    expect(tabs[1]?.keys).toEqual(['cpus']);
    expect(tabs[2]?.keys).toEqual(['cypher_id', 'notes']);
  });

  it('nests the assignment block inside Identity', () => {
    const tabs = formTabs(['sn', 'user_email', 'status']);

    // Assignment is no longer a tab of its own; it is a heading inside Identity,
    // so it costs no extra click and cannot end up empty next to Identity.
    expect(tabs.map((tab) => tab.id)).toEqual(['identity']);
    expect(tabs[0]?.keys).toEqual(['sn']);
    expect(tabs[0]?.groups).toEqual([
      { id: 'assignment', headingKey: 'ui_form_tab_assignment', keys: ['status', 'user_email'] },
    ]);
  });

  it('drops empty tabs and puts unknown keys in the custom tab', () => {
    const tabs = formTabs(['sn', 'something_new']);

    expect(tabs.map((tab) => tab.id)).toEqual(['identity', CUSTOM_SECTION_ID]);
    expect(tabs[1]?.keys).toEqual(['something_new']);
  });

  it('keeps a declared order inside a tab regardless of the role list', () => {
    const tabs = formTabs(['hostname', 'sn', 'manufacturer']);

    expect(tabs[0]?.keys).toEqual(['sn', 'hostname', 'manufacturer']);
  });

  it('keeps the disk-space field inside Hardware', () => {
    const tabs = formTabs(['hds', 'hds_types', 'hds_spaces_gb']);

    expect(tabs).toHaveLength(1);
    expect(tabs[0]?.id).toBe('hardware');
    expect(tabs[0]?.keys).toEqual(['hds', 'hds_types', 'hds_spaces_gb']);
  });

  it('renders the agent block inside Hardware under its own heading', () => {
    const tabs = formTabs(['cpus', 'agent_os', 'agent_secure_boot', 'agent_all_logged_users']);

    expect(tabs.map((tab) => tab.id)).toEqual(['hardware']);
    // The grouped keys are not repeated in the tab's flat list.
    expect(tabs[0]?.keys).toEqual(['cpus']);
    expect(tabs[0]?.groups).toEqual([
      {
        id: 'agent',
        headingKey: 'ui_agent_inventory',
        keys: ['agent_os', 'agent_secure_boot', 'agent_all_logged_users'],
      },
    ]);
  });
});

describe('detailSections', () => {
  it('gives every published column a card', () => {
    const sections = detailSections([column('sn'), column('cpus'), column('agent_os'), column('created_at')]);

    expect(sections.map((section) => section.id)).toEqual(['identity', 'hardware', 'record']);
    expect(sections[1]?.columns.map((entry) => entry.api_key)).toEqual(['cpus']);
    expect(sections[1]?.groups.map((group) => group.id)).toEqual(['agent']);
    expect(sections[2]?.columns.map((entry) => entry.api_key)).toEqual(['created_at']);
  });

  it('puts a column no section knows into the custom card', () => {
    const sections = detailSections([column('sn'), column('invented_later')]);

    expect(sections.at(-1)?.id).toBe(CUSTOM_SECTION_ID);
    expect(sections.at(-1)?.columns.map((entry) => entry.api_key)).toEqual(['invented_later']);
  });

  it('nests the assignment columns inside the identity card', () => {
    const sections = detailSections([column('sn'), column('user_email')]);

    expect(sections.map((section) => section.id)).toEqual(['identity']);
    expect(sections[0]?.columns.map((entry) => entry.api_key)).toEqual(['sn']);
    expect(sections[0]?.groups.map((group) => group.id)).toEqual(['assignment']);
    expect(sections[0]?.groups[0]?.headingKey).toBe('ui_form_tab_assignment');
    expect(sections[0]?.groups[0]?.columns.map((entry) => entry.api_key)).toEqual(['user_email']);
  });
});
