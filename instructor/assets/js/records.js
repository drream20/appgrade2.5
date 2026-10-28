(function() {
  function post(formData, done) {
    if (window.canRecord === false) { done({ ok: false, message: 'Recording is disabled. Academic year is inactive or locked.' }); return; }
    fetch('records.php', { method: 'POST', body: formData })
      .then(r => r.json())
      .then(done)
      .catch(() => done({ ok: false, message: 'Request failed' }));
  }

  function getCurrentTimeHM() {
    const now = new Date();
    const h = String(now.getHours()).padStart(2, '0');
    const m = String(now.getMinutes()).padStart(2, '0');
    return h + ':' + m;
  }

  function appendClassSection(fd) {
    if (typeof window.currentSectionId !== 'undefined' && window.currentSectionId) {
      fd.append('class_section_id', window.currentSectionId);
    }
  }

  function recordDateNotFutureYmd(ymd) {
    if (!ymd || typeof ymd !== 'string') return false;
    var t = ymd.trim().substring(0, 10);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(t)) return false;
    var today = new Date();
    var todayStr = today.getFullYear() + '-' +
      String(today.getMonth() + 1).padStart(2, '0') + '-' +
      String(today.getDate()).padStart(2, '0');
    return t <= todayStr;
  }

  function validateScoreAndMax(rawScore, rawMax) {
    const score = parseFloat(rawScore);
    const max = parseFloat(rawMax);
    // If score or max is not a valid number, consider as cancel/empty entry (allow).
    if (!isFinite(score) || !isFinite(max)) return true;
    if (score > max) {
      // Alert, but DON'T loop forever: user can cancel prompt in caller.
      alert('');
      // Returning false will prevent saving, and the caller will stop (not prompt again on its own).
      return false;
    }
    return true;
  }

  document.querySelectorAll('.add-assessment').forEach(btn => {
    btn.addEventListener('click', function() {
      const eid = this.dataset.eid, tid = this.dataset.tid, name = this.dataset.name;
      const type = prompt('Type: class_participation, quiz, or project', 'quiz');
      if (!type || !['class_participation','quiz','project'].includes(type)) return;
      const score = prompt('Score', '0');
      const max = prompt('Max score', '100');
      if (score === null || max === null) return;
      if (!validateScoreAndMax(score, max)) return;
      const fd = new FormData();
      fd.append('action', 'save_assessment');
      fd.append('enrollment_id', eid);
      fd.append('term_id', tid);
      fd.append('record_type', type);
      fd.append('score', score);
      fd.append('max_score', max);
      fd.append('title', '');
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  document.querySelectorAll('.edit-assessment').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const id = this.dataset.id, eid = this.dataset.eid, tid = this.dataset.tid;
      const type = this.dataset.type, score = this.dataset.score, max = this.dataset.max, title = this.dataset.title || '';
      const newScore = prompt('Score', score);
      const newMax = prompt('Max score', max);
      const newTitle = prompt('Title (optional)', title);
      if (newScore === null || newMax === null) return;
      if (!validateScoreAndMax(newScore, newMax)) return;
      const fd = new FormData();
      fd.append('action', 'save_assessment');
      fd.append('record_id', id);
      fd.append('enrollment_id', eid);
      fd.append('term_id', tid);
      fd.append('record_type', type);
      fd.append('score', newScore);
      fd.append('max_score', newMax);
      fd.append('title', newTitle || '');
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  document.querySelectorAll('.delete-assessment').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      if (!confirm('Delete this assessment?')) return;
      const fd = new FormData();
      fd.append('action', 'delete_assessment');
      fd.append('id', this.dataset.id);
      fd.append('enrollment_id', this.dataset.eid);
      fd.append('term_id', this.dataset.tid);
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  document.querySelectorAll('.add-performance').forEach(btn => {
    btn.addEventListener('click', function() {
      const eid = this.dataset.eid, tid = this.dataset.tid;
      const score = prompt('Score', '0');
      const max = prompt('Max score', '100');
      const title = prompt('Title (optional)', '');
      if (score === null || max === null) return;
      if (!validateScoreAndMax(score, max)) return;
      const fd = new FormData();
      fd.append('action', 'save_performance');
      fd.append('enrollment_id', eid);
      fd.append('term_id', tid);
      fd.append('score', score);
      fd.append('max_score', max);
      fd.append('title', title || '');
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  document.querySelectorAll('.edit-performance').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const id = this.dataset.id, eid = this.dataset.eid, tid = this.dataset.tid;
      const score = this.dataset.score, max = this.dataset.max, title = this.dataset.title || '';
      const newScore = prompt('Score', score);
      const newMax = prompt('Max score', max);
      const newTitle = prompt('Title (optional)', title);
      if (newScore === null || newMax === null) return;
      if (!validateScoreAndMax(newScore, newMax)) return;
      const fd = new FormData();
      fd.append('action', 'save_performance');
      fd.append('record_id', id);
      fd.append('enrollment_id', eid);
      fd.append('term_id', tid);
      fd.append('score', newScore);
      fd.append('max_score', newMax);
      fd.append('title', newTitle || '');
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  document.querySelectorAll('.edit-exam').forEach(el => {
    el.addEventListener('click', function(e) {
      e.preventDefault();
      const eid = this.dataset.eid, tid = this.dataset.tid, score = this.dataset.score || '', max = this.dataset.max || '100';
      const newScore = prompt('Exam score', score);
      const newMax = prompt('Max score', max);
      if (newScore === null || newMax === null) return;
      if (!validateScoreAndMax(newScore, newMax)) return;
      const fd = new FormData();
      fd.append('action', 'save_exam');
      fd.append('enrollment_id', eid);
      fd.append('term_id', tid);
      fd.append('score', newScore);
      fd.append('max_score', newMax);
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  // Attendance modal (Add/Edit) - recording appears only in modal
  document.addEventListener('click', function(e) {
    const btnAdd = e.target.closest('#btn-add-attendance-slot');
    if (btnAdd && typeof window.attendanceEnrollments !== 'undefined' && document.getElementById('modalAttendance')) {
      e.preventDefault();
      document.getElementById('modalAttendanceTitle').textContent = 'Add Attendance';
      document.getElementById('attendanceMode').value = 'add';
      document.getElementById('attendanceDateRow').style.display = '';
      document.getElementById('attendanceDate').value = new Date().toISOString().slice(0, 10);
      document.getElementById('attendanceTime').value = getCurrentTimeHM();
      const statuses = {};
      window.attendanceEnrollments.forEach(en => { statuses[en.enrollment_id] = 'present'; });
      buildAttendanceTable(statuses);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAttendance')).show();
    }
    const btnEdit = e.target.closest('.edit-attendance-slot-btn');
    if (btnEdit && typeof window.attendanceEnrollments !== 'undefined' && document.getElementById('modalAttendance')) {
      e.preventDefault();
      document.getElementById('modalAttendanceTitle').textContent = 'Edit Attendance';
      document.getElementById('attendanceMode').value = 'edit';
      document.getElementById('attendanceDateRow').style.display = 'none';
      const statuses = JSON.parse(btnEdit.dataset.statuses || '{}');
      document.getElementById('attendanceDate').value = btnEdit.dataset.date || '';
      document.getElementById('attendanceTime').value = btnEdit.dataset.time ? btnEdit.dataset.time.substring(0, 5) : '';
      buildAttendanceTable(statuses);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAttendance')).show();
    }
  });
  function buildAttendanceTable(statuses) {
    const tbody = document.getElementById('attendanceStatusBody');
    if (!tbody || !window.attendanceEnrollments) return;
    const letters = { present: 'P', absent: 'A', late: 'L', excused: 'E' };
    tbody.innerHTML = window.attendanceEnrollments.map(en => {
      const s = statuses[en.enrollment_id] || 'present';
      return '<tr><td>' + (en.full_name || '') + ' <span class="text-muted small">' + (en.student_number || '') + '</span></td>' +
        '<td><button type="button" class="btn btn-sm attendance-status-btn status-' + s + '" data-enrollment-id="' + en.enrollment_id + '" data-status="' + s + '"><strong>' + letters[s] + '</strong></button></td></tr>';
    }).join('');
    tbody.querySelectorAll('.attendance-status-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        const next = { present: 'absent', absent: 'late', late: 'excused', excused: 'present' };
        const s = next[this.dataset.status] || 'present';
        this.dataset.status = s;
        this.className = 'btn btn-sm attendance-status-btn status-' + s;
        this.innerHTML = '<strong>' + letters[s] + '</strong>';
      });
    });
  }

  document.addEventListener('click', function(e) {
    if (e.target.closest('#btnSaveAttendanceAll') && typeof window.attendanceEnrollments !== 'undefined' && document.getElementById('modalAttendance')) {
      const mode = document.getElementById('attendanceMode')?.value || 'add';
      const ad = document.getElementById('attendanceDate');
      if (ad && ad.value && !recordDateNotFutureYmd(ad.value)) {
        alert('You cannot record attendance for a future date. Choose today or a past date.');
        return;
      }
      const statuses = {};
      document.querySelectorAll('#attendanceStatusBody .attendance-status-btn').forEach(btn => {
        statuses[btn.dataset.enrollmentId] = btn.dataset.status || 'present';
      });
      const fd = new FormData();
      fd.append('action', mode === 'add' ? 'add_attendance_slot' : 'save_attendance_all');
      fd.append('instructor_subject_id', window.attendanceInstructorSubjectId || '');
      fd.append('term_id', window.attendanceTermId || '');
      fd.append('attendance_date', document.getElementById('attendanceDate').value || '');
      fd.append('attendance_time', document.getElementById('attendanceTime').value || '');
      fd.append('statuses', JSON.stringify(statuses));
      appendClassSection(fd);
      post(fd, r => {
        if (r.ok) {
          bootstrap.Modal.getInstance(document.getElementById('modalAttendance')).hide();
          location.reload();
        } else alert(r.message || 'Error');
      });
    }
  });

  // Delete attendance slot
  document.querySelectorAll('.delete-attendance-slot-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      if (!confirm('Delete this attendance record for all students?')) return;
      const fd = new FormData();
      fd.append('action', 'delete_attendance_slot');
      fd.append('instructor_subject_id', document.getElementById('btn-add-attendance-slot')?.dataset.instructorSubjectId || '');
      fd.append('term_id', document.getElementById('btn-add-attendance-slot')?.dataset.termId || '');
      fd.append('attendance_date', this.dataset.date);
      fd.append('attendance_time', this.dataset.time || '');
      appendClassSection(fd);
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  document.querySelectorAll('.add-attendance').forEach(btn => {
    btn.addEventListener('click', function() {
      const eid = this.dataset.eid, tid = this.dataset.tid;
      const date = prompt('Date (YYYY-MM-DD)', new Date().toISOString().slice(0,10));
      const status = prompt('Status: present, absent, late, excused', 'present');
      if (!date || !status || !['present','absent','late','excused'].includes(status)) return;
      const fd = new FormData();
      fd.append('action', 'save_attendance');
      fd.append('enrollment_id', eid);
      fd.append('term_id', tid);
      fd.append('attendance_date', date);
      fd.append('status', status);
      fd.append('remarks', '');
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  document.querySelectorAll('.edit-attendance').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      const id = this.dataset.id, eid = this.dataset.eid, tid = this.dataset.tid;
      const date = this.dataset.date, status = this.dataset.status, remarks = this.dataset.remarks || '';
      const newDate = prompt('Date (YYYY-MM-DD)', date);
      const newStatus = prompt('Status: present, absent, late, excused', status);
      const newRemarks = prompt('Remarks', remarks);
      if (!newDate || !newStatus || !['present','absent','late','excused'].includes(newStatus)) return;
      const fd = new FormData();
      fd.append('action', 'save_attendance');
      fd.append('record_id', id);
      fd.append('enrollment_id', eid);
      fd.append('term_id', tid);
      fd.append('attendance_date', newDate);
      fd.append('status', newStatus);
      fd.append('remarks', newRemarks || '');
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  document.querySelectorAll('.delete-attendance').forEach(link => {
    link.addEventListener('click', function(e) {
      e.preventDefault();
      if (!confirm('Delete this attendance record?')) return;
      const fd = new FormData();
      fd.append('action', 'delete_attendance');
      fd.append('id', this.dataset.id);
      fd.append('enrollment_id', this.dataset.eid);
      fd.append('term_id', this.dataset.tid);
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });

  // Assessment modal (Add/Edit) - recording appears only in modal, one Save All
  function buildAssessmentScores(scores, maxScore) {
    const tbody = document.getElementById('assessmentScoresBody');
    if (!tbody || !window.assessmentEnrollments) return;
    tbody.innerHTML = window.assessmentEnrollments.map(en => {
      const val = scores[en.enrollment_id] !== undefined && scores[en.enrollment_id] !== '' ? scores[en.enrollment_id] : '';
      return '<tr><td>' + (en.full_name || '') + ' <span class="text-muted small">' + (en.student_number || '') + '</span></td>' +
        '<td><input type="number" step="0.01" min="0" max="' + maxScore + '" class="form-control form-control-sm score-input" data-enrollment-id="' + en.enrollment_id + '" placeholder="0" value="' + (val !== '' ? val : '') + '"></td></tr>';
    }).join('');
  }

  document.addEventListener('click', function(e) {
    if (e.target.closest('#btn-add-activity') && typeof window.assessmentEnrollments !== 'undefined' && document.getElementById('modalAssessmentActivity')) {
      document.getElementById('modalAssessmentActivityTitle').textContent = 'Add Assessment';
      document.getElementById('formActivityAction').value = 'add_activity';
      document.getElementById('formActivityId').value = '';
      document.getElementById('formInstructorSubjectId').value = e.target.closest('#btn-add-activity').dataset.instructorSubjectId || '';
      document.getElementById('formTermId').value = e.target.closest('#btn-add-activity').dataset.termId || '';
      document.getElementById('formActivityTitle').value = '';
      document.getElementById('formRecordType').value = 'quiz';
      document.getElementById('formMaxScore').value = '100';
      document.getElementById('formActivityDate').value = new Date().toISOString().slice(0, 10);
      document.getElementById('formActivityTime').value = getCurrentTimeHM();
      buildAssessmentScores({}, 100);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAssessmentActivity')).show();
    }
    if (e.target.closest('.edit-activity-btn') && typeof window.assessmentEnrollments !== 'undefined' && document.getElementById('modalAssessmentActivity')) {
      const btn = e.target.closest('.edit-activity-btn');
      document.getElementById('modalAssessmentActivityTitle').textContent = 'Edit Assessment';
      document.getElementById('formActivityAction').value = 'update_activity';
      document.getElementById('formActivityId').value = btn.dataset.activityId || '';
      document.getElementById('formActivityTitle').value = btn.dataset.title || '';
      document.getElementById('formRecordType').value = btn.dataset.recordType || 'quiz'; 
      document.getElementById('formMaxScore').value = btn.dataset.maxScore || '100';
      document.getElementById('formActivityDate').value = btn.dataset.activityDate || '';
      document.getElementById('formActivityTime').value = btn.dataset.activityTime || '';
      const scores = JSON.parse(btn.dataset.scores || '{}');
      buildAssessmentScores(scores, parseFloat(btn.dataset.maxScore) || 100);
      bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAssessmentActivity')).show();
    }
    if (e.target.closest('#btnSaveAssessmentAll') && typeof window.assessmentEnrollments !== 'undefined' && document.getElementById('formAssessmentActivity')) {
      const maxScoreInput = document.getElementById('formMaxScore');
      const maxScore = maxScoreInput ? maxScoreInput.value : '0';
      const scores = {};
      let hasError = false;
      document.querySelectorAll('#assessmentScoresBody .score-input').forEach(inp => {
        const val = inp.value;
        if (val !== '' && !validateScoreAndMax(val, maxScore)) {
          if (!hasError) {
            inp.focus();
            inp.select && inp.select();
          }
          hasError = true;
        }
        scores[inp.dataset.enrollmentId] = val;
      });
      if (hasError) return;
      const fd = new FormData(document.getElementById('formAssessmentActivity'));
      fd.set('action', 'save_assessment_all');
      const adEl = document.getElementById('formActivityDate');
      if (adEl && adEl.value && !recordDateNotFutureYmd(adEl.value)) {
        alert('You cannot record assessments for a future date. Choose today or a past date.');
        return;
      }
      fd.append('activity_id', document.getElementById('formActivityId').value);
      fd.append('instructor_subject_id', document.getElementById('formInstructorSubjectId').value);
      fd.append('term_id', document.getElementById('formTermId').value);
      fd.append('scores', JSON.stringify(scores));
      appendClassSection(fd);
      post(fd, r => {
        if (r.ok) {
          bootstrap.Modal.getInstance(document.getElementById('modalAssessmentActivity')).hide();
          location.reload();
        } else alert(r.message || 'Error');
      });
    }
  });

  document.querySelectorAll('.delete-activity-btn').forEach(btn => {
    btn.addEventListener('click', function() {
      if (!confirm('Delete this assessment record?')) return;
      const fd = new FormData();
      fd.append('action', 'delete_activity');
      fd.append('activity_id', this.dataset.activityId);
      post(fd, r => { if (r.ok) location.reload(); else alert(r.message || 'Error'); });
    });
  });
})();
