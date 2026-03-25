USE studysync_db;

ALTER TABLE schedule_events
    ADD COLUMN IF NOT EXISTS is_generated TINYINT(1) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS generated_from_assignment_id INT NULL,
    ADD KEY idx_schedule_time (user_id, start_time, end_time),
    ADD KEY idx_schedule_generated (user_id, is_generated, generated_from_assignment_id);

ALTER TABLE assignments
    ADD KEY idx_assignments_due (user_id, status, due_date);

ALTER TABLE group_members
    ADD KEY idx_group_members_user (user_id, group_id);

ALTER TABLE group_messages
    ADD KEY idx_group_messages_feed (group_id, sent_at);

ALTER TABLE resources
    ADD KEY idx_resources_main (user_id, type, uploaded_at),
    ADD KEY idx_resources_group (group_id, uploaded_at);

ALTER TABLE grades
    ADD KEY idx_grades_user_semester (user_id, semester);

ALTER TABLE notifications
    ADD COLUMN IF NOT EXISTS notification_key VARCHAR(190) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS related_item_type VARCHAR(30) DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS related_item_id INT DEFAULT NULL,
    ADD UNIQUE KEY uniq_notification_per_user (user_id, notification_key),
    ADD KEY idx_notifications_feed (user_id, is_read, created_at);
