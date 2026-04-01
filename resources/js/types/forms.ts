import type { User } from './index'

// ============================================
// FORM ELEMENT TYPES
// ============================================

export type FormElementType =
    | 'section'
    | 'grid'
    | 'repeater'
    | 'heading'
    | 'text_block'
    | 'divider'
    | 'short_text'
    | 'long_text'
    | 'select'
    | 'image'
    | 'checkbox'
    | 'number'
    | 'date'
    | 'time'
    | 'url'
    | 'checklist'

export type FormElementCategory = 'layout' | 'content' | 'input'

// ============================================
// BASE ELEMENT INTERFACE
// ============================================

export interface FormElement {
    id: string // UUID (generated in UI)
    type: FormElementType
    config: Record<string, any>
}

// ============================================
// LAYOUT ELEMENTS
// ============================================

export interface SectionElement extends FormElement {
    type: 'section'
    config: {
        name: string
        icon?: string
        description?: string
        children: FormElement[]
    }
}

export interface GridColumn {
    element: FormElement // Only input types allowed
    width: 25 | 50 | 75 | 100 // Percentage
}

export interface GridElement extends FormElement {
    type: 'grid'
    config: {
        columns: GridColumn[] // Max 4 columns
    }
}

export interface RepeaterElement extends FormElement {
    type: 'repeater'
    config: {
        name: string
        icon?: string
        description?: string
        min: number
        max: number
        children: FormElement[]
    }
}

// ============================================
// CONTENT ELEMENTS
// ============================================

export interface HeadingElement extends FormElement {
    type: 'heading'
    config: {
        level: 1 | 2 | 3
        text: string
    }
}

export interface TextBlockElement extends FormElement {
    type: 'text_block'
    config: {
        content: string
    }
}

export interface DividerElement extends FormElement {
    type: 'divider'
    config: Record<string, never> // Empty object
}

// ============================================
// INPUT FIELDS - BASE
// ============================================

export interface BaseInputConfig {
    label: string
    hint?: string
    required?: boolean
    placeholder?: string
}

// ============================================
// INPUT FIELDS - SPECIFIC TYPES
// ============================================

export interface ShortTextElement extends FormElement {
    type: 'short_text'
    config: BaseInputConfig & {
        minLength?: number
        maxLength?: number
        pattern?: string
    }
}

export interface LongTextElement extends FormElement {
    type: 'long_text'
    config: BaseInputConfig & {
        minLength?: number
        maxLength?: number
        rows?: number
    }
}

export interface SelectOption {
    value: string
    label: string
    icon?: string
    hint?: string
}

export interface SelectElement extends FormElement {
    type: 'select'
    config: BaseInputConfig & {
        options: SelectOption[]
        multiple?: boolean
    }
}

export interface ChecklistElement extends FormElement {
    type: 'checklist'
    config: BaseInputConfig & {
        options: SelectOption[]
    }
}

export interface NumberElement extends FormElement {
    type: 'number'
    config: BaseInputConfig & {
        min?: number
        max?: number
        step?: number
    }
}

export interface DateElement extends FormElement {
    type: 'date'
    config: BaseInputConfig & {
        min?: string // YYYY-MM-DD
        max?: string // YYYY-MM-DD
    }
}

export interface TimeElement extends FormElement {
    type: 'time'
    config: BaseInputConfig
}

export interface UrlElement extends FormElement {
    type: 'url'
    config: BaseInputConfig
}

export interface ImageElement extends FormElement {
    type: 'image'
    config: BaseInputConfig & {
        maxSize?: number // MB
        acceptedTypes?: string[] // ['image/jpeg', 'image/png', etc.]
    }
}

export interface CheckboxElement extends FormElement {
    type: 'checkbox'
    config: {
        label: string
        hint?: string
    }
}

// ============================================
// UNION TYPE FOR ALL ELEMENTS
// ============================================

export type AnyFormElement =
    | SectionElement
    | GridElement
    | RepeaterElement
    | HeadingElement
    | TextBlockElement
    | DividerElement
    | ShortTextElement
    | LongTextElement
    | SelectElement
    | ChecklistElement
    | NumberElement
    | DateElement
    | TimeElement
    | UrlElement
    | ImageElement
    | CheckboxElement

// ============================================
// FORM & SUBMISSION
// ============================================

export interface Form {
    id: string
    name: string
    icon: string | null
    description: string | null
    content: FormElement[]
    is_anonymous: boolean
    creator?: User
    submissions_count?: number
    created_at: string
    updated_at: string
}

export interface FormSubmission {
    id: string
    form_id: string
    form?: Form
    data: Record<string, any> // { element_id: value }
    creator?: User
    created_at: string
    updated_at: string
}

// ============================================
// FORM SUBMISSION DATA VALUES
// ============================================

export type FormSubmissionValue = 
    | string 
    | number 
    | boolean 
    | string[] 
    | Record<string, any>
    | null

// ============================================
// VALIDATION ERROR
// ============================================

export interface FormValidationError {
    elementId: string
    message: string
}

// ============================================
// BUILDER STATE
// ============================================

export interface FormBuilderState {
    elements: FormElement[]
    selectedElementId: string | null
    draggedElementType: FormElementType | null
}
