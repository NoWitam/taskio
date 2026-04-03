<script setup lang="ts">
import { computed } from 'vue'
import { marked } from 'marked'
import DOMPurify from 'dompurify'

const props = defineProps<{
    content: string
}>()

const renderedHTML = computed(() => {
    if (!props.content) return ''
    
    // Configure marked
    marked.setOptions({
        breaks: true,
        gfm: true, // GitHub Flavored Markdown
    })
    
    // Convert markdown to HTML
    const rawHTML = marked.parse(props.content) as string
    
    // Sanitize HTML for security
    return DOMPurify.sanitize(rawHTML, {
        ALLOWED_TAGS: [
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'p', 'br', 'strong', 'em', 'u', 'code', 'pre',
            'ul', 'ol', 'li',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'a', 'blockquote', 'hr',
            'del', 'ins', 'sup', 'sub'
        ],
        ALLOWED_ATTR: ['href', 'title', 'target', 'rel']
    })
})
</script>

<template>
    <div 
        class="markdown-content"
        v-html="renderedHTML"
    />
</template>

<style scoped>
.markdown-content {
    color: var(--color-foreground);
}

/* Headings */
.markdown-content :deep(h1) {
    font-size: 1.875rem;
    font-weight: 700;
    margin-top: 1.5rem;
    margin-bottom: 1rem;
}

.markdown-content :deep(h2) {
    font-size: 1.5rem;
    font-weight: 700;
    margin-top: 1.25rem;
    margin-bottom: 0.75rem;
}

.markdown-content :deep(h3) {
    font-size: 1.25rem;
    font-weight: 700;
    margin-top: 1rem;
    margin-bottom: 0.5rem;
}

.markdown-content :deep(h4) {
    font-size: 1.125rem;
    font-weight: 600;
    margin-top: 0.75rem;
    margin-bottom: 0.5rem;
}

.markdown-content :deep(h5) {
    font-size: 1rem;
    font-weight: 600;
    margin-top: 0.5rem;
    margin-bottom: 0.25rem;
}

.markdown-content :deep(h6) {
    font-size: 0.875rem;
    font-weight: 600;
    margin-top: 0.5rem;
    margin-bottom: 0.25rem;
    color: var(--color-muted-foreground);
}

/* Paragraphs */
.markdown-content :deep(p) {
    margin-bottom: 1rem;
    line-height: 1.625;
}

/* Lists */
.markdown-content :deep(ul) {
    list-style-type: disc;
    list-style-position: inside;
    margin-bottom: 1rem;
}

.markdown-content :deep(ul li) {
    margin-bottom: 0.5rem;
}

.markdown-content :deep(ol) {
    list-style-type: decimal;
    list-style-position: inside;
    margin-bottom: 1rem;
}

.markdown-content :deep(ol li) {
    margin-bottom: 0.5rem;
}

.markdown-content :deep(li) {
    line-height: 1.625;
}

.markdown-content :deep(li > ul),
.markdown-content :deep(li > ol) {
    margin-left: 1.5rem;
    margin-top: 0.5rem;
}

/* Text styles */
.markdown-content :deep(strong) {
    font-weight: 700;
}

.markdown-content :deep(em) {
    font-style: italic;
}

.markdown-content :deep(u) {
    text-decoration: underline;
}

.markdown-content :deep(del) {
    text-decoration: line-through;
    color: var(--color-muted-foreground);
}

/* Code */
.markdown-content :deep(code) {
    background-color: var(--color-muted);
    padding: 0.125rem 0.375rem;
    border-radius: 0.25rem;
    font-size: 0.875rem;
    font-family: ui-monospace, monospace;
}

.markdown-content :deep(pre) {
    background-color: var(--color-muted);
    padding: 1rem;
    border-radius: 0.5rem;
    margin-bottom: 1rem;
    overflow-x: auto;
}

.markdown-content :deep(pre code) {
    background-color: transparent;
    padding: 0;
}

/* Links */
.markdown-content :deep(a) {
    color: var(--color-primary);
    text-decoration: underline;
    transition: color 0.2s;
}

.markdown-content :deep(a:hover) {
    opacity: 0.8;
}

/* Blockquotes */
.markdown-content :deep(blockquote) {
    border-left: 4px solid var(--color-border);
    padding-left: 1rem;
    padding-top: 0.5rem;
    padding-bottom: 0.5rem;
    margin-bottom: 1rem;
    font-style: italic;
    color: var(--color-muted-foreground);
}

/* Horizontal rule */
.markdown-content :deep(hr) {
    border-top: 1px solid var(--color-border);
    margin-top: 1.5rem;
    margin-bottom: 1.5rem;
}

/* Tables */
.markdown-content :deep(table) {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 1rem;
    font-size: 0.875rem;
}

.markdown-content :deep(thead) {
    background-color: var(--color-muted);
}

.markdown-content :deep(th) {
    border: 1px solid var(--color-border);
    padding: 0.5rem 1rem;
    text-align: left;
    font-weight: 600;
}

.markdown-content :deep(td) {
    border: 1px solid var(--color-border);
    padding: 0.5rem 1rem;
}

.markdown-content :deep(tbody tr:hover) {
    background-color: color-mix(in srgb, var(--color-muted) 50%, transparent);
}

/* First element margin */
.markdown-content :deep(> *:first-child) {
    margin-top: 0;
}

/* Last element margin */
.markdown-content :deep(> *:last-child) {
    margin-bottom: 0;
}
</style>
