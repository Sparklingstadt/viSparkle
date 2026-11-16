import type { PlanData } from '@/types';

/**
 * The plans a visitor can start now, the cheapest of them (for the "from ¥…" bar) and the one drawn as featured: the
 * top accepting plan reads every post the others do and more, so it is featured when there are two or more.
 */
export function summarizePlans(plans: PlanData[]) {
    const accepting = plans.filter((plan) => plan.is_accepting);
    const cheapest = accepting.reduce<PlanData | null>(
        (min, plan) => (min === null || plan.price < min.price ? plan : min),
        null,
    );
    const featuredId =
        accepting.length > 1
            ? accepting.reduce((max, plan) =>
                  plan.price > max.price ? plan : max,
              ).id
            : null;

    return { accepting, cheapest, featuredId };
}
