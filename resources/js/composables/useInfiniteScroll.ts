import { ref, onUnmounted, watch, type Ref } from 'vue';

export interface InfiniteScrollOptions {
    rootMargin?: string;
    threshold?: number;
    root?: Element | null;
}

export function useInfiniteScroll(
    callback: () => void | Promise<void>,
    options: InfiniteScrollOptions = {}
) {
    const triggerElement = ref<HTMLElement | null>(null);
    const observer = ref<IntersectionObserver | null>(null);

    const {
        rootMargin = '100px',
        threshold = 0.1,
        root = null,
    } = options;

    const setupObserver = (element: HTMLElement) => {
        // Wyczyść poprzedni observer jeśli istnieje
        if (observer.value) {
            observer.value.disconnect();
        }

        observer.value = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        callback();
                    }
                });
            },
            {
                root,
                rootMargin,
                threshold,
            }
        );

        observer.value.observe(element);
    };

    // Obserwuj zmiany triggerElement i setupuj observer gdy element jest dostępny
    watch(triggerElement, (newElement) => {
        if (newElement && newElement instanceof Element) {
            setupObserver(newElement);
        }
    });

    const cleanup = () => {
        if (observer.value) {
            observer.value.disconnect();
        }
    };

    onUnmounted(() => {
        cleanup();
    });

    return {
        triggerElement,
        observer,
    };
}
