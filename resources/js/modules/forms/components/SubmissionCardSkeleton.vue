<script setup lang="ts">
import Skeleton from '@/components/ui/Skeleton.vue'
</script>

<template>
    <div class="border border-border rounded-lg p-4 bg-background">
        <!-- Header with creator -->
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-center gap-3 flex-1">
                <!-- Creator Avatar -->
                <Skeleton width="40px" height="40px" rounded="full" />
                
                <div class="flex-1 space-y-2">
                    <!-- Creator Name -->
                    <Skeleton height="16px" width="40%" />
                    <!-- Source Badge -->
                    <Skeleton height="20px" width="80px" rounded="full" />
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-1">
                <Skeleton width="32px" height="32px" rounded="md" />
                <Skeleton width="32px" height="32px" rounded="md" />
            </div>
        </div>

        <!-- Footer with approved date -->
        <div class="pt-3 border-t border-border">
            <Skeleton height="16px" width="50%" />
        </div>
    </div>
</template>
