import type { ComputedRef, InjectionKey } from 'vue';
import { inject, provide } from 'vue';

export interface PlanContextValue {
    isStreaming: ComputedRef<boolean>;
}

export const PLAN_KEY: InjectionKey<PlanContextValue> = Symbol('PlanContext');

export function providePlan(value: PlanContextValue): void {
    provide(PLAN_KEY, value);
}

export function usePlan(): PlanContextValue {
    const context = inject(PLAN_KEY);

    if (!context) {
        throw new Error('Plan components must be used within a Plan component');
    }

    return context;
}
