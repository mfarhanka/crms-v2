<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Car <span class="text-danger">*</span></label>
        <select class="form-select" name="car_id" required>
            <option value="">Select car</option>
            <?php foreach ($car_rows as $car_option): ?>
                <option value="<?php echo e($car_option['id']); ?>" <?php echo intval($maintenance_form['car_id'] ?? 0) === intval($car_option['id']) ? 'selected' : ''; ?>>
                    <?php echo e($car_option['plate_number'] . ' - ' . $car_option['brand'] . ' ' . $car_option['model']); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Record Date <span class="text-danger">*</span></label>
        <input type="date" class="form-control" name="service_date" value="<?php echo e($maintenance_form['service_date'] ?? date('Y-m-d')); ?>" required>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Category <span class="text-danger">*</span></label>
        <select class="form-select" name="expense_category" required>
            <?php foreach (maintenanceCategoryOptions() as $category_key => $category_label): ?>
                <option value="<?php echo e($category_key); ?>" <?php echo ($maintenance_form['expense_category'] ?? 'maintenance') === $category_key ? 'selected' : ''; ?>>
                    <?php echo e($category_label); ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Workshop / Vendor / Bank</label>
        <input type="text" class="form-control" name="vendor" value="<?php echo e($maintenance_form['vendor'] ?? ''); ?>">
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Description <span class="text-danger">*</span></label>
        <input type="text" class="form-control" name="service_type" value="<?php echo e($maintenance_form['service_type'] ?? ''); ?>" placeholder="Car wash, new wiper, bank installment" required>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Amount (RM) <span class="text-danger">*</span></label>
        <input type="number" class="form-control" name="cost" min="0" step="0.01" value="<?php echo e($maintenance_form['cost'] ?? ''); ?>" required>
    </div>
</div>

<div class="row">
    <div class="col-md-6 mb-3">
        <label class="form-label">Status</label>
        <select class="form-select" name="status">
            <option value="sent" <?php echo ($maintenance_form['status'] ?? 'sent') === 'sent' ? 'selected' : ''; ?>>Sent</option>
            <option value="in_progress" <?php echo ($maintenance_form['status'] ?? '') === 'in_progress' ? 'selected' : ''; ?>>In Progress</option>
            <option value="completed" <?php echo ($maintenance_form['status'] ?? '') === 'completed' ? 'selected' : ''; ?>>Completed / Paid</option>
        </select>
    </div>
    <div class="col-md-6 mb-3">
        <label class="form-label">Receipt / Proof</label>
        <input type="file" class="form-control" name="receipt_photo" accept="image/*,.pdf">
        <?php if (!empty($maintenance_form['receipt_photo'])): ?>
        <div class="form-text">Leave empty to keep the existing receipt.</div>
        <?php endif; ?>
    </div>
</div>

<div class="mb-3">
    <label class="form-label">Notes</label>
    <textarea class="form-control" name="notes" rows="3"><?php echo e($maintenance_form['notes'] ?? ''); ?></textarea>
</div>
