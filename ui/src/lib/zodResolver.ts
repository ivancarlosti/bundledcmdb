import type { FieldErrors, FieldValues, Resolver } from 'react-hook-form';
import type { ZodType } from 'zod';

/**
 * Minimal zod -> react-hook-form resolver.
 *
 * `@hookform/resolvers` is not installed and does not need to be: the whole job
 * is "run the schema, turn `issues[].path` into the nested `errors` shape the
 * form reads". Keeping it here means one less pinned dependency in the stack lock
 * for a 30-line adapter.
 */
export function zodResolver<T extends FieldValues>(schema: ZodType<unknown>): Resolver<T> {
  return async (values) => {
    const result = schema.safeParse(values);
    if (result.success) {
      return { values: values as T, errors: {} };
    }

    const errors: Record<string, unknown> = {};
    for (const issue of result.error.issues) {
      // `values.SN` / `custom.cost_center`: the path mirrors the form model, so
      // the message lands on the input that caused it.
      let node = errors;
      issue.path.forEach((segment, index) => {
        const key = String(segment);
        if (index === issue.path.length - 1) {
          if (node[key] === undefined) {
            node[key] = { type: issue.code, message: issue.message };
          }
          return;
        }
        if (typeof node[key] !== 'object' || node[key] === null) {
          node[key] = {};
        }
        node = node[key] as Record<string, unknown>;
      });
    }

    return { values: {}, errors: errors as FieldErrors<T> };
  };
}
