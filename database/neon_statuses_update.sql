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
