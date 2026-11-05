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

    expect(tabs.map((tab) => tab.id)).toEqual(['identity', 'hardware', 'assignment', 'admin']);
    expect(tabs[0]?.keys).toEqual(['sn']);
    expect(tabs[1]?.keys).toEqual(['cpus']);
    expect(tabs[2]?.keys).toEqual(['status']);
    expect(tabs[3]?.keys).toEqual(['cypher_id', 'notes']);
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
});
