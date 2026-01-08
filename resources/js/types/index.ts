// Wspólne typy używane w całej aplikacji

export interface User {
    id?: number;
    name: string;
    email?: string;
    avatar?: string | null;
}

export interface Label {
    id?: number;
    text: string;
    color?: string;
}

export interface ApiMeta {
    next_cursor: string | null;
    prev_cursor: string | null;
    per_page: number;
    total?: number;
}

export interface ApiResponse<T> {
    data: T;
    meta?: ApiMeta;
}

export interface CursorPaginationParams {
    cursor?: string | null;
    per_page?: number;
}
