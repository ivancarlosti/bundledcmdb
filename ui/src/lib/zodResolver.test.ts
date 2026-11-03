import { describe, expect, it } from 'vitest';
import { z } from 'zod';

import { zodResolver } from './zodResolver';

/**
 * The zod adapter, pinned.
 *
 * It exists so `@hookform/resolvers` does not have to be pinned as well; what it
 * has to get right is the nesting, because react-hook-form reads
 * `errors.values.sn`, not `errors['values.sn']`.
 */
const schema = z.object({
  values: z.object({ sn: z.string().min(1, 'required') }),
  custom: z.object({ cost_center: z.string().min(1, 'required') }),
  company: z.string(),
});

const resolver = zodResolver<{ values: { sn: string }; custom: { cost_center: string }; company: string }>(schema);

const run = async (model: Record<string, unknown>) =>
  resolver(model as never, undefined, { fields: {}, shouldUseNativeValidation: false });

describe('zodResolver', () => {
  it('passes a valid model through', async () => {
    const result = await run({ values: { sn: 'SN-1' }, custom: { cost_center: 'CC-1' }, company: 'Acme' });

    expect(result.errors).toEqual({});
    expect(result.values).toMatchObject({ values: { sn: 'SN-1' } });
  });

  it('nests the issues on the path react-hook-form reads', async () => {
    const result = await run({ values: { sn: '' }, custom: { cost_center: '' }, company: 'Acme' });

    expect(result.errors).toMatchObject({
      values: { sn: { message: 'required' } },
      custom: { cost_center: { message: 'required' } },
    });
  });
});
