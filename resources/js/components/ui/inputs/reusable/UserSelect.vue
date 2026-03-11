<script setup lang="ts">
import { computed } from "vue";
import { useDebounceFn } from "@/composables/useDebounce";
import { useI18n } from "@/composables/useI18n";
import SelectInput from "../SelectInput.vue";
import TextInput from "../TextInput.vue";
import Icon from "../../Icon.vue";
import Avatar from "../../Avatar.vue";
import Badge from "../../Badge.vue";
import { useUsersStore } from "../../../../store/users";
import type { User } from "../../../../types";

const props = withDefaults(
  defineProps<{
    modelValue: any;
    multiple?: boolean;
    label?: string;
    placeholder?: string;
    disabled?: boolean;
    error?: string;
    hint?: string;
    class?: string;
    clearable?: boolean;
  }>(),
  {
    multiple: false,
    clearable: true,
    placeholder: "",
  }
);

const emit = defineEmits<{ (e: "update:modelValue", value: any): void }>();
const { t } = useI18n();

const defaultPlaceholder = computed(() => 
  props.placeholder || (props.multiple ? t('inputs.selectUsers') : t('inputs.selectUser'))
);

const selectedValue = computed({
  get: () => props.modelValue,
  set: (v) => emit("update:modelValue", v),
});

const usersStore = useUsersStore();

function resolveSelectedUser(value: any) {
  const id = value === undefined || value === null ? '' : String(value);
  if (!id) return null;

  const u: any = (usersStore as any).usersById?.[id];
  if (!u) return null;

  return {
    ...u,
    label: u.name,
    value: u.id,
    name: u.name,
    id: u.id,
    avatar: u.avatar ?? u.src ?? null,
  };
}

function resolveUser(item: any): User | null {
  const id = item?.id ?? item?.value;
  if (id === undefined || id === null || id === "") return null;
  return (usersStore as any).usersById?.[String(id)] ?? null;
}

function displayName(item: any): string {
  const u = resolveUser(item);
  return String(u?.name ?? item?.name ?? item?.label ?? item?.value ?? "");
}

function displayAvatar(item: any): string | null {
  const u = resolveUser(item) as any;
  return (u?.avatar ?? u?.src ?? item?.avatar ?? item?.src ?? null) as any;
}

const debouncedSetQuery = useDebounceFn((setter: (q: string) => void, q: string) => setter(q));

async function loadUsers(cursor?: string, q?: string) {
  const res = await usersStore.fetchUsers({
    cursor: cursor ?? null,
    q: q || undefined,
    per_page: 20,
  });

  return {
    data: (res.data || []).map((u: User) => ({
      label: u.name,
      value: u.id,
      ...u,
    })),
    next: res.meta?.next_cursor ?? undefined,
  };
}
</script>

<template>
  <SelectInput
    v-model="selectedValue"
    :label="label"
    :placeholder="defaultPlaceholder"
    :error="error"
    :hint="hint"
    :disabled="disabled"
    :class="class"
    :loader="loadUsers"
    :multiple="multiple"
    :clearable="clearable"
    :resolveSelected="resolveSelectedUser"
    option-label-key="name"
    option-value-key="id"
  >
    <template #left>
      <span class="text-muted-foreground">
        <Icon name="users" />
      </span>
    </template>

    <template #panel-top="{ query, setQuery }">
      <div class="p-2 border-b border-border">
        <TextInput
          :modelValue="query"
          @update:modelValue="(v) => debouncedSetQuery(setQuery, v as string)"
          :placeholder="t('inputs.searchUser')"
          type="search"
          class="h-11"
        >
          <template #left>
            <span class="text-muted-foreground">
              <Icon name="search" />
            </span>
          </template>
        </TextInput>
      </div>
    </template>

    <template #item="{ item }">
      <div class="flex items-center gap-3 w-full min-w-0">
        <Avatar :name="displayName(item)" :src="displayAvatar(item)" size="xs" />
        <div class="min-w-0">
          <div class="text-sm font-semibold truncate">{{ displayName(item) }}</div>
          <div v-if="resolveUser(item)?.email || item.email" class="text-xs text-muted-foreground truncate">
            {{ resolveUser(item)?.email || item.email }}
          </div>
        </div>
      </div>
    </template>

    <template v-if="multiple" #selected="{ item, remove, measuring }">
      <Badge class="bg-secondary/40 border-transparent font-medium gap-2 h-6 py-0 px-2">
        <Avatar :name="displayName(item)" :src="displayAvatar(item)" size="xs" />
        <span class="truncate max-w-35">{{ displayName(item) }}</span>
        <button
          v-if="!measuring"
          type="button"
          class="text-muted-foreground leading-none"
          @click.stop="remove"
        >
          ✕
        </button>
        <span v-else class="text-muted-foreground leading-none">✕</span>
      </Badge>
    </template>

    <template v-else #selected="{ item }">
      <div class="flex items-center gap-2 min-w-0">
        <Avatar :name="displayName(item)" :src="displayAvatar(item)" size="xs" class="shrink-0" />
        <span class="truncate text-sm font-semibold">{{ displayName(item) }}</span>
      </div>
    </template>
  </SelectInput>
</template>
