<template>
    <div class="h-full min-h-0 flex flex-col overflow-hidden bg-background">
        <div class="flex-1 min-h-0 overflow-y-auto overflow-x-hidden p-6 text-center">
        <div class="flex items-center justify-between mb-6">
            <div>
                <h2 class="text-xl font-semibold text-primary dark:text-white">Hello from Vue!</h2>
                <p class="text-sm text-gray-600 dark:text-gray-300">Tailwind + Vue are working 🎉</p>
            </div>
            <button
                @click="toggleTheme"
                class="flex items-center justify-center w-10 h-10 rounded-full bg-secondary dark:bg-card border border-border dark:border-border hover:bg-muted dark:hover:bg-muted transition-colors"
                :title="isDarkMode ? 'Switch to light mode' : 'Switch to dark mode'"
            >
                <svg v-if="!isDarkMode" class="w-5 h-5 text-foreground" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M10 2a1 1 0 011 1v1a1 1 0 11-2 0V3a1 1 0 011-1zm4 8a4 4 0 11-8 0 4 4 0 018 0zm-.464 4.95l-2.12-2.12a1 1 0 00-1.414 1.414l2.12 2.12a1 1 0 001.414-1.414zM2.05 6.464A1 1 0 103.464 5.05l-1.414-1.414A1 1 0 002.05 6.464zM17.95 6.464l1.414-1.414a1 1 0 00-1.414-1.414l-1.414 1.414a1 1 0 001.414 1.414zM5.05 17.95l-1.414 1.414a1 1 0 001.414 1.414l1.414-1.414a1 1 0 00-1.414-1.414zM17.657 16.657l1.414 1.414a1 1 0 01-1.414 1.414l-1.414-1.414a1 1 0 011.414-1.414zM10 18a1 1 0 011 1v1a1 1 0 11-2 0v-1a1 1 0 011-1zM3 10a1 1 0 110-2 1 1 0 010 2zm14 0a1 1 0 110-2 1 1 0 010 2zM3.464 3.464a1 1 0 01-1.414 0L.636 1.636a1 1 0 011.414-1.414L3.464 3.464z" clip-rule="evenodd" />
                </svg>
                <svg v-else class="w-5 h-5 text-foreground" fill="currentColor" viewBox="0 0 20 20">
                    <path d="M17.293 13.293A8 8 0 016.707 2.707a8.001 8.001 0 1010.586 10.586z" />
                </svg>
            </button>
        </div>
        
        <PageHeader
            title="Publishing"
            description="Zarządzaj kolejką postów, edytorem i historią publikacji."
        >
            <template #icon>
                ICON
            </template>

            <template #actions>
                <Button variant="secondary">Import</Button>
                <Button variant="primary">Nowy post</Button>
            </template>

            <template #extra>
                <div class="flex flex-wrap gap-2">
                    <Badge tone="primary">Connected: 3</Badge>
                    <Badge tone="neutral">Queue: 12</Badge>
                </div>
            </template>
        </PageHeader>

        <StatsGrid class="mt-2">
            <StatCard label="Queue" :value="12" delta="+3" deltaTone="success" />
            <StatCard label="Scheduled" :value="8" />
            <StatCard label="Published" :value="103" delta="+12%" deltaTone="success" />
            <StatCard label="Errors" :value="1" delta="!" deltaTone="danger" />
        </StatsGrid>

        <EntityCard class="mt-2" title="Post: New Year Campaign" subtitle="Scheduled · Jan 2, 10:00">
            <template #leading>
                <Avatar name="ACME" />
            </template>

            <template #badges>
                <Badge tone="warning" dot>Scheduled</Badge>
            </template>

            <template #actions>
                <DropdownMenu>
                    <template #activator="{ toggle }">
                        <Button size="sm" variant="ghost" @click.stop="toggle">⋯</Button>
                    </template>

                    <template #default="{ closeMenu }">
                        <button
                            v-for="item in [{ id:'edit', label:'Edytuj' },{ id:'delete', label:'Usuń', tone:'danger' }]"
                            type="button"
                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60"
                            @click="handleAction(item.id, closeMenu)"
                        >
                            <span class="truncate"> {{ item.label }} </span>
                        </button>
                    </template>
                </DropdownMenu>
            </template>

            <template #meta>
                <div class="flex flex-wrap gap-2 text-xs text-foreground/70">
                <span>Campaign: ACME Q1</span>
                <span>•</span>
                <span>Assets: 3</span>
                </div>
            </template>

            <template #footer>
                <div class="flex items-center justify-between text-xs text-foreground/70">
                <span>Owner: Marta</span>
                <span>Last edited: 2h ago</span>
                </div>
            </template>
        </EntityCard>

        <Card class="mt-2">
            <div class="flex gap-2">
                <Button @click="() => isSheetOpen = true">
                    Open Sheet
                </Button>

                <Button @click="() => confirmOpen = true">
                    Open Confirm Dialog
                </Button>
            </div>

            <ConfirmDialog
                v-model="confirmOpen"
                :loading="confirmLoading"
                title="Usunąć element?"
                description="Tej operacji nie da się cofnąć."
                tone="danger"
                confirmLabel="Usuń"
                @confirm="confirmDialogConfirm"
            />

            <Sheet 
                v-model="isSheetOpen" 
                title="Edytuj post" 
                description="Zmień treść i harmonogram"
                width="lg"
            >
                <div
                    v-for="i in 40"
                    class="mt-2"
                >
                    Line {{ i }}
                </div>
                
                <template #footer>
                    <div class="flex justify-end gap-2">
                        <Button variant="secondary" @click="isSheetOpen=false">Anuluj</Button>
                        <Button variant="primary">Zapisz</Button>
                    </div>
                </template>
            </Sheet>
        </Card>

        <Card class="mt-2">
            <div
                v-for="variant in buttonVariants"
                class="flex gap-2 m-2"
            >
                <template v-for="size in buttonSizes">
                    <Button
                        :size
                        :variant
                    >
                        Click!
                    </Button>

                    <Button
                        :size
                        :variant
                        disabled
                    >
                        Click!
                    </Button>

                    <Button
                        :size
                        :variant
                        loading
                    >
                        Click!
                    </Button>
                </template>
            </div>
        </Card>

        <Card class="mt-2">
            <FileDropzone />
        </Card>

        <Card class="mt-2">
            <div class="flex gap-2 items-center p-2">
                <Badge>Neutral</Badge>
                <Badge tone="primary">Primary</Badge>
                <Badge tone="success">Success</Badge>
                <Badge tone="warning">Warning</Badge>
                <Badge tone="danger">Danger</Badge>
                <Badge dot tone="danger">Alerts</Badge>
            </div>
        </Card>

        <Card class="mt-2">
              <Tabs v-model="tab" :tabs="tabs">
                <template #panel="{ activeId }">
                    <div class="rounded-xl border border-border bg-background p-4">
                        Aktywny tab: <span class="font-semibold">{{ activeId }}</span>
                    </div>
                </template>
            </Tabs>
        </Card>

        <Card class="mt-2">
              <DropdownMenu v-model:open="open" align="end">
                <template #activator="{ open, toggle }">
                    <Button variant="secondary" @click.stop="toggle()">
                        Akcje <span class="text-foreground/60">{{ open ? "▲" : "▼" }}</span>
                    </Button>
                </template>

                <template #default="{ closeMenu }">
                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60"
                        @click="handleAction('edit', closeMenu)"
                    >
                        <span class="truncate">Edytuj</span>
                    </button>

                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60"
                        @click="handleAction('duplicate', closeMenu)"
                    >
                        <span class="truncate">Duplikuj</span>
                    </button>

                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition cursor-not-allowed opacity-50"
                        disabled
                    >
                        <span class="truncate">Archiwizuj</span>
                    </button>

                    <button
                        type="button"
                        class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition cursor-pointer hover:bg-secondary/60 focus:bg-secondary/60 text-red-600"
                        @click="handleAction('delete', closeMenu)"
                    >
                        <span class="truncate">Usuń</span>
                    </button>
                </template>
            </DropdownMenu>
        </Card>

        <Card class="mt-2">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <SelectInput
                        v-model="color"
                        hint="Hint"
                        error="Error"
                        label="Select color (single)"
                        :options="[
                            { label: 'Red', value: 'red' },
                            { label: 'Green', value: 'green' },
                            { label: 'Blue', value: 'blue' },
                            { label: 'Yellow', value: 'yellow' }
                        ]"
                        placeholder="Wybierz kolor"
                    />
                    <div class="text-xs text-muted-foreground mt-2">Value: {{ color }}</div>
                </div>

                <div>
                    <SelectInput
                        v-model="tags"
                        label="Select tags (multiple)"
                        :options="[
                            { label: 'Bug', value: 'bug' },
                            { label: 'Feature', value: 'feature' },
                            { label: 'Chore', value: 'chore' },
                            { label: 'Hotfix', value: 'hotfix' }
                        ]"
                        multiple
                        placeholder="Wybierz tagi"
                    >
                        <template #selected="{ item, remove }">
                            <Badge class="px-2!">{{ item.label }} <button class="ml-1 text-muted-foreground" @click="remove">✕</button></Badge>
                        </template>
                    </SelectInput>
                    <div class="text-xs text-muted-foreground mt-2">Values: {{ tags }}</div>
                </div>
            </div>

            <div class="mt-4">
                <SelectInput
                    v-model="remote"
                    label="Remote select (loader)"
                    placeholder="Wyszukaj..."
                    :loader="mockLoader"
                >
                    <template #panel-top="{ query, setQuery }">
                        <input class="w-full rounded-md border p-2 text-sm" placeholder="Szukaj" :value="query" @input="(e) => setQuery((e.target as HTMLInputElement).value)" />
                    </template>

                    <template #item="{ item, select, isSelected }">
                        <button
                            class="flex w-full items-center justify-between gap-3 px-3 py-2 text-left text-sm transition"
                            @click="select"
                        >
                            <span>{{ item.label }}</span>
                            <span v-if="isSelected" class="text-primary">✔</span>
                        </button>
                    </template>
                </SelectInput>
                <div class="text-xs text-muted-foreground mt-2">Value: {{ remote }}</div>
            </div>

            <div class="mt-4">
                <TextInput
                    v-model="textValue"
                    label="Label"
                    placeholder="Search..."
                    hint="Hint"
                    error="Error message"
                >
                    <template #left>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </template>
                    <template #right>
                        <button class="text-xs text-muted-foreground px-2">Clear</button>
                    </template>
                </TextInput>
            </div>

            <div class="mt-4">
                <TextareaInput
                    v-model="textareaValue"
                    label="Label"
                    placeholder="Search..."
                    hint="Hint"
                    error="Error message"
                >
                    <template #left>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 10a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </template>
                    <template #right>
                        <button class="text-xs text-muted-foreground px-2">Clear</button>
                    </template>
                </TextareaInput>
            </div>

            <div class="mt-6">
                <Button @click="showDialog = true">Open Dialog</Button>

                <Dialog v-model="showDialog" title="Example Dialog" description="Przykładowy dialog">
                    <p class="text-sm text-foreground">Tutaj możesz umieścić dowolną treść — formularz, informacje lub ustawienia.</p>

                    <template #footer>
                        <Button variant="ghost" @click="showDialog = false">Anuluj</Button>
                        <Button @click="showDialog = false">Potwierdź</Button>
                    </template>
                </Dialog>
            </div>
        </Card>

        <Card class="mt-2 flex gap-2 flex-col">
            <SwitchInput
                v-model="sw"
                label="Label"
                hint="Hint"
                error="Error"
            >
                <template #left>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                    </svg>
                </template>

                <template #right>
                    <span class="text-xs text-muted-foreground">{{ sw ? 'On' : 'Off' }}</span>
                </template>
            </SwitchInput>
            <CheckboxInput v-model="agreedTerms" label="Zgody" text="Akceptuję regulamin" />
            <RadioGroupInput v-model="radioGroupMode" label="Tryb" :options="radioGroupOptions" />
        </Card>

        <Card class="mt-2 flex gap-2 flex-col">
            <DateInput
                v-model="date"
                label="DateInput"
                hint="DD.MM.RRRR"
                :min="'1982-06-05'"
                :max="'2030-12-31'"
                :disabledDates="['2026-01-14','2026-01-15']"
                class="w-1/4"
            />

            <TimeInput
                v-model="time"
                label="TimeInput"
                hint="HH:MM"
                class="w-1/4"
            >
            </TimeInput>

            <ColorInput
                v-model="selectedColor"
                :swatches="['#FF3B30','#FF9500','#4CD964','#057AFF']"
                label="ColorInput"
                hint="Wybierz kolor"
                class="w-1/4"
            >
                <template #left>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2v20M2 12h20" />
                    </svg>
                </template>

                <template #right>
                    <div class="text-xs text-muted-foreground">{{ selectedColor }}</div>
                </template>

                <template #center="{ color }">
                    <div class="flex items-center gap-2 pointer-events-none">
                        <div class="w-6 h-6 rounded-full border border-border" />
                        <div class="text-xs text-muted-foreground">{{ color }}</div>
                    </div>
                </template>
            </ColorInput>

            <PillGroupInput v-model="daysSelected" :items="[{key:'sun',label:'Sun'},{key:'mon',label:'Mon'},{key:'tue',label:'Tue'},{key:'wed',label:'Wed'},{key:'thu',label:'Thu'},{key:'fri',label:'Fri'},{key:'sat',label:'Sat'}]" :disabledItems="['sun','sat']" label="Days of Week" hint="Kliknij aby zaznaczyć/odznaczyć" class="w-1/2" />
            <div class="text-xs text-muted-foreground mt-2">Wybrane: {{ daysSelected.join(', ') }}</div>

            <ColorInput
                v-model="selectedColor2"
                label="ColorInput"
                hint="Wybierz kolor"
                class="w-1/4"
            >
                <template #left>
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 text-muted-foreground" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2v20M2 12h20" />
                    </svg>
                </template>
            </ColorInput>

            <DateTimeInput
                v-model="dateTime"
                label="DateTimeInput"
                hint="YYYY-MM-DD HH:MM"
            />

            <NumberInput
                v-model="count"
                label="NumberInput"
                :min="0"
                :step="1"
                hint="Wpisz liczbę"
            />

            <SliderInput
                v-model="intensity"
                label="SliderInput"
                :min="0"
                :max="100"
                :step="1"
                hint="0–100"
            />

            <SliderInput
                v-model="range"
                :range="true"
                label="Intensity"
                :min="0"
                :max="100"
                :step="1"
                hint="Ustaw zakres od–do"
            />

            <RichTextInput
                v-model="richText"
                label="RichTextInput"
                placeholder="Napisz treść…"
                hint="Zwraca HTML w v-model"
            />

            <h3 class="text-base font-semibold text-foreground mb-4">Icon Picker</h3>
            <div class="flex flex-col gap-3">
                <IconInput
                    v-model="selectedIcon"
                    label="Wybierz ikonę"
                    placeholder="Kliknij, aby wybrać…"
                    class="max-w-xl"
                />

                <IconInput
                    v-model="selectedIcon2"
                    disabled
                    label="Druga ikona (więcej kolumn)"
                    :columns="10"
                    class="max-w-sm"
                />
            </div>

            <p class="mt-2 text-sm text-muted-foreground">
                Selected: {{ selectedIcon || '(empty)' }} · {{ selectedIcon2 || '(empty)' }}
            </p>
        </Card>

        <Card class="mt-2">
        </Card>

        <Card class="mt-2">
            <div class="flex items-center gap-2 p-2">
                <Button @click="showToast('neutral')">Show Neutral Toast</Button>
                <Button @click="showToast('success')" variant="success">Show Success Toast</Button>
                <Button @click="showToast('warning')" variant="warning">Show Warning Toast</Button>
                <Button @click="showToast('danger')" variant="danger">Show Danger Toast</Button>
            </div>
        </Card>

        <Card class="mt-2">
            <Tooltip side="top" class="ml-4">
                <Button size="lg" variant="primary">
                    ℹ️
                </Button>

                <template #content>
                    <div class="space-y-1 w-[200px]">
                        <div class="font-semibold">Skrót</div>
                        <div class="text-background/80">Ctrl + K</div>
                    </div>
                </template>
            </Tooltip>
        </Card>

        <Card class="mt-2">
            <div class="flex items-center gap-3">
                <Skeleton rounded="full" width="40px" height="40px" />
                <div class="space-y-2">
                    <Skeleton width="180px" height="12px" />
                    <Skeleton width="120px" height="12px" class="opacity-70" />
                </div>
            </div>

            <div class="mt-2 rounded-xl border border-secondary/60 bg-background p-4 space-y-3">
                <Skeleton width="60%" height="14px" />
                <Skeleton width="100%" height="10px" />
                <Skeleton width="92%" height="10px" />
                <Skeleton width="75%" height="10px" />
                <div class="flex gap-2 pt-2">
                    <Skeleton rounded="full" width="84px" height="32px" />
                    <Skeleton rounded="full" width="84px" height="32px" />
                </div>
            </div>
        </Card>

        <Card class="mt-2">
            <EmptyState
                title="Brak postów w kolejce"
                description="Dodaj pierwszy post albo zaimportuj go z kampanii."
                actionLabel="Dodaj post"
                @action="() => console.log('add')"
            >
                <template #icon>
                    <span class="text-foreground/70">🗂️</span>
                </template>
            </EmptyState>
        </Card>

        <Card class="mt-2">
            <TextInput v-model="q" label="Szukaj" placeholder="np. campaign, post..." class="w-72" />
            <SelectInput
            class="h-full"
                v-model="status"
                :options="[
                    { label: 'Wszystkie', value: '' },
                    { label: 'Draft', value: 'draft' },
                    { label: 'Scheduled', value: 'scheduled' },
                    { label: 'Published', value: 'published' }
                ]"
            />
            <FilterBar :active="active" @remove="removeFilter" @clear="clear">
                <template #actions>
                    <!-- np. export / bulk -->
                    <Button>
                        Export
                    </Button>
                </template>
            </FilterBar>
        </Card>

        <!-- Timeline Example 1: Change History (matching screenshot) -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Timeline: Change History</h3>
            <Timeline :items="historyItems">
                <template #afterTitle="{ item }">
                    <Badge :tone="item.badgeTone">{{ item.badgeLabel }}</Badge>
                </template>
                <template #actions="{ item }">
                    <button
                        class="text-muted-foreground hover:text-foreground transition-colors p-1 mt-2"
                        title="More actions"
                    >
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 12.75a.75.75 0 110-1.5.75.75 0 010 1.5zM12 18.75a.75.75 0 110-1.5.75.75 0 010 1.5z" />
                        </svg>
                    </button>
                </template>
                <template #default="{ item }">
                    <div class="bg-card border border-border rounded-md p-3 text-sm text-foreground my-2">
                        <div>
                            Changed <strong>{{ item.field }}</strong>
                        </div>
                        <div v-if="item.oldValue" class="mt-1 text-foreground/70">
                            From: <span class="text-foreground">{{ item.oldValue }}</span>
                        </div>
                        <div class="mt-1">
                            Set to: <span class="text-primary font-medium">{{ item.newValue }}</span>
                        </div>
                    </div>
                </template>
            </Timeline>
        </Card>

        <!-- Timeline Example 2: Events List (custom avatar slot) -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Timeline: Events Log</h3>
            <Timeline :items="eventItems">
                <template #avatar="{ item }">
                    <div
                        class="w-10 h-10 rounded-full bg-primary/10 flex items-center justify-center text-primary text-lg"
                    >
                        {{ item.icon }}
                    </div>
                </template>
                <template #default="{ item }">
                    <p class="text-sm text-foreground/80">{{ item.description }}</p>
                </template>
            </Timeline>
        </Card>

        <!-- Timeline Example 3: Compact Mode -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Timeline: Compact Mode</h3>
            <Timeline :items="compactItems" compact>
                <template #default="{ item }">
                    <span class="text-xs text-foreground/70">{{ item.summary }}</span>
                </template>
            </Timeline>
        </Card>

        <!-- NEW COMPONENTS DEMOS -->

        <!-- LoadingSpinner -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Loading Spinner</h3>
            <div class="flex gap-8">
                <LoadingSpinner size="sm" />
                <LoadingSpinner size="md" label="Loading data..." />
                <LoadingSpinner size="lg" label="Please wait" />
            </div>
        </Card>

        <!-- Breadcrumbs -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Breadcrumbs</h3>
            <Breadcrumbs :items="breadcrumbItems" />
        </Card>

        <!-- StatusBadge -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Status Badge</h3>
            <div class="flex gap-2 flex-wrap">
                <StatusBadge status="pending" />
                <StatusBadge status="processing" />
                <StatusBadge status="success" />
                <StatusBadge status="error" />
                <StatusBadge status="warning" />
                <StatusBadge status="info" label="Custom Label" />
            </div>
        </Card>

        <!-- Navbar -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Navbar</h3>
            <Navbar
                title="TaskIO"
                :items="navItems"
                searchable
                @nav-click="handleNavClick"
                @search="handleNavSearch"
                @user-menu="handleUserMenu"
                class="-m-4 border-b"
            />
        </Card>

        <!-- DataTable -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Data Table</h3>
            <DataTable
                :columns="taskTableColumns"
                :data="taskTableData"
                striped
                hoverable
                @row-click="handleRowClick"
            />
        </Card>

        <!-- Pagination (Infinite Scroll / Load More) -->
        <Card class="mt-2">
            <h3 class="text-base font-semibold text-foreground mb-4">Pagination (Cursor-based)</h3>
            <p class="text-sm text-muted-foreground mb-4">
                Cursor-based pagination for infinite scroll. Click to load more items.
            </p>
            <Pagination
                v-model="paginationState"
                variant="offset"
                @load-more="handleLoadMore"
            />
        </Card>
        </div>
    </div>
