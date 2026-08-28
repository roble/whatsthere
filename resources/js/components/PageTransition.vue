<template>
    <Transition name="page" mode="out-in" appear>
        <!--
            Keyed on the component, not the URL: moving within one page type
            (chat session to chat session, or a new chat adopting its id) is not
            a page change and should not fade. Keying on $page.url also tore
            down and rebuilt the subtree on every address change, which remounted
            the sidebar and killed in-place animations.
        -->
        <div :key="$page.component" class="page-transition-wrapper">
            <slot />
        </div>
    </Transition>
</template>

<style scoped>
.page-transition-wrapper {
    width: 100%;
    height: 100%;
}

.page-enter-active,
.page-leave-active {
    transition: opacity 0.3s ease-in-out;
}

.page-enter-from {
    opacity: 0;
}

.page-leave-to {
    opacity: 0;
}

/* Respect reduced motion preferences */
@media (prefers-reduced-motion: reduce) {
    .page-enter-active,
    .page-leave-active {
        transition: none;
    }
}
</style>
