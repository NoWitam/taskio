import type { User } from '@/types';

export type ApproverType = 'user' | 'ai';
export type ApprovalProcessStatus = 'pending' | 'approved' | 'rejected';

export interface ApprovalStage {
    id: string;
    name: string;
    icon: string | null;
    description: string | null;
    approver_type: ApproverType;
    approver: User | null;
    order: number;
}

export interface ApprovalPipeline {
    id: string;
    name: string;
    icon: string | null;
    description: string | null;
    stages: ApprovalStage[];
    creator?: User;
    can_be_edited: boolean;
    can_be_deleted: boolean;
    created_at: string;
}

export interface ApprovalPipelineListItem {
    id: string;
    name: string;
    icon: string | null;
    description: string | null;
    stages_count: number;
    stages: Array<{
        name: string;
        icon: string | null;
        order: number;
    }>;
    can_be_edited: boolean;
    can_be_deleted: boolean;
    created_at: string;
}

export interface ApprovalProcess {
    id: string;
    run_id: string;
    status: ApprovalProcessStatus;
    note: string | null;
    approver_type: ApproverType;
    approver: User | null;
    pipeline: ApprovalPipeline | null;
    stage: ApprovalStage | null;
    decided_at: string | null;
    created_at: string;
}

export interface ApprovalQueueEntity {
    id: string;
    type: string;
    type_label: string;
    type_icon: string;
    name: string;
    description: string | null;
    extra_fields: Array<{
        label: string;
        value: string;
        icon: string;
    }>;
    form: {
        id: string;
        name: string;
        content: any[];
        submission: Record<string, any> | null;
    } | null;
    comments_url: string | null;
}

export interface ApprovalQueueItem {
    process: {
        id: string;
        run_id: string;
        status: ApprovalProcessStatus;
        approver_type: ApproverType;
        created_at: string;
    };
    pipeline: {
        id: string;
        name: string;
        icon: string | null;
    } | null;
    stage: {
        id: string;
        name: string;
        icon: string | null;
        description: string | null;
        order: number;
    } | null;
    entity: ApprovalQueueEntity | null;
}

export interface StageInput {
    name: string;
    icon: string | null;
    description: string | null;
    approver_type: ApproverType;
    approver_id: string | null;
}

export interface ApprovalRunHistoryItem {
    id: string;
    run_id: string;
    status: ApprovalProcessStatus;
    note: string | null;
    approver_type: ApproverType;
    approver: User | null;
    stage: ApprovalStage | null;
    decided_at: string | null;
    created_at: string;
}
