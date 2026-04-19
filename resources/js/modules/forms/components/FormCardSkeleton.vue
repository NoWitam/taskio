<script setup lang="ts">
import Skeleton from '@/components/ui/Skeleton.vue'
</script>

<template>
    <div class="border border-border rounded-lg p-4 bg-background">
        <!-- Header -->
        <div class="flex items-start justify-between mb-3">
            <div class="flex items-start gap-3 flex-1">
                <!-- Icon -->
                <Skeleton width="40px" height="40px" rounded="lg" />
                
                <div class="flex-1 space-y-2">
                    <!-- Title -->
                    <Skeleton height="16px" width="60%" />
                    <!-- Badge -->
                    <Skeleton height="20px" width="80px" rounded="full" />
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="flex gap-1">
                <Skeleton width="32px" height="32px" rounded="md" />
                <Skeleton width="32px" height="32px" rounded="md" />
                <Skeleton width="32px" height="32px" rounded="md" />
                <Skeleton width="100px" height="32px" rounded="lg" />
            </div>
        </div>

        <!-- Description -->
        <div class="space-y-2 mb-3">
            <Skeleton height="14px" width="100%" />
            <Skeleton height="14px" width="85%" />
        </div>

        <!-- Footer -->
        <div class="flex items-center gap-4 pt-3 border-t border-border">
            <Skeleton height="14px" width="80px" />
            <Skeleton height="14px" width="100px" />
        </div>
    </div>
</template>
