<script setup lang="ts">
    import { computed } from "vue";
    import { cn } from "@/lib/helpers";
    import Avatar from "../Avatar.vue";

    interface TimelineKeys {
        avatar?: string;
        title?: string;
        time?: string;
    }

    const props = withDefaults(
        defineProps<{
            items?: any[];
            keys?: TimelineKeys;
            compact?: boolean;
            reverse?: boolean;
            class?: string;
        }>(),
        {
            items: () => [],
            keys: () => ({ avatar: "avatar", title: "title", time: "time" }),
            compact: false,
            reverse: false,
        }
    );

    const processedItems = computed(() => {
        return props.reverse ? [...props.items].reverse() : props.items;
    });

    function getNestedValue(obj: any, path: string) {
        if (!path) return undefined;
        return path.split(".").reduce((acc, part) => acc?.[part], obj);
    }
</script>

<template>
    <div :class="cn('relative', props.class)">
        <slot name="header" />

        <div class="relative">
            <!-- Vertical connector line -->
            <div
                class="absolute left-5 top-0 bottom-0 w-px bg-border"
                :class="{ 'left-4': compact }"
            />

            <!-- Timeline items -->
            <ul role="list" :class="cn('pl-12', { 'space-y-8': compact, 'space-y-10': !compact })">
                <li
                    v-for="(item, idx) in processedItems"
                    :key="idx"
                    role="listitem"
                    class="relative"
                >
                    <!-- Avatar positioned absolutely on the left -->
                    <div
                        class="absolute left-0 z-10"
                        :class="{ '-translate-x-12': !compact, '-translate-x-10': compact, 'mt-0.5': !compact, 'mt-0': compact }"
                    >
                        <slot name="avatar" :item="item" :index="idx">
                            <Avatar
                                v-if="getNestedValue(item, keys.avatar || 'avatar')"
                                :name="
                                    typeof getNestedValue(item, keys.avatar || 'avatar') === 'string'
                                        ? getNestedValue(item, keys.avatar || 'avatar')
                                        : getNestedValue(item, keys.avatar || 'avatar')?.name || 'User'
                                "
                                :src="
                                    typeof getNestedValue(item, keys.avatar || 'avatar') === 'object'
                                        ? getNestedValue(item, keys.avatar || 'avatar')?.src
                                        : undefined
                                "
                                :size="compact ? 'sm' : 'md'"
                            />
                        </slot>
                    </div>

                    <!-- Content -->
                    <div>
                        <!-- Header row: title + afterTitle + time vs actions -->
                        <div class="flex items-start justify-between gap-4">
                            <!-- Left side: title + afterTitle + time in column -->
                            <div class="flex-1 min-w-0">
                                <div class="flex items-start gap-2">
                                    <span
                                        :class="
                                            cn('font-semibold text-foreground', {
                                                'text-sm': !compact,
                                                'text-xs': compact,
                                            })
                                        "
                                    >
                                        {{ getNestedValue(item, keys.title || "title") }}
                                    </span>
                                    <slot name="afterTitle" :item="item" :index="idx" />
                                </div>
                                
                                <!-- Time below title -->
                                <time
                                    v-if="getNestedValue(item, keys.time || 'time')"
                                    :class="
                                        cn('block text-muted-foreground text-left', {
                                            'text-xs mt-1': !compact,
                                            'text-[0.65rem] mt-0.5': compact,
                                        })
                                    "
                                >
                                    {{ getNestedValue(item, keys.time || "time") }}
                                </time>
                            </div>

                            <!-- Right side: actions slot -->
                            <div class="flex items-start gap-2 shrink-0">
                                <slot name="actions" :item="item" :index="idx" />
                            </div>
                        </div>

                        <!-- Content body (slot) -->
                        <div :class="cn({ 'mt-2': !compact, 'mt-1.5': compact })">
                            <slot :item="item" :index="idx" />
                        </div>
                    </div>
                </li>
            </ul>
        </div>

        <slot name="footer" />
    </div>
</template>
