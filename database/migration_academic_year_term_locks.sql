-- Per-term recording locks (Prelim=1, Midterm=2, Final=3). When 1, instructors cannot record for that term; students may still view.
-- Run once on existing databases.

ALTER TABLE `academic_years`
  ADD COLUMN `lock_term_1` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Prelim locked for instructors' AFTER `is_locked`,
  ADD COLUMN `lock_term_2` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Midterm locked' AFTER `lock_term_1`,
  ADD COLUMN `lock_term_3` tinyint(1) NOT NULL DEFAULT 0 COMMENT '1=Final locked' AFTER `lock_term_2`;
