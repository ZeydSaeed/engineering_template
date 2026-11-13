import type { TimelineEntry } from '@/types/table';

/** The states of a request (Domain\RequestStatus, ADR-0033). */
export type RequestStatus = 'draft' | 'submitted' | 'approved' | 'rejected';

export type RequestRow = {
    uuid: string;
    title: string;
    requester: string | null;
    status: RequestStatus;
    amount: string | null;
    submitted_at: string | null;
    created_at: string;
};

export type Attachment = {
    uuid: string;
    name: string;
    size: number;
    status: 'pending' | 'verified' | 'rejected';
};

export type FullRequest = RequestRow & {
    details: string;
    lock_version: number;
    is_mine: boolean;
    decider: string | null;
    decided_at: string | null;
    decision_note: string | null;
    attachments: Attachment[];
    timeline: TimelineEntry[];
    can: {
        update: boolean;
        submit: boolean;
        decide: boolean;
        see_all: boolean;
    };
};
