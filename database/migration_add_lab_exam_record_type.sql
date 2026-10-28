-- Lab exam: one per term per subject like exam; combined with exam for term_examination %.
ALTER TABLE `assessment_activities`
  MODIFY `record_type` ENUM(
    'class_participation','quiz','project','assignment','exam','lab_exam','graded_activity','exercise','values'
  ) NOT NULL DEFAULT 'quiz';

ALTER TABLE `assessment_records`
  MODIFY `record_type` ENUM(
    'class_participation','quiz','project','assignment','exam','lab_exam','graded_activity','values','exercise'
  ) NOT NULL;
