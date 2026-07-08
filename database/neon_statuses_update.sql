-- تحديث حالات الطلبات والمطاليب في Neon PostgreSQL
-- نفذ هذا الملف مرة واحدة من SQL Editor في Neon.

-- =========================
-- حالات الطلبات
-- =========================
ALTER TABLE orders
ALTER COLUMN status TYPE TEXT;

ALTER TABLE orders
ALTER COLUMN status SET DEFAULT 'submitted';

ALTER TABLE orders
DROP CONSTRAINT IF EXISTS orders_status_check;

ALTER TABLE orders
ADD CONSTRAINT orders_status_check
CHECK (
    status IN (
        'submitted',
        'under_direction',
        'directed',
        'in_execution',
        'waiting_receipt',
        'waiting_approval',
        'completed',
        'cancelled'
    )
);

-- =========================
-- حالات المطاليب
-- =========================
ALTER TABLE requirements
ALTER COLUMN status TYPE TEXT;

ALTER TABLE requirements
ALTER COLUMN status SET DEFAULT 'new';

ALTER TABLE requirements
DROP CONSTRAINT IF EXISTS requirements_status_check;

ALTER TABLE requirements
ADD CONSTRAINT requirements_status_check
CHECK (
    status IN (
        'new',
        'directed',
        'received_by_executor',
        'action_done',
        'received_by_requester',
        'returned_to_executor',
        'closed',
        'cancelled'
    )
);

-- =========================
-- جدول اعتماد استلام الإجراءات
-- =========================
CREATE TABLE IF NOT EXISTS receipt_approvals (
    id BIGSERIAL PRIMARY KEY,
    requirement_id BIGINT NOT NULL REFERENCES requirements(id) ON DELETE CASCADE,
    approved_by_user_id BIGINT NOT NULL REFERENCES users(id),
    approval_status TEXT NOT NULL DEFAULT 'approved',
    approval_notes TEXT NULL,
    approved_at TIMESTAMP NULL DEFAULT NOW(),
    created_at TIMESTAMP NULL DEFAULT NOW(),
    updated_at TIMESTAMP NULL DEFAULT NOW()
);

ALTER TABLE receipt_approvals
DROP CONSTRAINT IF EXISTS receipt_approvals_status_check;

ALTER TABLE receipt_approvals
ADD CONSTRAINT receipt_approvals_status_check
CHECK (approval_status IN ('approved', 'rejected'));

CREATE INDEX IF NOT EXISTS idx_receipt_approvals_requirement_id
ON receipt_approvals(requirement_id);
