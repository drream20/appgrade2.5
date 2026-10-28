div class="card">
      <div class="card-header bg-white">
        <strong><i class="bi bi-clock-history me-2"></i>Recent Activity</strong>
      </div>
      <div class="card-body p-0">
        <?php if (empty($logs)): ?>
          <p class="text-muted p-3 mb-0">No recent activity found for this account.</p>
        <?php else: ?>
          <div class="table-responsive">
            <table class="table table-sm table-hover mb-0">
              <thead class="table-light">
                <tr>
                  <th>Time</th>
                  <th>Action</th>
                  <th>Details</th>
                  <th>IP</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($logs as $log): ?>
                  <tr>
                    <td><?= htmlspecialchars(date('Y-m-d H:i:s', strtotime($log['created_at']))) ?></td>
                    <td><?= htmlspecialchars($log['action']) ?></td>
                    <td><?= htmlspecialchars($log['details'] ?? '-') ?></td>
                    <td><?= htmlspecialchars($log['ip_address'] ?? '-') ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php
$content = ob_get_clean();
require __DIR__ . '/includes/layout.php';