</template>

<script setup lang="ts">
    import Card from './ui/Card.vue';
    import Button from './ui/Button.vue';
    import TextInput from './ui/inputs/TextInput.vue';
    import TextareaInput from './ui/inputs/TextareaInput.vue';
    import SwitchInput from './ui/inputs/SwitchInput.vue';
    import SelectInput from './ui/inputs/SelectInput.vue';
    import Badge from './ui/Badge.vue';
    import Dialog from './ui/Dialog.vue';
    import Tabs from './ui/Tabs.vue';
    import DropdownMenu from './ui/DropdownMenu.vue';
    import Tooltip from './ui/Tooltip.vue';
    import Skeleton from './ui/Skeleton.vue';
    import EmptyState from './ui/tables/EmptyState.vue';
    import FilterBar from './ui/tables/FilterBar.vue';
    import PageHeader from './ui/patterns/PageHeader.vue';
    import EntityCard from './ui/patterns/EntityCard.vue';
    import Avatar from './ui/Avatar.vue';
    import StatCard from './ui/patterns/StatCard.vue';
    import StatsGrid from './ui/patterns/StatsGrid.vue';
    import Timeline from './ui/patterns/Timeline.vue';
    import FileDropzone from './ui/inputs/FileDropzone.vue';
    import Sheet from './ui/patterns/Sheet.vue';
    import ConfirmDialog from './ui/ConfirmDialog.vue';
    import CheckboxInput from './ui/inputs/CheckboxInput.vue';
    import RadioGroupInput, { type RadioOption } from './ui/inputs/RadioGroupInput.vue';
    import DateInput from './ui/inputs/DateInput.vue';
    import TimeInput from './ui/inputs/TimeInput.vue';
    import ColorInput from './ui/inputs/ColorInput.vue';
    import NumberInput from './ui/inputs/NumberInput.vue';
    import PillGroupInput from './ui/inputs/PillGroupInput.vue';
    import SliderInput from './ui/inputs/SliderInput.vue';
    import RichTextInput from './ui/inputs/RichTextInput.vue';
    import LoadingSpinner from './ui/LoadingSpinner.vue';
    import Breadcrumbs from './ui/Breadcrumbs.vue';
    import StatusBadge from './ui/StatusBadge.vue';
    import DataTable, { type Column } from './ui/tables/DataTable.vue';
    import Pagination from './ui/Pagination.vue';
    import Navbar, { type NavItem } from './ui/Navbar.vue';
    import IconInput from './ui/inputs/IconInput.vue';
    import { useToast } from '@/composables/useToast';
    import { ref, computed, onMounted } from 'vue';

    // Dark mode state and logic
    const isDarkMode = ref(false);

    function initTheme() {
        // Check localStorage first
        const stored = localStorage.getItem('theme');
        if (stored === 'dark' || stored === 'light') {
            isDarkMode.value = stored === 'dark';
        } else {
            // Fall back to system preference
            isDarkMode.value = window.matchMedia('(prefers-color-scheme: dark)').matches;
        }
        applyTheme();
    }

    function applyTheme() {
        const html = document.documentElement;
        if (isDarkMode.value) {
            html.classList.add('dark');
            localStorage.setItem('theme', 'dark');
        } else {
            html.classList.remove('dark');
            localStorage.setItem('theme', 'light');
        }
    }

    function toggleTheme() {
        isDarkMode.value = !isDarkMode.value;
        applyTheme();
    }

    onMounted(() => {
        initTheme();
    });

    // example state for selects
    const color = ref(null);
    const tags = ref<any[]>([]);

    const buttonVariants = ['primary', 'secondary', 'ghost', 'danger'] as const;
    const buttonSizes = ['sm', 'md', 'lg'] as const;

    // mock loader for remote example (pages)
    const mockData = Array.from({ length: 60 }).map((_, i) => ({ label: `Option ${i + 1}`, value: `opt_${i + 1}` }));
    function mockLoader(
        url?: string,
        query?: string
    ): Promise<{ data: Array<{ label: string; value: string }>; next?: string }> {
        // url is used as page cursor, e.g. `page=2` simulated via string
        const page = url ? Number(url) : 0;
        const pageSize = 10;
        const start = page * pageSize;
        const data = mockData.slice(start, start + pageSize).filter(d => !query || d.label.toLowerCase().includes(query.toLowerCase()));
        const next = start + pageSize < mockData.length ? String(page + 1) : undefined;
        return new Promise((resolve) => setTimeout(() => resolve({ data, next }), 400));
    }

    const sw = ref(false);
    const showDialog = ref(false);
    const remote = ref(null);

    const textValue = ref('');
    const textareaValue = ref('');

    const { push } = useToast();

    function handleAction(action: string, closeMenu?: () => void) {
        console.log(action);
        if (typeof closeMenu === 'function') closeMenu();
    }

    const showToast = (tone: 'neutral'|'success'|'warning'|'danger') => {
        push({
            title: tone[0].toUpperCase() + tone.slice(1),
            message: `This is a ${tone} toast.`,
            tone,
            timeoutMs: 4000,
        });
    };

    const tab = ref("queue");

    const tabs = [
        { id: "queue", label: "Queue", badge: 12 },
        { id: "editor", label: "Editor" },
        { id: "history", label: "History", badge: 3 },
        { id: "accounts", label: "Accounts" },
    ];

    const open = ref(false);

    const q = ref("");
    const status = ref("");

    const active = computed(() => {
        const a: Array<{ key: string; label: string }> = [];
        if (q.value) a.push({ key: "q", label: `Szukaj: ${q.value}` });
        if (status.value) a.push({ key: "status", label: `Status: ${status.value}` });
        return a;
    });

    function removeFilter(key: string) {
        if (key === "q") q.value = "";
        if (key === "status") status.value = "";
    }

    function clear() {
        q.value = "";
        status.value = "";
    }

    const isSheetOpen = ref(false);
    const confirmOpen = ref(false);
    const confirmLoading = ref(false);
    const agreedTerms = ref(false);

    function confirmDialogConfirm () {
        confirmLoading.value = true;
        console.log('Cofirm!!!');
        setTimeout(() => {
            confirmLoading.value = false;
        }, 2000);
    }

    const radioGroupMode = ref<string | number>("draft");

    const radioGroupOptions: RadioOption[] = [
        { value: "draft", label: "Draft", description: "Zapisz bez publikacji" },
        { value: "schedule", label: "Schedule", description: "Ustaw datę i godzinę" },
        { value: "publish", label: "Publish now", description: "Opublikuj od razu" },
    ];

    const date = ref("");
    const time = ref("");
    const dateTime = ref("");

    const selectedColor = ref('#FF3B30');
    const selectedColor2 = ref(null);

    const daysSelected = ref<string[]>(['mon','thu','fri']);

    const count = ref("10");     // NumberInput zwraca string (tak jak TextInput)
    const intensity = ref(50);   // SliderInput zwraca number
    const range = ref<[number, number]>([20, 80]);
    const richText = ref("<p>Treść…</p>");

    // Timeline example data
    const historyItems = [
        {
            id: 1,
            avatar: { name: 'John Admin', src: '' },
            title: 'John Admin',
            time: 'Nov 10, 2025 at 8:00 AM',
            badgeTone: 'primary',
            badgeLabel: 'Created',
            field: 'Task',
            newValue: 'Write API documentation',
        },
        {
            id: 2,
            avatar: { name: 'Alex Smith' },
            title: 'Alex Smith',
            time: 'Nov 11, 2025 at 10:00 AM',
            badgeTone: 'warning',
            badgeLabel: 'Assigned',
            field: 'Assignee',
            newValue: 'Alex Smith',
        },
        {
            id: 3,
            avatar: { name: 'Alex Smith' },
            title: 'Alex Smith',
            time: 'Nov 11, 2025 at 10:05 AM',
            badgeTone: 'success',
            badgeLabel: 'Status Changed',
            field: 'Status',
            oldValue: 'To Do',
            newValue: 'In Progress',
        },
    ];

    const eventItems = [
        {
            id: 1,
            icon: '📝',
            title: 'Task Created',
            time: 'Jan 5, 2026',
            description: 'New task "Implement user authentication" was created in the backlog.',
        },
        {
            id: 2,
            icon: '🚀',
            title: 'Deployment',
            time: 'Jan 4, 2026',
            description: 'Version 2.1.0 deployed to production successfully.',
        },
        {
            id: 3,
            icon: '💬',
            title: 'Comment Added',
            time: 'Jan 3, 2026',
            description: 'Marta added a comment: "Please review the design mockups."',
        },
    ];

    const compactItems = [
        { id: 1, avatar: 'JD', title: 'John Doe', time: '10:23', summary: 'Updated config file' },
        { id: 2, avatar: 'MS', title: 'Mary Smith', time: '09:15', summary: 'Merged PR #123' },
        { id: 3, avatar: 'AJ', title: 'Alex Johnson', time: '08:45', summary: 'Created new branch' },
        { id: 4, avatar: 'SK', title: 'Sarah Kim', time: '08:12', summary: 'Fixed bug in auth' },
    ];

    // New components demo state
    const searchQuery = ref("");
    const selectedIcon = ref<string | null>("search");
    const selectedIcon2 = ref<string | null>(null);
    
    // DataTable demo
    interface TaskItem {
        id: number;
        title: string;
        status: string;
        priority: string;
        assignee: string;
    }

    const taskTableData = ref<TaskItem[]>([
        { id: 1, title: 'Implement dark mode', status: 'In Progress', priority: 'High', assignee: 'John' },
        { id: 2, title: 'Fix login bug', status: 'Done', priority: 'Critical', assignee: 'Jane' },
        { id: 3, title: 'Update documentation', status: 'To Do', priority: 'Low', assignee: 'Bob' },
        { id: 4, title: 'Design new dashboard', status: 'In Progress', priority: 'High', assignee: 'Sarah' },
        { id: 5, title: 'API integration', status: 'To Do', priority: 'Medium', assignee: 'Mike' },
    ]);

    const taskTableColumns: Column<TaskItem>[] = [
        { key: 'id', label: 'ID', width: '60px' },
        { key: 'title', label: 'Title', width: '250px' },
        { key: 'status', label: 'Status' },
        { key: 'priority', label: 'Priority' },
        { key: 'assignee', label: 'Assignee' },
    ];

    const handleRowClick = (row: TaskItem) => {
        console.log('Clicked row:', row);
    };

    // Pagination demo (infinite scroll - cursor based)
    const paginationState = ref({
        cursor: null as string | null,
        hasMore: true,
        pageSize: 10,
    });

    const handleLoadMore = () => {
        console.log('Loading more...');
        setTimeout(() => {
            paginationState.value.hasMore = false; // Example: no more items after first load
        }, 500);
    };

    // Navbar demo
    const navItems: NavItem[] = [
        { label: 'Dashboard', href: '#', active: true },
        { label: 'Projects', href: '#' },
        { label: 'Tasks', href: '#', badge: 5 },
        { label: 'Settings', href: '#' },
    ];

    const handleNavClick = (item: NavItem) => {
        console.log('Nav clicked:', item);
    };

    const handleNavSearch = (query: string) => {
        console.log('Search query:', query);
    };

    const handleUserMenu = (action: string) => {
        console.log('User menu action:', action);
    };

    // Breadcrumbs demo
    const breadcrumbItems = [
        { label: 'Home', href: '#' },
        { label: 'Projects', href: '#' },
        { label: 'Current Project' },
    ];

</script>